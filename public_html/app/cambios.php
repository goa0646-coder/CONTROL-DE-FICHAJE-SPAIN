<?php
// Cambios de turno entre compañeros. Flujo: el trabajador elige compañero y día (y, si quiere, el día que le devuelve)
// → el compañero acepta → aprueba su responsable o administración (con correo) → se intercambian los turnos.
// Antes de pedir y al aprobar se comprueban las reglas del convenio de los dos; si algo no se puede, no se cambia nada.

const ESTADOS_CAMBIO = ['companero' => 'Esperando al compañero', 'aprobacion' => 'Pendiente de aprobar', 'aprobado' => 'Aprobado', 'rechazado' => 'Rechazado', 'cancelado' => 'Cancelado'];

function cambio(PDO $db, int $id): ?array
{
    return consulta($db, 'SELECT * FROM cambios_turno WHERE id = ?', [$id])->fetch() ?: null;
}

// Compañeros con los que se puede cambiar: activos, del mismo puesto o (sin puesto) del mismo departamento.
function companeros_cambio(PDO $db, array $u): array
{
    $pp = puestos_por_persona($db);
    $mios = $pp[$u['id']] ?? [];
    $out = [];
    foreach ($db->query("SELECT * FROM usuarios WHERE activo = 1 AND rol IN ('empleado','responsable') ORDER BY nombre, apellidos") as $o) {
        if ($o['id'] == $u['id']) continue;
        $suyos = $pp[$o['id']] ?? [];
        if ($mios || $suyos ? array_intersect($mios, $suyos) : ($u['departamento_id'] && $u['departamento_id'] == $o['departamento_id'])) $out[(int)$o['id']] = $o;
    }
    return $out;
}

// Lo que tiene cada uno ese día: [horario_id|null, texto].
function turno_del_dia(PDO $db, array $u, string $f): array
{
    $c = calendario_teorico($db, $u, $f, $f)[$f];
    if ($c['ausencia'] && $c['ausencia']['minutos'] === null) throw new DomainException(nombre($u) . ' tiene ' . mb_strtolower($c['ausencia']['tipo']) . ' el ' . date('d/m', strtotime($f)) . '.');
    return $c['turno'] && $c['horario_id'] ? [(int)$c['horario_id'], $c['horario'] . ($c['entrada'] ? ' ' . substr($c['entrada'], 0, 5) . '–' . substr((string)$c['salida'], 0, 5) : '')] : [null, 'Libre'];
}

