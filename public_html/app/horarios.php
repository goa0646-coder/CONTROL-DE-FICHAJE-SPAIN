<?php
// Horario teórico de cada día, horas trabajadas, extra, nocturnas y bolsa de horas. Fechas locales (Y-m-d).

const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

function horarios(PDO $db): array
{
    $h = [];
    foreach ($db->query('SELECT * FROM horarios ORDER BY nombre') as $x) $h[$x['id']] = $x + ['dias' => []];
    foreach ($db->query('SELECT * FROM horario_dias') as $d) $h[$d['horario_id']]['dias'][$d['dia']] = $d;
    return $h;
}

function rango_fechas(string $desde, string $hasta): array
{
    $out = [];
    for ($d = new DateTime($desde); $d->format('Y-m-d') <= $hasta; $d->modify('+1 day')) $out[] = $d->format('Y-m-d');
    return $out;
}

// Por fecha: horario que toca, minutos teóricos, festivo y ausencia. Orden: planificación > festivo > horario de la persona.
function calendario_teorico(PDO $db, array $u, string $desde, string $hasta): array
{
    $h = horarios($db);
    $plan = consulta($db, 'SELECT fecha, horario_id FROM planificacion WHERE usuario_id = ? AND fecha BETWEEN ? AND ?', [$u['id'], $desde, $hasta])->fetchAll(PDO::FETCH_KEY_PAIR);
    $fest = consulta($db, 'SELECT fecha, nombre FROM festivos WHERE (centro_id IS NULL OR centro_id = ?) AND fecha BETWEEN ? AND ?', [$u['centro_id'], $desde, $hasta])->fetchAll(PDO::FETCH_KEY_PAIR);
    $aus = ausencias_por_dia($db, (int)$u['id'], $desde, $hasta);
    $alta = local($u['alta_utc'], 'Y-m-d');
    $baja = $u['baja_utc'] ? local($u['baja_utc'], 'Y-m-d') : '9999-12-31';
    $out = [];
    foreach (rango_fechas($desde, $hasta) as $f) {
        $hid = array_key_exists($f, $plan) ? $plan[$f] : (isset($fest[$f]) ? null : $u['horario_id']);
        $dia = $hid ? ($h[$hid]['dias'][(int)date('N', strtotime($f))] ?? null) : null;
        $min = ($dia && $f >= $alta && $f <= $baja) ? (int)$dia['minutos'] : 0;
        $a = $aus[$f] ?? null;
        $min = $a ? ($a['minutos'] === null ? 0 : max(0, $min - $a['minutos'])) : $min;
        $out[$f] = [
            'horario' => $hid ? $h[$hid]['nombre'] : null, 'minutos' => $min,
            'entrada' => $dia['entrada'] ?? null, 'salida' => $dia['salida'] ?? null,
            'flexible' => $hid ? (bool)$h[$hid]['flexible'] : true, 'margen' => $hid ? (int)$h[$hid]['margen_min'] : 0,
            'festivo' => $fest[$f] ?? null, 'ausencia' => $a,
            'laborable' => $dia !== null || (!$hid && !$u['horario_id'] && !isset($fest[$f]) && date('N', strtotime($f)) < 6),
        ];
    }
    return $out;
}

// Segundos de los tramos que caen entre las 22:00 y las 6:00 (hora de Madrid), art. 36 ET.
function segundos_nocturnos(array $tramos): int
{
    $n = 0;
    $z = new DateTimeZone(ZONA);
    foreach ($tramos as [$a, $b]) {
        $d = (new DateTime('@' . $a))->setTimezone($z)->modify('-1 day')->setTime(22, 0);
        while ($d->getTimestamp() < $b) {
            $ini = $d->getTimestamp();
            $fin = (clone $d)->modify('+1 day')->setTime(6, 0)->getTimestamp();
            $n += max(0, min($b, $fin) - max($a, $ini));
            $d->modify('+1 day');
        }
    }
    return $n;
}

// Resumen por día y total. Las diferencias (bolsa) solo cuentan días ya pasados.
function resumen(PDO $db, array $u, string $desde, string $hasta): array
{
    $cal = calendario_teorico($db, $u, $desde, $hasta);
    $hoy = local(ahora(), 'Y-m-d');
    $dias = [];
    foreach ($cal as $f => $c) $dias[$f] = $c + ['trabajado' => 0, 'nocturno' => 0, 'jornadas' => []];
    $todos = fichajes_de($db, (int)$u['id'], a_utc("$desde -1 day"), a_utc("$hasta +2 day"));
    foreach (jornadas($todos) as $j) {
        $f = local($j['entrada'], 'Y-m-d');
        if (!isset($dias[$f])) continue;
        $dias[$f]['trabajado'] += $j['trabajado'];
        $dias[$f]['nocturno'] += segundos_nocturnos($j['tramos']);
        $dias[$f]['jornadas'][] = $j;
    }
    $t = ['teorico' => 0, 'trabajado' => 0, 'extra' => 0, 'nocturno' => 0, 'festivo' => 0, 'diferencia' => 0];
    foreach ($dias as $f => &$d) {
        $teo = $d['minutos'] * 60;
        $d['extra'] = max(0, $d['trabajado'] - $teo);
        $d['diferencia'] = $f < $hoy ? $d['trabajado'] - $teo : 0;
        $t['teorico'] += $teo;
        $t['trabajado'] += $d['trabajado'];
        $t['extra'] += $d['extra'];
        $t['nocturno'] += $d['nocturno'];
        if ($d['festivo'] || !$d['laborable']) $t['festivo'] += $d['trabajado'];
        $t['diferencia'] += $d['diferencia'];
    }
    return ['dias' => $dias, 'total' => $t];
}

// Bolsa de horas en segundos: días pasados desde el alta + ajustes manuales.
function saldo_bolsa(PDO $db, array $u): int
{
    $ayer = local(gmdate('Y-m-d H:i:s', time() - 86400), 'Y-m-d');
    $alta = local($u['alta_utc'], 'Y-m-d');
    $dif = $alta <= $ayer ? resumen($db, $u, $alta, $ayer)['total']['diferencia'] : 0;
    return $dif + 60 * (int)consulta($db, 'SELECT COALESCE(SUM(minutos), 0) FROM ajustes_saldo WHERE usuario_id = ?', [$u['id']])->fetchColumn();
}

// Signo incluido: -1:30, +2:05.
function horas_signo(int $s): string
{
    return ($s < 0 ? '-' : '+') . horas(abs($s));
}
