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
function generar_cuadrante(PDO $db, array $personas, string $desde, string $hasta, ?array $r = null): array
{
    return planificar($db, $personas, $desde, $hasta, ['reglas' => $r, 'completar' => true])['plan'];
}

// Pesos medidos con un año de residencia (16 auxiliares para 4+3+2 diarios): reparte la carga de la semana (queda gente
// para el domingo) y premia seguir en el mismo turno (bloques de unos 4 días en vez de días sueltos).
const PESO_SEMANA = 32;
const SEGUIR_TURNO = 400;
// Hueco que se arregla con más gente (el resto, como un turno sin horas o sin nadie del departamento, no).
const MOTIVO_REGLAS = 'no hay más personas libres sin pasarse de horas, días seguidos o descansos';

// Motor de planificación. Reglas duras: no está de baja/vacaciones, tiene el puesto y es de su departamento, descanso entre
// jornadas, horas de la semana, máximo 5 días seguidos, un domingo libre cada N semanas y las horas anuales del convenio
// (repartidas a lo largo del año para no agotarlas antes de diciembre).
// $o: modo 'justo' (reparte noches, findes y horas) o 'minimo' (llena primero a quien ya trabaja: usa las menos personas),
//     puestos [uid => [pid]] (sustituye a los de la BD), horarios [hid] (solo esos turnos), bloqueados [uid => [fecha => 1]],
//     necesidades [fecha => lista] (caché), reglas.
// Devuelve plan, faltan [[fecha, horario_id, puesto_id, cuántos, motivo]] y min [uid => minutos].
function planificar(PDO $db, array $personas, string $desde, string $hasta, array $o = []): array
{
    $r = $o['reglas'] ?? null ?: reglas($db);
    $h = horarios($db);
    $pp = $o['puestos'] ?? puestos_por_persona($db);
    $minimo = ($o['modo'] ?? 'justo') === 'minimo';
    $completar = !empty($o['completar']);
    $solo = isset($o['horarios']) ? array_flip($o['horarios']) : null;
    $bloq = $o['bloqueados'] ?? [];
    $fechas = rango_fechas($desde, $hasta);
    $nec = [];
    foreach ($fechas as $f) $nec[$f] = array_values(array_filter($o['necesidades'][$f] ?? necesidades_dia($db, $f), fn($x) => $solo === null || isset($solo[$x[0]])));
    // Turno de un departamento: solo su gente. Si en ese departamento no hay nadie, quien ya lo ha hecho (cuadrante de los
    // últimos 4 meses o su horario habitual), p. ej. coordinación sin departamento asignado en las fichas.
    $deps = array_flip(array_map(fn($u) => (int)$u['departamento_id'], $personas));
    $hecho = [];
    foreach (consulta($db, 'SELECT DISTINCT usuario_id, horario_id FROM planificacion WHERE horario_id IS NOT NULL AND fecha BETWEEN ? AND ?',
        [date('Y-m-d', strtotime("$desde -120 days")), $hasta]) as $x) $hecho[$x['usuario_id']][$x['horario_id']] = true;
    foreach ($personas as $u) if ($u['horario_id']) $hecho[$u['id']][$u['horario_id']] = true;
    $vale = fn($u, $hid) => !$h[$hid]['departamento_id'] || (isset($deps[(int)$h[$hid]['departamento_id']])
        ? $u['departamento_id'] == $h[$hid]['departamento_id'] : isset($hecho[$u['id']][$hid]));
    $turnos_nec = array_unique(array_merge([], ...array_map(fn($l) => array_column($l, 0), array_values($nec))));
    // Solo entra quien puede cubrir algo (puesto y departamento); al resto no se le toca su horario.
    $personas = array_values(array_filter($personas, function ($u) use ($nec, $pp, $vale) {
        foreach ($nec as $l) foreach ($l as [$hid, $pid]) if ((!$pid || in_array($pid, $pp[$u['id']] ?? [], true)) && $vale($u, $hid)) return true;
        return false;
    }));
    // Horas anuales (prorrateadas al periodo) y ritmo: a mitad de sus días disponibles, como mucho la mitad + 24 h.
    $tope = $r['horas_anuales'] ? $r['horas_anuales'] * 60 * count($fechas) / 365 : null;
    // Completar jornada: objetivo del periodo (horas anuales prorrateadas o la media semanal).
    $objetivo = min($tope ?? PHP_INT_MAX, $r['horas_semana_media'] * 60 * count($fechas) / 7);
    $ant = date('Y-m-d', strtotime("$desde -7 days"));
    $estado = [];
    foreach ($personas as $u) {
        $uid = (int)$u['id'];
        $cal = calendario_teorico($db, $u, $ant, $hasta);
        $pasado = array_filter($cal, fn($f) => $f < $desde, ARRAY_FILTER_USE_KEY);
        $iv = intervalos_plan($pasado);
        $seguidos = 0;
        foreach (array_reverse($pasado) as $c) {
            if (!$c['turno']) break;
            $seguidos++;
        }
        // Días en que puede trabajar (sin vacaciones ni bajas): el ritmo de horas se reparte sobre ellos, así quien vuelve de vacaciones recupera.
        $disp = [];
        $k = 0;
        // Antes del alta o después de la baja no puede trabajar: se trata como un día bloqueado.
        $alta = local($u['alta_utc'] ?? '2000-01-01 00:00:00', 'Y-m-d');
        $baja = !empty($u['baja_utc']) ? local($u['baja_utc'], 'Y-m-d') : '9999-12-31';
        foreach ($fechas as $f) if ($f < $alta || $f > $baja) $bloq[$uid][$f] = 1;
        foreach ($fechas as $f) $disp[$f] = $k += (isset($bloq[$uid][$f]) || ($cal[$f]['ausencia'] && $cal[$f]['ausencia']['minutos'] === null)) ? 0 : 1;
        $ultimo = null;
        foreach ($pasado as $c) if ($c['horario_id'] && $c['turno']) $ultimo = (int)$c['horario_id'];
        $estado[$uid] = ['fin' => $iv ? end($iv)[2] : 0, 'seguidos' => $seguidos, 'min' => 0, 'finde' => 0, 'noches' => 0, 'semana' => [], 'cal' => $cal, 'domingos' => 0,
            'disp' => $disp, 'disp_total' => max(1, $k), 'ultimo' => $ultimo, 'u' => $u, 'fin0' => $iv ? end($iv)[2] : 0, 'seguidos0' => $seguidos];
    }
    // Datos de un turno en una fecha: [minutos, inicio, fin, noche] o el motivo por el que no se puede usar.
    $datos = function (int $hid, string $f) use ($h, $r) {
        $d = $h[$hid]['dias'][(int)date('N', strtotime($f))] ?? ($h[$hid]['dias'] ? reset($h[$hid]['dias']) : null);
        if (!$d || !$d['entrada'] || !$d['salida']) return 'el turno no tiene hora de entrada y salida';
        if ($d['minutos'] > $r['jornada_max_diaria_h'] * 60) return 'el turno dura más que la jornada máxima diaria';
        $ini = ts_local($f, $d['entrada']);
        $fin = ts_local($f, $d['salida']);
        if ($fin <= $ini) $fin += 86400;
        return [$d['minutos'], $ini, $fin, (int)substr($d['entrada'], 0, 2) >= 20 || (int)substr($d['salida'], 0, 2) <= 7 && $fin - $ini > 3600];
    };
    // Por qué no se cubre un turno: nadie puede hacerlo nunca (falta gente en el departamento o con el puesto) o las reglas no dan.
    $motivo = function (int $hid, ?int $pid) use ($personas, $pp, $vale, $h, $db) {
        foreach ($personas as $u) if ($vale($u, $hid) && (!$pid || in_array($pid, $pp[$u['id']] ?? [], true))) return MOTIVO_REGLAS;
        $dep = $h[$hid]['departamento_id'] ? consulta($db, 'SELECT nombre FROM departamentos WHERE id = ?', [$h[$hid]['departamento_id']])->fetchColumn() : null;
        return $pid ? 'nadie tiene ese puesto asignado en su ficha'
            : 'nadie del departamento «' . $dep . '»: pon ese departamento en la ficha de quien hace este turno';
    };
    $plan = $faltan = [];
    foreach ($fechas as $i => $f) {
        $semana = date('o-W', strtotime($f));
        $dom = date('N', strtotime($f)) == 7;
        $finde = date('N', strtotime($f)) >= 6 || $estado && reset($estado)['cal'][$f]['festivo'];
        $hoy = [];
        // Reglas duras para una persona y un turno ese día.
        $puede = function (int $uid, int $hid, ?int $pid, array $t) use (&$estado, &$hoy, $bloq, $pp, $vale, $f, $semana, $dom, $r, $tope) {
            $e = $estado[$uid];
            $c = $e['cal'][$f];
            [$m, $ini] = $t;
            if (isset($hoy[$uid]) || isset($bloq[$uid][$f]) || ($c['ausencia'] && $c['ausencia']['minutos'] === null)) return false;
            if ($pid && !in_array($pid, $pp[$uid] ?? [], true)) return false;
            if (!$vale($e['u'], $hid)) return false;
            if ($e['fin'] && $ini - $e['fin'] < $r['descanso_entre_jornadas_h'] * 3600) return false;
            if (($e['semana'][$semana] ?? 0) + $m > $r['horas_semana_media'] * 60) return false;
            if ($e['seguidos'] >= 5) return false;
            if ($dom && $r['domingo_cada_semanas'] && $e['domingos'] >= $r['domingo_cada_semanas'] - 1) return false;
            if ($tope && ($e['min'] + $m > $tope || $e['min'] + $m > $tope * $e['disp'][$f] / $e['disp_total'] + 24 * 60)) return false;
            return true;
        };
        $asignar = function (int $uid, int $hid, ?int $pid, array $t) use (&$estado, &$hoy, &$plan, $f, $semana, $finde) {
            [$m, , $fin, $noche] = $t;
            $hoy[$uid] = true;
            $plan[$uid][$f] = ['horario_id' => $hid, 'puesto_id' => $pid];
            $estado[$uid]['fin'] = $fin;
            $estado[$uid]['min'] += $m;
            $estado[$uid]['semana'][$semana] = ($estado[$uid]['semana'][$semana] ?? 0) + $m;
            $estado[$uid]['finde'] += $finde ? 1 : 0;
            $estado[$uid]['noches'] += $noche ? 1 : 0;
            $estado[$uid]['ultimo'] = $hid;
        };
        $cuantos = [];
        foreach ($nec[$f] as [$hid, $pid, $n]) {
            $t = $datos($hid, $f);
            if (is_string($t)) {
                $faltan[] = [$f, $hid, $pid, $n, $t];
                continue;
            }
            $cand = [];
            foreach ($personas as $u) {
                $uid = (int)$u['id'];
                if (!$puede($uid, $hid, $pid, $t)) continue;
                $e = $estado[$uid];
                $ayer = ($plan[$uid][date('Y-m-d', strtotime("$f -1 day"))]['horario_id'] ?? null) == $hid ? SEGUIR_TURNO : 0;
                // Justo: menos es mejor (horas, findes y noches acumuladas). Mínimo: primero quien ya trabaja más.
                $cand[$uid] = $minimo ? -$e['min'] / 60 - $ayer + $uid / 1e6
                    : $e['min'] / 60 + ($finde ? 8 * $e['finde'] : 0) + ($t[3] ? 8 * $e['noches'] : 0) - $ayer + $uid / 1e6
                    // Quien ya lleva más horas esta semana, después: así queda gente libre para el domingo.
                    + PESO_SEMANA * ($e['semana'][$semana] ?? 0) / 60;
            }
            asort($cand);
            $elegidos = array_slice(array_keys($cand), 0, $n);
            if (count($elegidos) < $n) $faltan[] = [$f, $hid, $pid, $n - count($elegidos), $motivo($hid, $pid)];
            foreach ($elegidos as $uid) $asignar($uid, $hid, $pid, $t);
            $cuantos[$hid] = ($cuantos[$hid] ?? 0) + count($elegidos);
        }
        foreach ($personas as $u) {
            $uid = (int)$u['id'];
            if ($dom) $estado[$uid]['domingos'] = isset($hoy[$uid]) ? $estado[$uid]['domingos'] + 1 : 0;
            if (isset($hoy[$uid])) $estado[$uid]['seguidos']++;
            else {
                $estado[$uid]['seguidos'] = 0;
                $plan[$uid][$f] = null;
            }
        }
    }
    // Completar jornada (después de cubrir las necesidades de todo el periodo, para no gastar horas que harán falta):
    // en los días libres, quien no llega a sus horas trabaja en el turno de al lado, su último o su habitual.
    if ($completar) {
        $mins = fn($uid, $f) => ($x = $plan[$uid][$f] ?? null) && is_array($t = $datos($x['horario_id'], $f)) ? $t : null;
        $ayer = fn($f, $n) => date('Y-m-d', strtotime("$f $n day"));
        uasort($estado, fn($x, $y) => $x['min'] <=> $y['min']);
        foreach ($fechas as $f) {
            $semana = date('o-W', strtotime($f));
            if (date('N', strtotime($f)) == 7 && $r['domingo_cada_semanas']) continue; // los domingos los decide la regla del domingo libre
            foreach ($estado as $uid => $e) {
                $c = $e['cal'][$f];
                if (!array_key_exists($f, $plan[$uid] ?? []) || $plan[$uid][$f] || isset($bloq[$uid][$f]) || ($c['ausencia'] && $c['ausencia']['minutos'] === null)) continue;
                // Días seguidos que quedarían (antes y después).
                $racha = 1;
                for ($k = -1; ($g = $ayer($f, $k)) >= $desde ? !empty($plan[$uid][$g]) : false; $k--) $racha++;
                if ($ayer($f, $k) < $desde) $racha += $e['seguidos0'];
                for ($k = 1; !empty($plan[$uid][$ayer($f, $k)]); $k++) $racha++;
                if ($racha > 5) continue;
                $previo = $mins($uid, $ayer($f, -1));
                $fin_previo = $previo ? $previo[2] : ($ayer($f, -1) < $desde ? $e['fin0'] : 0);
                $sig = $mins($uid, $ayer($f, 1));
                $semana_min = 0;
                foreach ($plan[$uid] as $g => $x) if ($x && date('o-W', strtotime($g)) === $semana && is_array($t = $datos($x['horario_id'], $g))) $semana_min += $t[0];
                $opciones = array_unique(array_filter([$plan[$uid][$ayer($f, -1)]['horario_id'] ?? null, $plan[$uid][$ayer($f, 1)]['horario_id'] ?? null,
                    $e['ultimo'], $e['u']['horario_id'] ? (int)$e['u']['horario_id'] : null, ...$turnos_nec], fn($x) => $x && isset($h[$x]) && $vale($e['u'], $x)));
                foreach ($opciones as $hid) {
                    $t = $datos($hid, $f);
                    if (is_string($t) || $estado[$uid]['min'] + $t[0] / 2 > $objetivo) continue;
                    if ($fin_previo && $t[1] - $fin_previo < $r['descanso_entre_jornadas_h'] * 3600) continue;
                    if ($sig && $sig[1] - $t[2] < $r['descanso_entre_jornadas_h'] * 3600) continue;
                    if ($semana_min + $t[0] > $r['horas_semana_media'] * 60) continue;
                    $plan[$uid][$f] = ['horario_id' => $hid, 'puesto_id' => null];
                    $estado[$uid]['min'] += $t[0];
                    $estado[$uid]['finde'] += date('N', strtotime($f)) >= 6 ? 1 : 0;
                    $estado[$uid]['noches'] += $t[3] ? 1 : 0;
                    break;
                }
            }
        }
        ksort($estado);
    }
    return ['plan' => $plan, 'faltan' => $faltan, 'min' => array_map(fn($e) => $e['min'], $estado),
        'noches' => array_map(fn($e) => $e['noches'], $estado), 'findes' => array_map(fn($e) => $e['finde'], $estado)];
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
