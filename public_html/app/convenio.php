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
    'noche_desde_h' => 22,              // art. 36.1
    'noche_hasta_h' => 6,
    'domingo_cada_semanas' => 0,        // 0 = no se exige; convenio: p. ej. 4
    'jornada_partida_pausa_h' => 0,     // pausa mínima en jornada partida (0 = no se exige)
    'superar_jornada_con_acuerdo' => 0, // 1 = superar la jornada diaria es posible con acuerdo (aviso, no bloqueo)
    'retrasos_leve' => 0,               // baremo de puntualidad al mes (0 = no se muestra)
    'retrasos_grave' => 0,
    'retrasos_muy_grave' => 0,
    'festivo_compensa_dias' => 0,       // días de descanso por festivo trabajado (p. ej. 1,3)
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
    'noche_desde_h' => 'Horario nocturno desde (hora)',
    'noche_hasta_h' => 'Horario nocturno hasta (hora)',
    'domingo_cada_semanas' => 'Descanso en domingo al menos cada (semanas, 0 = no)',
    'jornada_partida_pausa_h' => 'Pausa mínima en jornada partida (horas, 0 = no)',
    'superar_jornada_con_acuerdo' => 'Superar la jornada diaria con acuerdo (1 = sí)',
    'retrasos_leve' => 'Retrasos al mes: falta leve desde',
    'retrasos_grave' => 'Retrasos al mes: falta grave desde',
    'retrasos_muy_grave' => 'Retrasos al mes: falta muy grave desde',
    'festivo_compensa_dias' => 'Días de descanso por festivo trabajado',
];

// VI Convenio colectivo de residencias privadas de personas mayores de Galicia (datos facilitados por la empresa).
const PLANTILLA_GALICIA_RESIDENCIAS = [
    'reglas' => ['descanso_entre_jornadas_h' => 12, 'descanso_semanal_h' => 36, 'jornada_max_diaria_h' => 9, 'horas_semana_media' => 40,
        'horas_extra_max' => 60, 'pausa_si_mas_de_h' => 6, 'pausa_minutos' => 20, 'preaviso_vacaciones_dias' => 60, 'noche_desde_h' => 22,
        'noche_hasta_h' => 7, 'domingo_cada_semanas' => 4, 'jornada_partida_pausa_h' => 1, 'superar_jornada_con_acuerdo' => 1,
        'retrasos_leve' => 3, 'retrasos_grave' => 6, 'retrasos_muy_grave' => 11, 'festivo_compensa_dias' => 1.3],
    'horas_anuales' => 1762,
    'pausas' => [['Bocadillo', 1]], // 20 min en jornada continua, tiempo de trabajo efectivo
    // [nombre, por_horas, retribuida, días/año, justificante, color, cómputo]
    'tipos' => [
        ['Vacaciones', 0, 1, 26, 0, '#16a34a', 'laborables'],
        ['Libre disposición', 0, 1, 6, 0, '#9333ea', 'laborables'],
        ['Lactancia', 1, 1, null, 1, '#db2777', 'laborables'],
        ['Asamblea', 1, 1, null, 0, '#0d9488', 'laborables'],
        ['Crédito sindical', 1, 1, null, 0, '#4f46e5', 'laborables'],
    ],
];

// Permisos del Estatuto de los Trabajadores (art. 37.3, 37.9, 48 y 48 bis). [nombre, por_horas, retribuida, días/año, justificante, color, cómputo].
// Los días de cada permiso van en el nombre: se conceden por cada hecho causante, no son un saldo anual.
const PERMISOS_ET = [
    ['Matrimonio o pareja de hecho (15 días)', 0, 1, null, 1, '#ec4899', 'naturales'],
    ['Accidente, enfermedad grave u hospitalización de familiar (5 días)', 0, 1, null, 1, '#f97316', 'laborables'],
    ['Fallecimiento de familiar (2 días, 4 con desplazamiento)', 0, 1, null, 1, '#475569', 'laborables'],
    ['Traslado de domicilio (1 día)', 0, 1, null, 1, '#0ea5e9', 'laborables'],
    ['Deber inexcusable público o personal', 1, 1, null, 1, '#64748b', 'laborables'],
    ['Fuerza mayor familiar urgente (por horas, 4 días al año)', 1, 1, null, 1, '#eab308', 'laborables'],
    ['Exámenes prenatales y preparación al parto', 1, 1, null, 1, '#d946ef', 'laborables'],
    ['Exámenes oficiales', 0, 1, null, 1, '#06b6d4', 'laborables'],
    ['Nacimiento y cuidado de menor (suspensión)', 0, 0, null, 1, '#14b8a6', 'naturales'],
    ['Permiso parental (no retribuido, hasta 8 semanas)', 0, 0, null, 0, '#a855f7', 'naturales'],
    ['Accidente de trabajo (baja)', 0, 0, null, 1, '#b91c1c', 'naturales'],
];

