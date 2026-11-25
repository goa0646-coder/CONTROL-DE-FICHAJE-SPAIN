<?php
// Preferencias del trabajador (sugerencias, no reglas) y equidad del reparto de turnos.

// Peso de una preferencia en el generador, en la misma escala que el reparto semanal (~ un turno de diferencia): lo bastante para mover días entre candidatos
// parecidos, nunca para saltarse una regla, un ratio o un reparto muy desigual.
const PESO_PREFERENCIA = 300;

function guardar_preferencia(PDO $db, array $u, array $d): int
{
    $tipo = $d['tipo'] ?? '';
    $prefiere = ($d['prefiere'] ?? '1') === '1' ? 1 : 0;
    $hid = $dia = $fecha = null;
    if ($tipo === 'turno' || !empty($d['horario_id'])) {
        $hid = (int)($d['horario_id'] ?? 0);
        $h = horarios($db)[$hid] ?? null;
        if (!$h || !$h['activo']) throw new DomainException('Elige un turno.');
    }
    if ($tipo === 'dia') {
        $dia = (int)($d['dia'] ?? 0);
        if ($dia < 1 || $dia > 7) throw new DomainException('Elige el día de la semana.');
    } elseif ($tipo === 'fecha') {
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['fecha'] ?? '') ? $d['fecha'] : throw new DomainException('Elige la fecha.');
        if ($fecha < local(ahora(), 'Y-m-d')) throw new DomainException('La fecha ya ha pasado.');
    } elseif ($tipo !== 'turno') {
        throw new DomainException('Tipo de preferencia no válido.');
    }
    if ((int)consulta($db, 'SELECT COUNT(*) FROM preferencias WHERE usuario_id = ? AND activa = 1', [$u['id']])->fetchColumn() >= 20) throw new DomainException('Como mucho 20 preferencias.');
    consulta($db, 'INSERT INTO preferencias (usuario_id, tipo, horario_id, dia, fecha, prefiere, comentario, creada_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$u['id'], $tipo, $hid, $dia, $fecha, $prefiere, mb_substr(trim($d['comentario'] ?? ''), 0, 300), ahora()]);
    return (int)$db->lastInsertId();
}

// [uid => [preferencia, …]] activas (las de fecha, solo las que no han pasado).
function preferencias_activas(PDO $db, ?array $uids = null): array
{
    $out = [];
    foreach (consulta($db, 'SELECT * FROM preferencias WHERE activa = 1 AND (fecha IS NULL OR fecha >= ?)', [date('Y-m-d', strtotime(local(ahora(), 'Y-m-d') . ' -400 days'))]) as $p) {
        if ($uids === null || in_array((int)$p['usuario_id'], $uids, true)) $out[(int)$p['usuario_id']][] = $p;
    }
    return $out;
}

// ¿Esta preferencia habla de ese día (y turno)? null = no aplica; true = se cumple si trabaja ese turno; false = si no.
// Devuelve el ajuste de puntuación para asignar $hid el día $f (negativo = mejor).
function ajuste_preferencias(array $prefs, string $f, int $hid): float
{
    $n = (int)date('N', strtotime($f));
    $a = 0;
    foreach ($prefs as $p) {
        $habla_del_dia = match ($p['tipo']) { 'dia' => (int)$p['dia'] === $n, 'fecha' => $p['fecha'] === $f, default => true };
        if (!$habla_del_dia) continue;
        $es_turno = !$p['horario_id'] || (int)$p['horario_id'] === $hid;
        if (!$es_turno) continue;
        $a += $p['prefiere'] ? -PESO_PREFERENCIA : PESO_PREFERENCIA;
    }
    return $a;
}

// Cumplimiento de las preferencias en un plan [uid => [fecha => [horario_id] | null]]: [uid => [cumplidas, total]].
function cumplimiento_preferencias(array $plan, array $prefs): array
{
    $out = [];
    foreach ($plan as $uid => $dias) {
        $c = $t = 0;
        foreach ($prefs[$uid] ?? [] as $p) foreach ($dias as $f => $x) {
            $habla = match ($p['tipo']) { 'dia' => (int)$p['dia'] === (int)date('N', strtotime($f)), 'fecha' => $p['fecha'] === $f, default => true };
            if (!$habla) continue;
            // Turno preferido: cuenta cada día que lo trabaja (o no) solo si ese día trabaja; día/fecha sin turno: trabajar o librar.
            if ($p['horario_id']) {
                if (!$x && $p['tipo'] === 'turno') continue;
                $cumple = ($x && (int)$x['horario_id'] === (int)$p['horario_id']) === (bool)$p['prefiere'];
            } else {
                $cumple = (bool)$x === (bool)$p['prefiere'];
            }
            $t++;
            $c += $cumple ? 1 : 0;
        }
        if ($t) $out[$uid] = [$c, $t];
    }
    return $out;
}

function texto_preferencia(array $p, array $hors): string
{
    $turno = $p['horario_id'] ? ($hors[$p['horario_id']]['nombre'] ?? 'turno') : null;
    $cuando = match ($p['tipo']) { 'dia' => ' los ' . preg_replace('/(?<!s)$/u', 's', mb_strtolower(DIAS[(int)$p['dia']])), 'fecha' => ' el ' . date('d/m/Y', strtotime($p['fecha'])), default => '' };
    if ($p['tipo'] === 'turno') return ($p['prefiere'] ? 'Prefiere el turno ' : 'Prefiere no hacer el turno ') . $turno;
    if ($turno) return ($p['prefiere'] ? 'Prefiere ' : 'Prefiere no hacer ') . $turno . $cuando;
    return ($p['prefiere'] ? 'Prefiere trabajar' : 'Prefiere librar') . $cuando;
}

// Equidad: por persona, turnos, horas, noches, fines de semana y festivos planificados, y la media de su grupo (mismo puesto).
function equidad(PDO $db, array $personas, string $desde, string $hasta): array
{
    $pp = puestos_por_persona($db);
    $pn = puestos($db);
    $filas = [];
    foreach ($personas as $p) {
        $x = ['persona' => $p, 'grupo' => implode(', ', array_intersect_key($pn, array_flip($pp[$p['id']] ?? []))) ?: 'Sin puesto', 'turnos' => 0, 'min' => 0, 'noches' => 0, 'findes' => 0, 'festivos' => 0];
        foreach (calendario_teorico($db, $p, $desde, $hasta) as $f => $c) {
            if (!$c['turno']) continue;
            $x['turnos']++;
            $x['min'] += $c['turno'];
            if ($c['entrada'] && ((int)substr($c['entrada'], 0, 2) >= 20 || $c['salida'] && $c['salida'] < $c['entrada'])) $x['noches']++;
            if ((int)date('N', strtotime($f)) >= 6) $x['findes']++;
            if ($c['festivo']) $x['festivos']++;
        }
        if ($x['turnos']) $filas[] = $x;
    }
    $medias = [];
    foreach ($filas as $x) foreach (['turnos', 'min', 'noches', 'findes', 'festivos'] as $k) $medias[$x['grupo']][$k][] = $x[$k];
    foreach ($medias as &$m) foreach ($m as &$v) $v = array_sum($v) / count($v);
    unset($m, $v);
    return [$filas, $medias];
}