// Intercambia los turnos de los dos en esas fechas y comprueba el convenio. Sin $guardar lo deshace (solo prueba).
// Devuelve [[fecha, turno de A antes, turno de B antes], …]. Lanza DomainException si algo no se puede.
// Con $voluntario, lo que incumple el convenio no para el cambio: se devuelve en $incumple para que quede constancia.
function aplicar_cambio(PDO $db, array $a, array $b, array $fechas, bool $guardar, bool $voluntario = false, ?string &$incumple = null): array
{
    $propio = !$db->inTransaction();
    if ($propio) $db->beginTransaction();
    try {
        $out = [];
        foreach ($fechas as $f) {
            [$ha, $ta] = turno_del_dia($db, $a, $f);
            [$hb, $tb] = turno_del_dia($db, $b, $f);
            if ($ha === $hb) throw new DomainException('El ' . date('d/m', strtotime($f)) . ' los dos tenéis lo mismo (' . $ta . '): no hay nada que cambiar.');
            consulta($db, 'REPLACE INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, ?, ?)', [$a['id'], $f, $hb]);
            consulta($db, 'REPLACE INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, ?, ?)', [$b['id'], $f, $ha]);
            $out[] = [$f, $ta, $tb];
        }
        $desde = date('Y-m-d', strtotime(min($fechas) . ' -7 days'));
        $hasta = date('Y-m-d', strtotime(max($fechas) . ' +7 days'));
        $mal = [];
        foreach ([$a, $b] as $x) if ($m = bloqueos(comprobar_plan($db, $x, $desde, $hasta))) {
            $mal[] = 'a ' . nombre($x) . ' le quedaría «' . $m[0][2] . '» (' . date('d/m', strtotime($m[0][0])) . ')';
        }
        $incumple = mb_substr(implode('; ', $mal), 0, 500);
        if ($mal && !$voluntario) throw new DomainException('No se puede: ' . $incumple . '. Si los dos lo queréis igualmente, marca «Lo acepto por mi propia voluntad aunque incumpla el convenio».');
        if ($propio) $guardar ? $db->commit() : $db->rollBack();
        return $out;
    } catch (Throwable $e) {
        if ($propio && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// Cómo quedaría el cambio, día a día: [[fecha, mi turno, su turno]] (antes; después se intercambian). Lanza si no se puede.
function previa_cambio(PDO $db, array $u, int $companero, array $fechas): array
{
    $o = companeros_cambio($db, $u)[$companero] ?? throw new DomainException('Elige un compañero de tu mismo puesto.');
    $out = [];
    foreach (array_unique(array_filter($fechas, fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $f))) as $f) {
        $out[] = [$f, turno_del_dia($db, $u, $f)[1], turno_del_dia($db, $o, $f)[1], $o];
    }
    return $out;
}

function fechas_cambio(array $c): array
{
    return array_values(array_unique(array_filter([$c['fecha'], $c['fecha_devuelve'] ?? null])));
}

function pedir_cambio(PDO $db, array $u, int $companero, string $fecha, string $devuelve = '', string $motivo = '', bool $voluntario = false): int
{
    $o = companeros_cambio($db, $u)[$companero] ?? throw new DomainException('Elige un compañero de tu mismo puesto.');
    foreach (array_filter([$fecha, $devuelve]) as $f) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) || !strtotime($f)) throw new DomainException('Revisa las fechas.');
        if ($f < local(ahora(), 'Y-m-d')) throw new DomainException('Solo se pueden cambiar días que aún no han pasado.');
    }
    if ($devuelve === $fecha) $devuelve = '';
    $pend = consulta($db, "SELECT COUNT(*) FROM cambios_turno WHERE estado IN ('companero','aprobacion') AND ? IN (solicitante_id, companero_id) AND (fecha IN (?, ?) OR fecha_devuelve IN (?, ?))",
        [$u['id'], $fecha, $devuelve ?: $fecha, $fecha, $devuelve ?: $fecha])->fetchColumn();
    if ($pend) throw new DomainException('Ya hay un cambio pendiente para ese día.');
    aplicar_cambio($db, $u, $o, array_filter([$fecha, $devuelve]), false, $voluntario, $inc); // prueba: avisa ya si no cumple
    consulta($db, 'INSERT INTO cambios_turno (solicitante_id, companero_id, fecha, fecha_devuelve, motivo, pedido_utc, incumple) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$u['id'], $o['id'], $fecha, $devuelve ?: null, mb_substr(trim($motivo), 0, 500), ahora(), $inc]);
    $id = (int)$db->lastInsertId();
    auditar($db, (int)$u['id'], 'cambio_pedido', "cambio=$id con={$o['id']}" . ($inc !== '' ? " voluntario incumple: $inc" : ''));
    avisar_administracion('INDA: cambio de turno pedido · ' . nombre($u), nombre($u) . ' pide cambiar el turno del ' . date('d/m/Y', strtotime($fecha)) . ' con ' . nombre($o)
        . ($devuelve ? ' (le devuelve el ' . date('d/m/Y', strtotime($devuelve)) . ')' : '') . '. Primero tiene que aceptarlo ' . nombre($o) . "; después lo apruebas tú.\n\n" . rtrim(config()['url'] ?? '', '/') . '/?p=solicitudes');
    notificar($db, (int)$o['id'], "cambio:$id", nombre($u) . ' te propone cambiar el turno del ' . date('d/m', strtotime($fecha)) . ($devuelve ? ' (y te devuelve el ' . date('d/m', strtotime($devuelve)) . ')' : '') . '. Acepta o rechaza en Cambios de turno.', 'cambios');
    return $id;
}

