<?php
// Reglas de jornada (Estatuto de los Trabajadores por defecto; el convenio puede cambiarlas) y su comprobación.
// INDA las comprueba con cálculos fijos: la IA nunca decide si algo cumple.

const REGLAS_ET = [
    'descanso_entre_jornadas_h' => 12,  // art. 34.3
    'descanso_semanal_h' => 36,         // art. 37.1 (acumulable en 14 días)
    'jornada_max_diaria_h' => 9,        // art. 34.3 (salvo convenio)
    'horas_semana_media' => 40,         // art. 34.1, promedio anual
    'horas_extra_max' => 80,            // art. 35.2
    'pausa_si_mas_de_h' => 6,           // art. 34.4
    'pausa_minutos' => 15,
    'preaviso_vacaciones_dias' => 60,   // art. 38.3
];

const REGLAS_TEXTO = [
    'descanso_entre_jornadas_h' => 'Descanso mínimo entre jornadas (horas)',
    'descanso_semanal_h' => 'Descanso semanal mínimo (horas seguidas)',
    'jornada_max_diaria_h' => 'Jornada ordinaria máxima al día (horas)',
    'horas_semana_media' => 'Horas semanales de media',
    'horas_extra_max' => 'Máximo de horas extra al año',
    'pausa_si_mas_de_h' => 'Pausa obligatoria si la jornada pasa de (horas)',
    'pausa_minutos' => 'Duración mínima de esa pausa (minutos)',
    'preaviso_vacaciones_dias' => 'Antelación para conocer las vacaciones (días)',
];

function reglas(PDO $db): array
{
    $r = json_decode(ajuste($db, 'reglas') ?? '[]', true) ?: [];
    $r = array_intersect_key($r, REGLAS_ET) + REGLAS_ET;
    $r['horas_anuales'] = $db->query('SELECT horas_anuales FROM empresa WHERE id = 1')->fetchColumn() ?: null;
    return $r;
}

function guardar_reglas(PDO $db, array $d): void
{
    $r = [];
    foreach (REGLAS_ET as $k => $def) $r[$k] = is_numeric($d[$k] ?? null) && $d[$k] >= 0 ? $d[$k] + 0 : $def;
    consulta($db, 'REPLACE INTO ajustes VALUES (?, ?)', ['reglas', json_encode($r)]);
}

// Lo que diga el convenio leído por la IA (solo los valores que regula).
function reglas_de_convenio(array $cv): array
{
    return array_filter(['descanso_entre_jornadas_h' => $cv['descanso_entre_jornadas_h'] ?? null, 'horas_extra_max' => $cv['horas_extra_max'] ?? null,
        'pausa_minutos' => $cv['pausa_minutos'] ?? null], fn($v) => $v !== null);
}

function ts_local(string $fecha, string $hora): int
{
    return (new DateTime("$fecha $hora", new DateTimeZone(ZONA)))->getTimestamp();
}

// Turnos planificados con hora fija → [fecha, inicio, fin] (Unix). Los que acaban antes de empezar terminan al día siguiente.
function intervalos_plan(array $cal): array
{
    $out = [];
    foreach ($cal as $f => $c) {
        if (!$c['turno'] || !$c['entrada'] || !$c['salida'] || $c['ausencia'] && $c['ausencia']['minutos'] === null) continue;
        $ini = ts_local($f, $c['entrada']);
        $fin = ts_local($f, $c['salida']);
        if ($fin <= $ini) $fin += 86400;
        $out[] = [$f, $ini, $fin];
    }
    return $out;
}

