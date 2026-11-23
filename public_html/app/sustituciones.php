<?php
// Cubrir huecos (bajas, vacaciones): candidatos que cumplen el convenio, ofertas de horas extra
// que la persona acepta con un código de un solo uso, y movimientos de turno.

// Prueba un cambio en la planificación sin guardarlo: devuelve los incumplimientos que causaría.
function simular(PDO $db, array $u, string $fecha, ?int $horario, ?int $puesto, int $extra, int $fm = 0): array
{
    $db->beginTransaction();
    try {
        consulta($db, 'REPLACE INTO planificacion (usuario_id, fecha, horario_id, puesto_id, extra, fuerza_mayor) VALUES (?, ?, ?, ?, ?, ?)', [$u['id'], $fecha, $horario, $puesto, $extra, $fm]);
        $mal = comprobar_plan($db, $u, date('Y-m-d', strtotime("$fecha -1 day")), date('Y-m-d', strtotime("$fecha +1 day")));
        $extra_h = $extra && !$fm ? horas_extra_anio($db, $u, (int)substr($fecha, 0, 4)) : 0;
    } finally {
        $db->rollBack();
    }
    $r = reglas($db);
    if ($extra_h > $r['horas_extra_max'] * 3600) $mal[] = [$fecha, 'horas_extra_max', 'Pasaría a ' . horas($extra_h) . " h extra este año (máximo {$r['horas_extra_max']} h).", 'bloqueo'];
    return $mal;
}

// Para un hueco: quién lo puede cubrir. tipo 'extra' (su día libre, horas extra voluntarias) o 'mover' (le sobra en su turno de ese día).
// Orden: primero los que cumplen; entre ellos, quien menos horas extra lleva y, a igualdad, más antigüedad (art. 18 del convenio).
function candidatos(PDO $db, array $personas, string $fecha, int $horario, ?int $puesto): array
{
    $pp = puestos_por_persona($db);
    $dia = [];
    foreach ($personas as $u) {
        $c = calendario_teorico($db, $u, $fecha, $fecha)[$fecha];
        if ($c['horario_id'] && $c['turno'] > 0) $dia[$u['id']] = ['horario_id' => $c['horario_id'], 'puesto_id' => $c['puesto_id']];
    }
    $usados = [];
    foreach (cubrir_dia(necesidades_dia($db, $fecha), $dia, $pp) as [, , , $a]) $usados = array_merge($usados, $a);
    $out = [];
    foreach ($personas as $u) {
        $uid = (int)$u['id'];
        if ($puesto && !in_array($puesto, $pp[$uid] ?? [], true)) continue;
        $c = calendario_teorico($db, $u, $fecha, $fecha)[$fecha];
        if ($c['ausencia'] && $c['ausencia']['minutos'] === null) continue;
        if (isset($dia[$uid]) && ($dia[$uid]['horario_id'] == $horario || in_array($uid, $usados))) continue;
        $tipo = isset($dia[$uid]) ? 'mover' : 'extra';
        $mal = bloqueos(simular($db, $u, $fecha, $horario, $puesto, $tipo === 'extra' ? 1 : 0));
        $out[] = ['persona' => $u, 'tipo' => $tipo, 'mal' => $mal, 'extra' => horas_extra_anio($db, $u, (int)substr($fecha, 0, 4)),
            'actual' => $dia[$uid]['horario_id'] ?? null];
    }
    usort($out, fn($a, $b) => [(bool)$a['mal'], $a['tipo'] === 'extra', $a['extra'], $a['persona']['alta_utc']]
        <=> [(bool)$b['mal'], $b['tipo'] === 'extra', $b['extra'], $b['persona']['alta_utc']]);
    return $out;
}