// El compañero acepta (pasa a aprobación, con correo a administración) o rechaza.
function responder_cambio(PDO $db, array $yo, int $id, bool $acepta, bool $voluntario = false): array
{
    $c = cambio($db, $id);
    if (!$c || $c['companero_id'] != $yo['id'] || $c['estado'] !== 'companero') throw new DomainException('Esta propuesta ya no está pendiente.');
    $a = usuario($db, (int)$c['solicitante_id']);
    $inc = $c['incumple'];
    // Si incumple, también el compañero tiene que aceptarlo por su voluntad (y quien lo pidió ya lo hizo al pedirlo).
    if ($acepta) aplicar_cambio($db, $a, $yo, fechas_cambio($c), false, $voluntario, $inc);
    if ($acepta && $inc !== '' && $c['incumple'] === '') throw new DomainException('Ahora este cambio incumple el convenio (' . $inc . ') y quien lo pidió no lo aceptó así: que lo vuelva a pedir.');
    consulta($db, 'UPDATE cambios_turno SET estado = ?, respondido_utc = ?, incumple = ?, voluntario_companero = ? WHERE id = ?',
        [$acepta ? 'aprobacion' : 'rechazado', ahora(), $acepta ? $inc : $c['incumple'], (int)($acepta && $inc !== ''), $id]);
    auditar($db, (int)$yo['id'], $acepta ? 'cambio_aceptado' : 'cambio_rechazado_companero', "cambio=$id" . ($acepta && $inc !== '' ? " voluntario incumple: $inc" : ''));
    $dias = implode(' y ', array_map(fn($f) => date('d/m', strtotime($f)), fechas_cambio($c)));
    if ($acepta) avisar_aprobadores($db, $a, "cambio_ok:$id", 'Cambio de turno para aprobar: ' . nombre($a) . ' y ' . nombre($yo) . " ($dias)." . ($c['motivo'] !== '' ? " Motivo: {$c['motivo']}" : ''), 'equipo');
    else notificar($db, (int)$a['id'], "cambio_no:$id", nombre($yo) . " no acepta el cambio de turno del $dias.", 'cambios');
    return $c;
}