// Incumplimientos del horario planificado. Cada uno: [fecha, regla, texto, 'bloqueo'|'aviso'].
// bloqueo = INDA no deja guardarlo; aviso = se puede guardar, pero queda señalado.
function comprobar_plan(PDO $db, array $u, string $desde, string $hasta, ?array $r = null): array
{
    $r ??= reglas($db);
    $ini = date('Y-m-d', strtotime("$desde -8 days"));
    $fin = date('Y-m-d', strtotime("$hasta +8 days"));
    $cal = calendario_teorico($db, $u, $ini, $fin);
    $iv = intervalos_plan($cal);
    $mal = [];
    $dentro = fn($f) => $f >= $desde && $f <= $hasta;
    // Descanso entre jornadas.
    for ($i = 1; $i < count($iv); $i++) {
        $hueco = $iv[$i][1] - $iv[$i - 1][2];
        if ($hueco < $r['descanso_entre_jornadas_h'] * 3600 && ($dentro($iv[$i][0]) || $dentro($iv[$i - 1][0]))) {
            $mal[] = [$iv[$i][0], 'descanso_entre_jornadas_h', sprintf('Solo %s h de descanso desde el turno anterior (mínimo %s h).', round(max(0, $hueco) / 3600, 1), $r['descanso_entre_jornadas_h']), 'bloqueo'];
        }
    }
    // Jornada diaria.
    foreach ($cal as $f => $c) {
        if ($dentro($f) && !$c['extra'] && $c['turno'] > $r['jornada_max_diaria_h'] * 60) {
            $mal[] = [$f, 'jornada_max_diaria_h', 'Turno de ' . horas($c['turno'] * 60) . ' h (máximo ' . $r['jornada_max_diaria_h'] . ' h de jornada ordinaria).', 'bloqueo'];
        }
    }
    // Descanso semanal: en cada semana (lunes a domingo) tiene que haber un hueco de 36 h seguidas.
    for ($l = strtotime('monday this week', strtotime($desde)); date('Y-m-d', $l) <= $hasta; $l = strtotime('+7 days', $l)) {
        $a = ts_local(date('Y-m-d', $l), '00:00');
        $b = ts_local(date('Y-m-d', strtotime('+7 days', $l)), '00:00');
        $marcas = [$a];
        foreach ($iv as [$f, $x, $y]) if ($y > $a && $x < $b) array_push($marcas, max($a, $x), min($b, $y));
        $marcas[] = $b;
        $max = 0;
        for ($i = 0; $i + 1 < count($marcas); $i += 2) $max = max($max, $marcas[$i + 1] - $marcas[$i]);
        if (count($marcas) > 2 && $max < $r['descanso_semanal_h'] * 3600) {
            $mal[] = [max($desde, date('Y-m-d', $l)), 'descanso_semanal_h', 'Semana del ' . date('d/m', $l) . ': el descanso más largo es de ' . round($max / 3600) . ' h (mínimo ' . $r['descanso_semanal_h'] . ' h; se puede acumular en 14 días).', 'aviso'];
        }
        // Media semanal (aviso: la ley la mide en el promedio anual).
        $min = 0;
        foreach (rango_fechas(date('Y-m-d', $l), date('Y-m-d', strtotime('+6 days', $l))) as $f) $min += isset($cal[$f]) && !$cal[$f]['extra'] ? $cal[$f]['turno'] : 0;
        if ($min > $r['horas_semana_media'] * 60) {
            $mal[] = [max($desde, date('Y-m-d', $l)), 'horas_semana_media', 'Semana del ' . date('d/m', $l) . ': ' . horas($min * 60) . ' h planificadas (media de ' . $r['horas_semana_media'] . ' h; se compensa en el año).', 'aviso'];
        }
    }
    // Horas anuales del convenio.
    if ($r['horas_anuales']) {
        foreach (array_unique([substr($desde, 0, 4), substr($hasta, 0, 4)]) as $anio) {
            $teo = array_sum(array_column(calendario_teorico($db, $u, "$anio-01-01", "$anio-12-31"), 'minutos'));
            if ($teo > $r['horas_anuales'] * 60) {
                $mal[] = [max($desde, "$anio-01-01"), 'horas_anuales', "$anio: " . horas($teo * 60) . " h previstas, más que las {$r['horas_anuales']} h del convenio. Planifica días libres o vacaciones.", 'aviso'];
            }
        }
    }
    return $mal;
}

