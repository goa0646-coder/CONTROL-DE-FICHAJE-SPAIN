<?php
// Estudio de plantilla (interno): cuántas personas hacen falta como mínimo para cubrir los ratios de un año
// cumpliendo el convenio, y generación del calendario anual con la plantilla real usando las menos personas posibles.

// Grupos de turnos por departamento, solo los que tienen ratios activos. [clave => [nombre, departamento_id, horarios]].
function grupos_estudio(PDO $db): array
{
    $g = [];
    foreach ($db->query('SELECT DISTINCT h.id, h.departamento_id, d.nombre FROM necesidades n JOIN horarios h ON h.id = n.horario_id
        LEFT JOIN departamentos d ON d.id = h.departamento_id WHERE n.activo = 1 AND h.activo = 1 ORDER BY d.nombre') as $x) {
        $k = $x['departamento_id'] ? 'd' . $x['departamento_id'] : 'sin';
        $g[$k] ??= ['nombre' => $x['nombre'] ?? 'Turnos sin departamento', 'departamento_id' => $x['departamento_id'] ? (int)$x['departamento_id'] : null, 'horarios' => []];
        $g[$k]['horarios'][] = (int)$x['id'];
    }
    return $g;
}

// Ratios de cada día del periodo (se calculan una vez y se reutilizan en todas las pruebas).
function necesidades_periodo(PDO $db, string $desde, string $hasta): array
{
    $out = [];
    foreach (rango_fechas($desde, $hasta) as $f) $out[$f] = necesidades_dia($db, $f);
    return $out;
}

// Minutos de turno que exige un grupo en el periodo y el máximo de personas a la vez en un día.
function demanda(PDO $db, array $grupo, array $nec): array
{
    $h = horarios($db);
    $total = $pico = 0;
    foreach ($nec as $f => $lista) {
        $dia = 0;
        foreach ($lista as [$hid, , $n]) {
            if (!in_array($hid, $grupo['horarios'], true)) continue;
            $d = $h[$hid]['dias'][(int)date('N', strtotime($f))] ?? ($h[$hid]['dias'] ? reset($h[$hid]['dias']) : null);
            $total += $n * (int)($d['minutos'] ?? 0);
            $dia += $n;
        }
        $pico = max($pico, $dia);
    }
    return [$total, $pico];
}

// Cota teórica: horas que pide el turno / horas anuales del convenio, y nunca menos que el pico del día × 7/5 (5 días seguidos).
function cota_personas(PDO $db, array $grupo, string $desde, string $hasta, array $nec): int
{
    $r = reglas($db);
    $dias = count(rango_fechas($desde, $hasta));
    [$total, $pico] = demanda($db, $grupo, $nec);
    if (!$total) return 0;
    $tope = $r['horas_anuales'] ? $r['horas_anuales'] * 60 * $dias / 365 : $r['horas_semana_media'] * 60 * $dias / 7;
    return max(1, (int)ceil($total / $tope), (int)ceil($pico * 7 / 5));
}

// Plantilla ficticia de $n personas polivalentes del grupo, con sus vacaciones repartidas a lo largo del año.
function plantilla_ficticia(array $grupo, int $n, array $fechas, int $vacaciones, array $puestos): array
{
    $personas = $bloq = $pp = [];
    for ($i = 0; $i < $n; $i++) {
        $id = -1 - $i;
        $personas[] = ['id' => $id, 'nombre' => 'Persona', 'apellidos' => (string)($i + 1), 'centro_id' => null, 'horario_id' => null, 'departamento_id' => $grupo['departamento_id'],
            'alta_utc' => '2000-01-01 00:00:00', 'baja_utc' => null, 'rol' => 'empleado', 'activo' => 1];
        $pp[$id] = $puestos;
        $ini = intdiv($i * count($fechas), max(1, $n));
        for ($k = 0; $k < $vacaciones; $k++) $bloq[$id][$fechas[($ini + $k) % count($fechas)]] = 1;
    }
    return [$personas, $bloq, $pp];
}

// Busca el mínimo de personas: empieza en la cota teórica y sube hasta que no falta nadie en ningún turno.
function minimo_personas(PDO $db, array $grupo, string $desde, string $hasta, array $nec, int $vacaciones = 30, int $maximo = 120): array
{
    $r = reglas($db);
    $fechas = rango_fechas($desde, $hasta);
    [$total, $pico] = demanda($db, $grupo, $nec);
    $res = ['total' => $total, 'pico' => $pico, 'minimo' => null, 'cota' => 0, 'faltan' => [], 'motivos' => [], 'media' => 0, 'noches' => 0, 'findes' => 0];
    if (!$total) return $res;
    $res['cota'] = $n = cota_personas($db, $grupo, $desde, $hasta, $nec);
    $puestos = array_values(array_unique(array_filter(array_merge(...array_map(fn($l) => array_column($l, 1), array_values($nec))))));
    // Reparto equilibrado entre N personas: cubre más con menos gente que «llenar» a unas pocas (medido en un año real).
    $o = ['reglas' => $r, 'horarios' => $grupo['horarios'], 'necesidades' => $nec, 'modo' => 'justo', 'preferencias' => []];
    $ultimo = null;
    for (; $n <= $maximo; $n++) {
        [$personas, $o['bloqueados'], $o['puestos']] = plantilla_ficticia($grupo, $n, $fechas, $vacaciones, $puestos);
        $ultimo = planificar($db, $personas, $desde, $hasta, $o);
        // Fallos que no se arreglan con más gente: no hace falta seguir subiendo.
        $fijos = array_filter($ultimo['faltan'], fn($x) => $x[4] !== MOTIVO_REGLAS);
        if (!$ultimo['faltan'] || count($fijos) === count($ultimo['faltan'])) break;
    }
    $res['faltan'] = $ultimo['faltan'];
    $res['motivos'] = array_count_values(array_column($ultimo['faltan'], 4));
    if (!$ultimo['faltan']) {
        $res['minimo'] = $n;
        $usados = array_filter($ultimo['min']);
        $res['media'] = $usados ? array_sum($usados) / count($usados) : 0;
        $res['noches'] = $usados ? array_sum($ultimo['noches']) / count($usados) : 0;
        $res['findes'] = $usados ? array_sum($ultimo['findes']) / count($usados) : 0;
    }
    return $res;
}

// Personas reales de los grupos elegidos (por departamento; «sin departamento» = personas sin departamento).
function personas_de_grupos(PDO $db, array $grupos): array
{
    $deps = array_column($grupos, 'departamento_id');
    return array_values(array_filter($db->query("SELECT * FROM usuarios WHERE activo = 1 AND rol <> 'inspeccion' ORDER BY nombre, apellidos")->fetchAll(),
        fn($u) => in_array($u['departamento_id'] ? (int)$u['departamento_id'] : null, $deps, true)));
}

// Vacaciones previstas para el calendario: bloques seguidos de $dias días, repartidos a lo largo del año entre las personas,
// salvo a quien ya tiene vacaciones aprobadas ese año. [uid => [desde, hasta]].
function vacaciones_previstas(PDO $db, array $gente, array $fechas, int $dias): array
{
    $out = [];
    if (!$dias || !$gente) return $out;
    $tipo = consulta($db, "SELECT id FROM tipos_ausencia WHERE nombre LIKE 'Vacaciones%' ORDER BY id LIMIT 1")->fetchColumn();
    foreach (array_values($gente) as $i => $u) {
        if ($tipo && consulta($db, "SELECT 1 FROM ausencias WHERE usuario_id = ? AND tipo_id = ? AND estado = 'aprobada' AND hasta >= ? AND desde <= ?", [$u['id'], $tipo, $fechas[0], end($fechas)])->fetchColumn()) continue;
        $ini = intdiv($i * count($fechas), count($gente));
        $ini = min($ini, count($fechas) - $dias);
        $out[$u['id']] = [$fechas[max(0, $ini)], $fechas[min(count($fechas) - 1, max(0, $ini) + $dias - 1)]];
    }
    return $out;
}

// Calendario de un año (o periodo) con la plantilla real.
// 'minimo': por departamento, prueba con la cota teórica de personas, una más, otra… (primero las de más antigüedad) y se
// queda con el primer número que cubre todo; el resto queda sin turnos. Si ni con todas se cubre, usa todas. 'justo': todas.
// $vac: días de vacaciones que se dejan libres a cada persona (bloque seguido, escalonado entre la plantilla).
function plan_anual(PDO $db, array $grupos, string $desde, string $hasta, string $modo, int $vac = 0): array
{
    $todas = personas_de_grupos($db, $grupos);
    $nec = necesidades_periodo($db, $desde, $hasta);
    $fechas = rango_fechas($desde, $hasta);
    $res = ['plan' => [], 'faltan' => [], 'min' => [], 'noches' => [], 'findes' => [], 'personas' => $todas, 'vacaciones' => []];
    foreach ($grupos as $g) {
        $gente = personas_de_grupos($db, [$g]);
        usort($gente, fn($a, $b) => [$a['alta_utc'], $a['id']] <=> [$b['alta_utc'], $b['id']]);
        $desde_n = $modo === 'minimo' && $gente ? max(1, min(count($gente), cota_personas($db, $g, $desde, $hasta, $nec))) : count($gente);
        for ($n = $desde_n; ; $n++) {
            $usar = array_slice($gente, 0, $n);
            $vacs = vacaciones_previstas($db, $usar, $fechas, $vac);
            $bloq = [];
            foreach ($vacs as $uid => [$a, $b]) foreach (rango_fechas($a, $b) as $f) $bloq[$uid][$f] = 1;
            $r = planificar($db, $usar, $desde, $hasta, ['modo' => 'justo', 'horarios' => $g['horarios'], 'necesidades' => $nec, 'bloqueados' => $bloq, 'preferencias' => []]);
            if (!$r['faltan'] || $n >= count($gente)) break;
        }
        // Las que no hacen falta: descanso todo el periodo (quedan como bolsa para bajas).
        foreach (array_slice($gente, $n) as $u) $r['plan'][$u['id']] = array_fill_keys($fechas, null);
        foreach (['plan', 'min', 'noches', 'findes'] as $k) $res[$k] += $r[$k];
        $res['vacaciones'] += $vacs;
        $res['faltan'] = array_merge($res['faltan'], $r['faltan']);
    }
    return $res;
}