function resolver_cambio(PDO $db, array $yo, int $id, bool $aprobar, string $comentario = ''): array
{
    $c = cambio($db, $id);
    // Administración tiene la última palabra: aprueba cuando ya ha aceptado el compañero; rechazar, en cualquier momento.
    if (!$c || !in_array($c['estado'], $aprobar ? ['aprobacion'] : ['aprobacion', 'companero'], true)) {
        throw new DomainException($c && $c['estado'] === 'companero' ? 'Todavía no lo ha aceptado el compañero: puedes rechazarlo, pero no aprobarlo.' : 'Este cambio ya no está pendiente.');
    }
    $a = usuario($db, (int)$c['solicitante_id']);
    $b = usuario($db, (int)$c['companero_id']);
    if (!puede_aprobar($yo, $a)) throw new DomainException('No puedes aprobar este cambio.');
    $db->beginTransaction();
    try {
        // Solo pasa por encima del convenio si los dos lo aceptaron por su voluntad; la empresa ve el aviso antes de aprobar.
        if ($aprobar) aplicar_cambio($db, $a, $b, fechas_cambio($c), true, $c['incumple'] !== '' && (bool)$c['voluntario_companero']);
        consulta($db, 'UPDATE cambios_turno SET estado = ?, resuelto_por = ?, resuelto_utc = ?, comentario = ? WHERE id = ?',
            [$aprobar ? 'aprobado' : 'rechazado', $yo['id'], ahora(), mb_substr(trim($comentario), 0, 500), $id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    auditar($db, (int)$yo['id'], $aprobar ? 'cambio_aprobado' : 'cambio_rechazado', "cambio=$id" . ($aprobar && $c['incumple'] !== '' ? " voluntario de los dos, incumple: {$c['incumple']}" : ''));
    $dias = implode(' y ', array_map(fn($f) => date('d/m', strtotime($f)), fechas_cambio($c)));
    foreach ([$a, $b] as $x) notificar($db, (int)$x['id'], "cambio_res:$id", "El cambio de turno del $dias ha sido " . ($aprobar ? 'aprobado: ya está en tu calendario.' : 'rechazado.'), 'calendario');
    return $c;
}

function cancelar_cambio(PDO $db, array $yo, int $id): void
{
    $s = consulta($db, "UPDATE cambios_turno SET estado = 'cancelado', resuelto_utc = ? WHERE id = ? AND solicitante_id = ? AND estado IN ('companero','aprobacion')", [ahora(), $id, $yo['id']]);
    if (!$s->rowCount()) throw new DomainException('Ya no se puede cancelar.');
    auditar($db, (int)$yo['id'], 'cambio_cancelado', "cambio=$id");
}

// Cambios donde participa $uid (los últimos 100).
function cambios_de(PDO $db, int $uid): array
{
    return consulta($db, 'SELECT * FROM cambios_turno WHERE ? IN (solicitante_id, companero_id) ORDER BY id DESC LIMIT 100', [$uid])->fetchAll();
}

// Pendientes de aprobar por $yo (con $tambien_companero, también los que esperan al compañero).
function cambios_por_aprobar(PDO $db, array $yo, bool $tambien_companero = false): array
{
    $out = [];
    foreach ($db->query("SELECT * FROM cambios_turno WHERE estado IN ('aprobacion'" . ($tambien_companero ? ",'companero'" : '') . ") ORDER BY fecha") as $c) if (puede_aprobar($yo, usuario($db, (int)$c['solicitante_id']))) $out[] = $c;
    return $out;
}

// Todo lo que piden los trabajadores y espera a $yo: cambios de turno (también los que esperan al compañero), ausencias,
// correcciones de fichaje, justificantes de pausa sin subir y envíos a la empresa sin revisar.
function solicitudes_pendientes(PDO $db, array $yo): array
{
    $equipo = array_column(equipo_de($db, $yo), null, 'id');
    return [
        'cambios' => cambios_por_aprobar($db, $yo, true),
        'ausencias' => ausencias_pendientes($db, $yo),
        'correcciones' => correcciones_pendientes($db, $yo),
        'justificantes' => array_values(array_filter(pausas_sin_justificante($db, null, gmdate('Y-m-d H:i:s', strtotime('-30 days'))), fn($x) => isset($equipo[$x['usuario_id']]) && $x['usuario_id'] != $yo['id'])),
        'envios' => envios_recibidos($db, $yo, true),
    ];
}

// Cuántas esperan una decisión (los justificantes y envíos se cuentan aparte: no se aprueban).
function n_por_decidir(array $s): int
{
    return count(array_filter($s['cambios'], fn($c) => $c['estado'] === 'aprobacion')) + count($s['ausencias']) + count($s['correcciones']);
}

// Cron: recordatorio por correo a administración con lo pendiente, cada N horas (Administración → Correo; 0 = nunca).
function recordar_solicitudes(PDO $db, ?string $ahora = null): bool
{
    $ahora ??= ahora();
    $cada = (int)(ajuste($db, 'recordatorio_horas') ?? 24);
    if ($cada <= 0) return false;
    $ult = ajuste($db, 'recordatorio_ultimo');
    if ($ult && strtotime($ult . ' UTC') > strtotime($ahora . ' UTC') - $cada * 3600) return false;
    $admin = $db->query("SELECT * FROM usuarios WHERE rol = 'admin' AND activo = 1 ORDER BY id LIMIT 1")->fetch();
    if (!$admin) return false;
    $s = solicitudes_pendientes($db, $admin);
    $lineas = [];
    foreach ($s['cambios'] as $c) $lineas[] = '· Cambio de turno: ' . nombre(usuario($db, (int)$c['solicitante_id'])) . ' ⇄ ' . nombre(usuario($db, (int)$c['companero_id'])) . ' (' . date('d/m', strtotime($c['fecha'])) . ') — ' . ESTADOS_CAMBIO[$c['estado']];
    foreach ($s['ausencias'] as $a) $lineas[] = '· Ausencia: ' . nombre($a) . " — {$a['tipo']} del " . date('d/m', strtotime($a['desde'])) . ($a['hasta'] !== $a['desde'] ? ' al ' . date('d/m', strtotime($a['hasta'])) : '');
    foreach ($s['correcciones'] as $c) $lineas[] = '· Corrección de fichaje: ' . nombre($c) . ' — ' . local($c['pedida_utc'], 'd/m H:i');
    foreach ($s['justificantes'] as $j) if ($j['vence_utc'] < $ahora) $lineas[] = '· Justificante sin subir: ' . nombre(usuario($db, (int)$j['usuario_id'])) . " — pausa {$j['motivo']} del " . local($j['momento_utc'], 'd/m');
    foreach ($s['envios'] as $e) $lineas[] = '· Envío a la empresa sin revisar: ' . ($e['asunto'] ?? $e['titulo'] ?? 'documento');
    if (!$lineas) return false;
    consulta($db, 'REPLACE INTO ajustes VALUES (?, ?)', ['recordatorio_ultimo', $ahora]);
    avisar_administracion('INDA: ' . count($lineas) . ' solicitudes pendientes', "Pendiente de revisar en INDA:\n\n" . implode("\n", $lineas) . "\n\n" . rtrim(config()['url'] ?? '', '/') . '/?p=solicitudes');
    return true;
}
