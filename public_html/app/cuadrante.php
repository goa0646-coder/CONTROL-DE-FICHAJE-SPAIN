<?php
// Necesidades por turno y puesto, cobertura (huecos) y generador de cuadrantes.
// El generador solo propone: lo que guarda pasa siempre por comprobar_plan().

function puestos(PDO $db): array
{
    return $db->query('SELECT id, nombre FROM puestos WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
}

// [usuario_id => [puesto_id, …]]
function puestos_por_persona(PDO $db): array
{
    $out = [];
    foreach ($db->query('SELECT usuario_id, puesto_id FROM usuarios_puestos') as $x) $out[$x['usuario_id']][] = (int)$x['puesto_id'];
    return $out;
}

// Necesidades de un día: [[horario_id, puesto_id|null, personas], …]. Las de un día concreto sustituyen a las de «todos los días».
function necesidades_dia(PDO $db, string $fecha, ?int $centro = null): array
{
    $todas = consulta($db, 'SELECT * FROM necesidades WHERE activo = 1 AND (desde IS NULL OR desde <= ?) AND (hasta IS NULL OR hasta >= ?)', [$fecha, $fecha])->fetchAll();
    $n = (int)date('N', strtotime($fecha));
    $festivo = fn($c) => (bool)consulta($db, 'SELECT 1 FROM festivos WHERE fecha = ? AND (centro_id IS NULL OR centro_id = ?)', [$fecha, $c])->fetchColumn();
    $out = [];
    foreach ($todas as $x) {
        if ($centro && $x['centro_id'] && $x['centro_id'] != $centro) continue;
        if (!$x['festivos'] && $festivo($x['centro_id'] ?? $centro)) continue; // regla que no se aplica en festivos
        if ($x['dia'] && $x['dia'] != $n) continue;
        $clave = $x['horario_id'] . ':' . ($x['puesto_id'] ?? '');
        if (!isset($out[$clave]) || $x['dia']) $out[$clave] = [(int)$x['horario_id'], $x['puesto_id'] ? (int)$x['puesto_id'] : null, (int)$x['personas']];
    }
    // Primero los puestos concretos (los más difíciles de cubrir).
    usort($out, fn($a, $b) => ($a[1] === null) <=> ($b[1] === null));
    return $out;
}

// Reparte a las personas que trabajan ese día entre las necesidades. $dia: [uid => ['horario_id', 'puesto_id']].
// Devuelve [[horario_id, puesto_id, faltan, [uid asignados]], …].
function cubrir_dia(array $necesidades, array $dia, array $puestos_de): array
{
    $libres = $dia;
    $out = [];
    foreach ($necesidades as [$h, $p, $n]) {
        $asig = [];
        foreach ($libres as $uid => $c) {
            if (count($asig) >= $n) break;
            if ($c['horario_id'] != $h) continue;
            if ($p && $c['puesto_id'] != $p && !($c['puesto_id'] === null && in_array($p, $puestos_de[$uid] ?? [], true))) continue;
            $asig[] = $uid;
            unset($libres[$uid]);
        }
        $out[] = [$h, $p, $n - count($asig), $asig];
    }
    return $out;
}

// Huecos entre $desde y $hasta: [[fecha, horario_id, puesto_id, faltan], …]. Tiene en cuenta bajas y vacaciones aprobadas.
function huecos(PDO $db, array $personas, string $desde, string $hasta): array
{
    $pp = puestos_por_persona($db);
    $cals = [];
    foreach ($personas as $u) $cals[$u['id']] = calendario_teorico($db, $u, $desde, $hasta);
    $out = [];
    foreach (rango_fechas($desde, $hasta) as $f) {
        $dia = [];
        foreach ($cals as $uid => $cal) {
            $c = $cal[$f];
            if ($c['horario_id'] && $c['turno'] > 0) $dia[$uid] = ['horario_id' => $c['horario_id'], 'puesto_id' => $c['puesto_id']];
        }
        foreach (cubrir_dia(necesidades_dia($db, $f), $dia, $pp) as [$h, $p, $faltan]) if ($faltan > 0) $out[] = [$f, $h, $p, $faltan];
    }
    return $out;
}

// Propone un cuadrante: [uid => [fecha => ['horario_id', 'puesto_id'] | null (libre)]].
// Reglas duras: no está de baja/vacaciones, tiene el puesto, descanso entre jornadas, horas de la semana y máximo 5 días seguidos.
// Reparto justo: primero quien menos horas, fines de semana y noches lleva.
function generar_cuadrante(PDO $db, array $personas, string $desde, string $hasta, ?array $r = null): array
{
    $r ??= reglas($db);
    $h = horarios($db);
    $pp = puestos_por_persona($db);
    // Solo entra quien puede cubrir algo; al resto no se le toca su horario.
    $cualquiera = (bool)$db->query('SELECT COUNT(*) FROM necesidades WHERE puesto_id IS NULL')->fetchColumn();
    $personas = array_values(array_filter($personas, fn($u) => $cualquiera || !empty($pp[$u['id']])));
    $ant = date('Y-m-d', strtotime("$desde -7 days"));
    $prev = [];
    $estado = [];
    foreach ($personas as $u) {
        $uid = (int)$u['id'];
        $cal = calendario_teorico($db, $u, $ant, $hasta);
        $iv = intervalos_plan(array_filter($cal, fn($f) => $f < $desde, ARRAY_FILTER_USE_KEY));
        $seguidos = 0;
        foreach (array_reverse(array_filter($cal, fn($f) => $f < $desde, ARRAY_FILTER_USE_KEY)) as $c) {
            if (!$c['turno']) break;
            $seguidos++;
        }
        $estado[$uid] = ['fin' => $iv ? end($iv)[2] : 0, 'seguidos' => $seguidos, 'min' => 0, 'finde' => 0, 'noches' => 0, 'semana' => [], 'cal' => $cal];
    }
    $plan = [];
    foreach (rango_fechas($desde, $hasta) as $f) {
        $semana = date('o-W', strtotime($f));
        $finde = date('N', strtotime($f)) >= 6 || $estado && reset($estado)['cal'][$f]['festivo'];
        $hoy = [];
        foreach (necesidades_dia($db, $f) as [$hid, $pid, $n]) {
            $d = $h[$hid]['dias'][(int)date('N', strtotime($f))] ?? ($h[$hid]['dias'] ? reset($h[$hid]['dias']) : null);
            if (!$d || !$d['entrada'] || !$d['salida']) continue;
            $ini = ts_local($f, $d['entrada']);
            $fin = ts_local($f, $d['salida']);
            if ($fin <= $ini) $fin += 86400;
            $noche = (int)substr($d['entrada'], 0, 2) >= 20 || (int)substr($d['salida'], 0, 2) <= 7 && $fin - $ini > 3600;
            $cand = [];
            foreach ($personas as $u) {
                $uid = (int)$u['id'];
                $e = $estado[$uid];
                $c = $e['cal'][$f];
                if (isset($hoy[$uid]) || ($c['ausencia'] && $c['ausencia']['minutos'] === null)) continue;
                if ($pid && !in_array($pid, $pp[$uid] ?? [], true)) continue;
                if ($h[$hid]['departamento_id'] && $u['departamento_id'] != $h[$hid]['departamento_id']) continue; // turno de otro departamento
                if ($e['fin'] && $ini - $e['fin'] < $r['descanso_entre_jornadas_h'] * 3600) continue;
                if (($e['semana'][$semana] ?? 0) + $d['minutos'] > $r['horas_semana_media'] * 60) continue;
                if ($e['seguidos'] >= 5) continue;
                if ($d['minutos'] > $r['jornada_max_diaria_h'] * 60) continue;
                // Menos es mejor: horas acumuladas, fines de semana y noches; mismo turno que ayer, mejor.
                $cand[$uid] = $e['min'] / 60 + ($finde ? 8 * $e['finde'] : 0) + ($noche ? 8 * $e['noches'] : 0)
                    - (($plan[$uid][date('Y-m-d', strtotime("$f -1 day"))]['horario_id'] ?? null) == $hid ? 2 : 0) + $uid / 1e6;
            }
            asort($cand);
            foreach (array_slice(array_keys($cand), 0, $n) as $uid) {
                $hoy[$uid] = true;
                $plan[$uid][$f] = ['horario_id' => $hid, 'puesto_id' => $pid];
                $estado[$uid]['fin'] = $fin;
                $estado[$uid]['min'] += $d['minutos'];
                $estado[$uid]['semana'][$semana] = ($estado[$uid]['semana'][$semana] ?? 0) + $d['minutos'];
                $estado[$uid]['finde'] += $finde ? 1 : 0;
                $estado[$uid]['noches'] += $noche ? 1 : 0;
            }
        }
        foreach ($personas as $u) {
            $uid = (int)$u['id'];
            if (isset($hoy[$uid])) $estado[$uid]['seguidos']++;
            else {
                $estado[$uid]['seguidos'] = 0;
                $plan[$uid][$f] = null;
            }
        }
    }
    return $plan;
}

// Escribe un cuadrante (de generar_cuadrante) en la planificación. Las ausencias aprobadas se respetan solas.
function guardar_cuadrante(PDO $db, array $plan): void
{
    foreach ($plan as $uid => $dias) {
        foreach ($dias as $f => $x) {
            consulta($db, 'REPLACE INTO planificacion (usuario_id, fecha, horario_id, puesto_id) VALUES (?, ?, ?, ?)', [$uid, $f, $x['horario_id'] ?? null, $x['puesto_id'] ?? null]);
        }
    }
}

// Ratios de AS FRAGAS (capturas de su configuración anterior): turno => mínimo de personas.
// La tarde no venía en las capturas: se deja para que la empresa la añada con su número.
const RATIOS_RESIDENCIA = ['RATIO AUXILIARES MAÑANA' => ['AUXILIAR MAÑANA', 4], 'RATIO AUXILIARES NOCHE' => ['AUXILIAR NOCHE', 2], 'RATIO PARTIDO' => ['COORDINACIÓN PARTIDO', 2]];

// Crea las reglas cuyo turno ya existe (por nombre, sin importar mayúsculas ni tildes). Devuelve [creadas, turnos que faltan].
function cargar_ratios(PDO $db, array $ratios = RATIOS_RESIDENCIA): array
{
    $hors = [];
    foreach ($db->query('SELECT id, nombre FROM horarios WHERE activo = 1') as $h) $hors[normalizar($h['nombre'])] = (int)$h['id'];
    $creadas = $faltan = [];
    foreach ($ratios as $nombre => [$turno, $min]) {
        $hid = $hors[normalizar($turno)] ?? null;
        if (!$hid) { $faltan[] = $turno; continue; }
        if (consulta($db, 'SELECT 1 FROM necesidades WHERE nombre = ?', [$nombre])->fetchColumn()) continue;
        consulta($db, 'INSERT INTO necesidades (nombre, horario_id, puesto_id, dia, personas) VALUES (?, ?, NULL, 0, ?)', [$nombre, $hid, $min]);
        $creadas[] = $nombre;
    }
    return [$creadas, $faltan];
}

// Cron: si hoy o mañana no se llega al ratio (bajas, vacaciones…), avisa a administración una vez por día y turno.
function revisar_ratios(PDO $db, ?string $ahora = null): int
{
    $ahora ??= ahora();
    $personas = $db->query("SELECT * FROM usuarios WHERE activo = 1 AND rol IN ('empleado','responsable','admin')")->fetchAll();
    if (!$personas || !$db->query('SELECT 1 FROM necesidades WHERE activo = 1 LIMIT 1')->fetchColumn()) return 0;
    $hoy = local($ahora, 'Y-m-d');
    $manana = date('Y-m-d', strtotime("$hoy +1 day"));
    $hors = horarios($db);
    $pn = puestos($db);
    $admins = array_map('intval', $db->query("SELECT id FROM usuarios WHERE rol = 'admin' AND activo = 1")->fetchAll(PDO::FETCH_COLUMN));
    $n = 0;
    $lineas = [];
    foreach (huecos($db, $personas, $hoy, $manana) as [$f, $h, $p, $faltan]) {
        $txt = ($f === $hoy ? 'Hoy' : 'Mañana') . ' faltan ' . $faltan . ' en ' . ($hors[$h]['nombre'] ?? 'turno') . ($p ? ' (' . ($pn[$p] ?? '') . ')' : '') . ' para llegar al mínimo.';
        $nuevo = false;
        foreach ($admins as $a) $nuevo = notificar($db, $a, "ratio:$f:$h:" . ($p ?? '') . ":$faltan", $txt, 'planificacion&semana=' . $f) || $nuevo;
        if ($nuevo) { $n++; $lineas[] = $txt; }
    }
    if ($lineas) avisar_administracion('INDA: no se llega al mínimo de personal', implode("\n", $lineas) . "\n\n" . rtrim(config()['url'] ?? '', '/') . '/?p=planificacion');
    return $n;
}
