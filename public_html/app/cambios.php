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
function aplicar_cambio(PDO $db, array $a, array $b, array $fechas, bool $guardar): array
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
        foreach ([$a, $b] as $x) if ($m = bloqueos(comprobar_plan($db, $x, $desde, $hasta))) {
            throw new DomainException('No se puede: a ' . nombre($x) . ' le quedaría «' . $m[0][2] . '» (' . date('d/m', strtotime($m[0][0])) . ').');
        }
        if ($propio) $guardar ? $db->commit() : $db->rollBack();
        return $out;
    } catch (Throwable $e) {
        if ($propio && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function fechas_cambio(array $c): array
{
    return array_values(array_unique(array_filter([$c['fecha'], $c['fecha_devuelve'] ?? null])));
}

function pedir_cambio(PDO $db, array $u, int $companero, string $fecha, string $devuelve = '', string $motivo = ''): int
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
    aplicar_cambio($db, $u, $o, array_filter([$fecha, $devuelve]), false); // prueba: avisa ya si no cumple
    consulta($db, 'INSERT INTO cambios_turno (solicitante_id, companero_id, fecha, fecha_devuelve, motivo, pedido_utc) VALUES (?, ?, ?, ?, ?, ?)',
        [$u['id'], $o['id'], $fecha, $devuelve ?: null, mb_substr(trim($motivo), 0, 500), ahora()]);
    $id = (int)$db->lastInsertId();
    auditar($db, (int)$u['id'], 'cambio_pedido', "cambio=$id con={$o['id']}");
    notificar($db, (int)$o['id'], "cambio:$id", nombre($u) . ' te propone cambiar el turno del ' . date('d/m', strtotime($fecha)) . ($devuelve ? ' (y te devuelve el ' . date('d/m', strtotime($devuelve)) . ')' : '') . '. Acepta o rechaza en Cambios de turno.', 'cambios');
    return $id;
}

// El compañero acepta (pasa a aprobación, con correo a administración) o rechaza.
function responder_cambio(PDO $db, array $yo, int $id, bool $acepta): array
{
    $c = cambio($db, $id);
    if (!$c || $c['companero_id'] != $yo['id'] || $c['estado'] !== 'companero') throw new DomainException('Esta propuesta ya no está pendiente.');
    $a = usuario($db, (int)$c['solicitante_id']);
    if ($acepta) aplicar_cambio($db, $a, $yo, fechas_cambio($c), false);
    consulta($db, 'UPDATE cambios_turno SET estado = ?, respondido_utc = ? WHERE id = ?', [$acepta ? 'aprobacion' : 'rechazado', ahora(), $id]);
    auditar($db, (int)$yo['id'], $acepta ? 'cambio_aceptado' : 'cambio_rechazado_companero', "cambio=$id");
    $dias = implode(' y ', array_map(fn($f) => date('d/m', strtotime($f)), fechas_cambio($c)));
    if ($acepta) avisar_aprobadores($db, $a, "cambio_ok:$id", 'Cambio de turno para aprobar: ' . nombre($a) . ' y ' . nombre($yo) . " ($dias)." . ($c['motivo'] !== '' ? " Motivo: {$c['motivo']}" : ''), 'equipo');
    else notificar($db, (int)$a['id'], "cambio_no:$id", nombre($yo) . " no acepta el cambio de turno del $dias.", 'cambios');
    return $c;
}

function resolver_cambio(PDO $db, array $yo, int $id, bool $aprobar, string $comentario = ''): array
{
    $c = cambio($db, $id);
    if (!$c || $c['estado'] !== 'aprobacion') throw new DomainException('Este cambio ya no está pendiente.');
    $a = usuario($db, (int)$c['solicitante_id']);
    $b = usuario($db, (int)$c['companero_id']);
    if (!puede_aprobar($yo, $a)) throw new DomainException('No puedes aprobar este cambio.');
    $db->beginTransaction();
    try {
        if ($aprobar) aplicar_cambio($db, $a, $b, fechas_cambio($c), true);
        consulta($db, 'UPDATE cambios_turno SET estado = ?, resuelto_por = ?, resuelto_utc = ?, comentario = ? WHERE id = ?',
            [$aprobar ? 'aprobado' : 'rechazado', $yo['id'], ahora(), mb_substr(trim($comentario), 0, 500), $id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    auditar($db, (int)$yo['id'], $aprobar ? 'cambio_aprobado' : 'cambio_rechazado', "cambio=$id");
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

// Pendientes de aprobar por $yo.
function cambios_por_aprobar(PDO $db, array $yo): array
{
    $out = [];
    foreach ($db->query("SELECT * FROM cambios_turno WHERE estado = 'aprobacion' ORDER BY fecha") as $c) if (puede_aprobar($yo, usuario($db, (int)$c['solicitante_id']))) $out[] = $c;
    return $out;
}