// Horas extra del año que cuentan para el tope: hechas (salvo fuerza mayor) + turnos extra ya aceptados en el futuro.
function horas_extra_anio(PDO $db, array $u, int $anio): int
{
    $hoy = local(ahora(), 'Y-m-d');
    $fin = min("$anio-12-31", date('Y-m-d', strtotime("$hoy -1 day")));
    $hechas = 0;
    if ("$anio-01-01" <= $fin) {
        foreach (resumen($db, $u, "$anio-01-01", $fin)['dias'] as $d) if (!$d['fuerza_mayor']) $hechas += $d['extra'];
    }
    $futuras = 0;
    if ($hoy <= "$anio-12-31") {
        foreach (calendario_teorico($db, $u, max($hoy, "$anio-01-01"), "$anio-12-31") as $c) if ($c['extra'] && !$c['fuerza_mayor']) $futuras += $c['turno'] * 60;
    }
    return $hechas + $futuras;
}

// Incumplimientos en lo que de verdad se ha fichado (para informes e Inspección).
function comprobar_real(PDO $db, array $u, string $desde, string $hasta, ?array $r = null): array
{
    $r ??= reglas($db);
    $js = jornadas(fichajes_de($db, (int)$u['id'], a_utc("$desde -2 day"), a_utc("$hasta +1 day")));
    $mal = [];
    $prev = $prev_dia = null;
    foreach ($js as $j) {
        $f = local($j['entrada'], 'Y-m-d');
        $ini = strtotime($j['entrada'] . ' UTC');
        // Dos jornadas que empiezan el mismo día son una jornada partida: el descanso de 12 h se mide entre días.
        if ($prev && $prev_dia !== $f && $f >= $desde && $f <= $hasta && $ini - $prev < $r['descanso_entre_jornadas_h'] * 3600) {
            $mal[] = [$f, 'descanso_entre_jornadas_h', 'Descansó ' . round(($ini - $prev) / 3600, 1) . ' h entre jornadas (mínimo ' . $r['descanso_entre_jornadas_h'] . ' h).', 'aviso'];
        }
        $prev = $j['salida'] ? strtotime($j['salida'] . ' UTC') : null;
        $prev_dia = $f;
        if ($f < $desde || $f > $hasta || !$j['salida']) continue;
        $total = strtotime($j['salida'] . ' UTC') - $ini;
        if ($total > $r['pausa_si_mas_de_h'] * 3600 && $j['pausas'] < $r['pausa_minutos'] * 60) {
            $mal[] = [$f, 'pausa_minutos', 'Jornada de ' . horas($total) . ' h sin la pausa de ' . $r['pausa_minutos'] . ' min.', 'aviso'];
        }
    }
    foreach (array_unique([(int)substr($desde, 0, 4), (int)substr($hasta, 0, 4)]) as $anio) {
        $extra = horas_extra_anio($db, $u, $anio);
        if ($extra > $r['horas_extra_max'] * 3600) $mal[] = ["$anio-12-31", 'horas_extra_max', "$anio: " . horas($extra) . " h extra (máximo {$r['horas_extra_max']} h).", 'aviso'];
    }
    return $mal;
}

function bloqueos(array $mal): array
{
    return array_values(array_filter($mal, fn($m) => $m[3] === 'bloqueo'));
}

// Días que consume una ausencia según el convenio: laborables (según su horario) o naturales.
function dias_computables(PDO $db, array $u, array $tipo, string $desde, string $hasta, ?int $excluir = null): int
{
    if (($tipo['computo'] ?? 'laborables') === 'naturales') return count(rango_fechas($desde, $hasta));
    return dias_laborables($db, $u, $desde, $hasta, $excluir);
}