// Crea los tipos que no existan (por nombre). Devuelve cuántos ha creado.
function cargar_tipos_ausencia(PDO $db, array $tipos): int
{
    $n = 0;
    foreach ($tipos as [$nom, $h, $r, $d, $j, $c, $cmp]) {
        if (consulta($db, 'SELECT 1 FROM tipos_ausencia WHERE nombre = ?', [$nom])->fetchColumn()) continue;
        consulta($db, 'INSERT INTO tipos_ausencia (nombre, por_horas, retribuida, dias_anuales, justificante, color, computo) VALUES (?, ?, ?, ?, ?, ?, ?)', [$nom, $h, $r, $d, $j, $c, $cmp]);
        $n++;
    }
    return $n;
}

function aplicar_plantilla(PDO $db, array $pl): void
{
    guardar_reglas($db, $pl['reglas']);
    consulta($db, 'UPDATE empresa SET horas_anuales = ? WHERE id = 1', [$pl['horas_anuales']]);
    foreach ($pl['pausas'] ?? [] as [$n, $ret]) {
        $id = consulta($db, 'SELECT id FROM motivos_pausa WHERE nombre = ?', [$n])->fetchColumn();
        if ($id) consulta($db, 'UPDATE motivos_pausa SET retribuida = ?, activo = 1 WHERE id = ?', [$ret, $id]);
        else consulta($db, 'INSERT INTO motivos_pausa (nombre, retribuida) VALUES (?, ?)', [$n, $ret]);
    }
    foreach ($pl['tipos'] as [$n, $h, $r, $d, $j, $c, $cmp]) {
        $id = consulta($db, 'SELECT id FROM tipos_ausencia WHERE nombre = ?', [$n])->fetchColumn();
        if ($id) consulta($db, 'UPDATE tipos_ausencia SET por_horas = ?, retribuida = ?, dias_anuales = ?, computo = ?, activo = 1 WHERE id = ?', [$h, $r, $d, $cmp, $id]);
        else consulta($db, 'INSERT INTO tipos_ausencia (nombre, por_horas, retribuida, dias_anuales, justificante, color, computo) VALUES (?, ?, ?, ?, ?, ?, ?)', [$n, $h, $r, $d, $j, $c, $cmp]);
    }
}

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
            $mal[] = $r['superar_jornada_con_acuerdo']
                ? [$f, 'jornada_max_diaria_h', 'Turno de ' . horas($c['turno'] * 60) . ' h: supera las ' . $r['jornada_max_diaria_h'] . ' h; solo con acuerdo escrito y 12 h de descanso.', 'aviso']
                : [$f, 'jornada_max_diaria_h', 'Turno de ' . horas($c['turno'] * 60) . ' h (máximo ' . $r['jornada_max_diaria_h'] . ' h de jornada ordinaria).', 'bloqueo'];
        }
    }
    // Descanso en domingo al menos una vez cada N semanas (si hay planificación esas semanas).
    if ($n = (int)$r['domingo_cada_semanas']) {
        $domingos = array_values(array_filter(array_keys($cal), fn($f) => date('N', strtotime($f)) == 7));
        $antes = calendario_teorico($db, $u, date('Y-m-d', strtotime("$ini -" . (7 * $n) . ' days')), date('Y-m-d', strtotime("$ini -1 day")));
        $todos = array_merge(array_filter($antes, fn($f) => date('N', strtotime($f)) == 7, ARRAY_FILTER_USE_KEY), array_intersect_key($cal, array_flip($domingos)));
        ksort($todos);
        $seguidos = 0;
        foreach ($todos as $f => $c) {
            $seguidos = $c['turno'] > 0 ? $seguidos + 1 : 0;
            if ($seguidos >= $n && $dentro($f)) {
                $mal[] = [$f, 'domingo_cada_semanas', "Trabaja $seguidos domingos seguidos: el descanso semanal debe caer en domingo al menos una vez cada $n semanas (salvo contratos de fin de semana).", 'aviso'];
                $seguidos = 0;
            }
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
    $js = con_pausa_auto(jornadas(fichajes_de($db, (int)$u["id"], a_utc("$desde -2 day"), a_utc("$hasta +1 day"))), $u);
    $mal = [];
    $prev = $prev_dia = null;
    foreach ($js as $j) {
        $f = local($j['entrada'], 'Y-m-d');
        $ini = strtotime($j['entrada'] . ' UTC');
        // Dos jornadas que empiezan el mismo día son una jornada partida: el descanso de 12 h se mide entre días.
        if ($prev && $prev_dia === $f && $r['jornada_partida_pausa_h'] && $f >= $desde && $f <= $hasta && $ini - $prev < $r['jornada_partida_pausa_h'] * 3600) {
            $mal[] = [$f, 'jornada_partida_pausa_h', 'Jornada partida con una interrupción de ' . round(($ini - $prev) / 60) . ' min (mínimo ' . $r['jornada_partida_pausa_h'] . ' h).', 'aviso'];
        }
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