function ofrecer_turno(PDO $db, array $yo, array $u, string $fecha, int $horario, ?int $puesto, bool $fm, string $motivo): int
{
    if ($fecha < local(ahora(), 'Y-m-d')) throw new DomainException('Esa fecha ya ha pasado.');
    if ($b = bloqueos(simular($db, $u, $fecha, $horario, $puesto, 1, (int)$fm))) throw new DomainException('No se puede ofrecer: ' . $b[0][2]);
    consulta($db, 'INSERT INTO ofertas_turno (usuario_id, fecha, horario_id, puesto_id, motivo, fuerza_mayor, creado_por, creado_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$u['id'], $fecha, $horario, $puesto, mb_substr($motivo, 0, 200), (int)$fm, $yo['id'], ahora()]);
    $id = (int)$db->lastInsertId();
    $h = horarios($db)[$horario];
    notificar($db, (int)$u['id'], "oferta:$id", 'Turno disponible: ' . $h['nombre'] . ' el ' . date('d/m', strtotime($fecha)) . ' (horas extra' . ($fm ? ' por fuerza mayor' : ', voluntario') . '). ¿Puedes cubrirlo?', 'ofertas');
    return $id;
}

// Texto exacto que la persona acepta (su hash queda guardado como prueba).
function texto_oferta(PDO $db, array $o): string
{
    $h = horarios($db)[$o['horario_id']];
    $d = $h['dias'][(int)date('N', strtotime($o['fecha']))] ?? ($h['dias'] ? reset($h['dias']) : null);
    return "Acepto de forma voluntaria trabajar el turno «{$h['nombre']}» el " . date('d/m/Y', strtotime($o['fecha']))
        . ($d && $d['entrada'] ? ' de ' . substr($d['entrada'], 0, 5) . ' a ' . substr($d['salida'], 0, 5) : '')
        . ' como horas extraordinarias' . ($o['fuerza_mayor'] ? ' por fuerza mayor (art. 35.3 ET)' : '') . ". Oferta nº {$o['id']}.";
}

function aceptar_oferta(PDO $db, array $u, int $id, string $metodo): void
{
    $o = consulta($db, "SELECT * FROM ofertas_turno WHERE id = ? AND usuario_id = ? AND estado = 'pendiente'", [$id, $u['id']])->fetch();
    if (!$o) throw new DomainException('La oferta ya no está disponible.');
    if ($b = bloqueos(simular($db, $u, $o['fecha'], (int)$o['horario_id'], $o['puesto_id'] ? (int)$o['puesto_id'] : null, 1, (int)$o['fuerza_mayor']))) {
        throw new DomainException('Ya no se puede aceptar: ' . $b[0][2]);
    }
    $db->beginTransaction();
    consulta($db, "UPDATE ofertas_turno SET estado = 'aceptada', respondida_utc = ?, ip = ?, metodo = ?, hash = ? WHERE id = ?",
        [ahora(), ip(), $metodo, hash('sha256', texto_oferta($db, $o)), $id]);
    consulta($db, 'REPLACE INTO planificacion (usuario_id, fecha, horario_id, puesto_id, extra, fuerza_mayor) VALUES (?, ?, ?, ?, 1, ?)',
        [$u['id'], $o['fecha'], $o['horario_id'], $o['puesto_id'], $o['fuerza_mayor']]);
    // El hueco ya está cubierto: las demás ofertas del mismo turno se retiran.
    $otras = consulta($db, "SELECT id, usuario_id FROM ofertas_turno WHERE estado = 'pendiente' AND fecha = ? AND horario_id = ? AND puesto_id <=> ? AND id <> ?",
        [$o['fecha'], $o['horario_id'], $o['puesto_id'], $id])->fetchAll();
    foreach ($otras as $x) consulta($db, "UPDATE ofertas_turno SET estado = 'anulada' WHERE id = ?", [$x['id']]);
    $db->commit();
    foreach ($otras as $x) notificar($db, (int)$x['usuario_id'], "oferta_anulada:{$x['id']}", 'El turno del ' . date('d/m', strtotime($o['fecha'])) . ' ya está cubierto. Gracias.', 'ofertas');
    notificar($db, (int)$o['creado_por'], "oferta_aceptada:$id", nombre($u) . ' cubre el turno del ' . date('d/m', strtotime($o['fecha'])) . '.', 'planificacion');
}

// Cambio de turno decidido por la empresa (sin horas extra). Avisa con la antelación del art. 34.2 ET (5 días).
function mover_turno(PDO $db, array $yo, array $u, string $fecha, int $horario, ?int $puesto): ?string
{
    if ($b = bloqueos(simular($db, $u, $fecha, $horario, $puesto, 0))) throw new DomainException('No se puede: ' . $b[0][2]);
    consulta($db, 'REPLACE INTO planificacion (usuario_id, fecha, horario_id, puesto_id) VALUES (?, ?, ?, ?)', [$u['id'], $fecha, $horario, $puesto]);
    notificar($db, (int)$u['id'], "mover:$fecha:" . ahora(), 'Cambio de turno: el ' . date('d/m', strtotime($fecha)) . ' pasas a ' . horarios($db)[$horario]['nombre'] . '.', 'calendario');
    $dias = (strtotime($fecha) - strtotime(local(ahora(), 'Y-m-d'))) / 86400;
    return $dias < 5 ? 'Aviso: menos de 5 días de antelación (art. 34.2 ET). Si no es una emergencia, conviene que la persona esté de acuerdo.' : null;
}

// --- Códigos de un solo uso (6 cifras, 10 minutos, 5 intentos) ---

function enviar_codigo(PDO $db, array $u, string $proposito): bool
{
    $c = (string)random_int(100000, 999999);
    consulta($db, 'UPDATE codigos SET usado = 1 WHERE usuario_id = ? AND proposito = ?', [$u['id'], $proposito]);
    consulta($db, 'INSERT INTO codigos (usuario_id, proposito, hash, expira_utc) VALUES (?, ?, ?, ?)', [$u['id'], $proposito, hash('sha256', $c), gmdate('Y-m-d H:i:s', time() + 600)]);
    return enviar_correo($u['email'], "INDA: tu código es $c", "Tu código para confirmar en INDA es: $c\n\nCaduca en 10 minutos. Si no lo has pedido tú, ignora este correo.");
}

function comprobar_codigo(PDO $db, array $u, string $proposito, string $codigo): bool
{
    $x = consulta($db, 'SELECT * FROM codigos WHERE usuario_id = ? AND proposito = ? AND usado = 0 AND expira_utc > ? ORDER BY id DESC LIMIT 1', [$u['id'], $proposito, ahora()])->fetch();
    if (!$x || $x['intentos'] >= 5) return false;
    if (!hash_equals($x['hash'], hash('sha256', trim($codigo)))) {
        consulta($db, 'UPDATE codigos SET intentos = intentos + 1 WHERE id = ?', [$x['id']]);
        return false;
    }
    consulta($db, 'UPDATE codigos SET usado = 1 WHERE id = ?', [$x['id']]);
    return true;
}
