<?php
// Cuadrante en Excel: una hoja por mes («Enero 2027», «2027-01»…) o una hoja «Cuadrante» con fechas completas.
// Fila = persona (email, DNI o nombre); columna = día; casilla = turno (letra o nombre), D = descanso o código de ausencia.

const MESES_CORTOS = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'sep' => 9, 'set' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12];
const DESCANSO = ['d', 'l', 'libre', 'descanso', 'descanso semanal', 'ds', 'dl', '-'];
// Códigos cortos de ausencia → palabra que tiene que aparecer en el nombre del tipo.
const CODIGOS_AUSENCIA = ['v' => 'vacaciones', 'vac' => 'vacaciones', 'it' => 'baja', 'b' => 'baja', 'baja' => 'baja', 'ld' => 'libre disposicion', 'ap' => 'asuntos propios', 'vm' => 'visita medica', 'at' => 'accidente de trabajo'];

// "Enero 2027", "ene-27", "2027-01", "01/2027" → [2027, 1]. null si no hay mes.
function mes_de_texto(string $s): ?array
{
    $t = normalizar($s);
    if (preg_match('/\b(20\d\d)[-\/.](0?[1-9]|1[0-2])\b/', $t, $m)) return [(int)$m[1], (int)$m[2]];
    if (preg_match('/\b(0?[1-9]|1[0-2])[-\/.](20\d\d)\b/', $t, $m)) return [(int)$m[2], (int)$m[1]];
    if (preg_match('/(ene|feb|mar|abr|may|jun|jul|ago|sep|set|oct|nov|dic)[a-z]*\.?[\s\-\/]*(\d{2,4})\b/', $t, $m)) {
        $a = (int)$m[2];
        return [$a < 100 ? 2000 + $a : $a, MESES_CORTOS[$m[1]]];
    }
    return null;
}

function es_hoja_cuadrante(string $nombre): bool
{
    $n = normalizar($nombre);
    return str_starts_with($n, 'cuadrante') || str_starts_with($n, 'planificacion') || str_starts_with($n, 'turnos de') || mes_de_texto($nombre) !== null;
}

// Fechas de las columnas: [columna => 'Y-m-d']. Vale una fecha completa o el número del día (con el mes de la hoja).
function columnas_fecha(array $fila, ?array $mes): array
{
    $out = [];
    foreach ($fila as $j => $v) {
        $v = trim((string)$v);
        if ($v === '') continue;
        if (!preg_match('/^\d{1,2}$/', $v) && ($f = fecha_iso($v))) { $out[$j] = $f; continue; } // fecha completa o número de fecha de Excel
        if ($mes && preg_match('/^(?:[a-zA-Záéíóú]{1,3}\.?\s*)?(\d{1,2})(?:\s*[a-zA-Záéíóú]{1,3})?$/u', $v, $m) && checkdate($mes[1], (int)$m[1], $mes[0])) $out[$j] = sprintf('%04d-%02d-%02d', $mes[0], $mes[1], $m[1]);
    }
    return $out;
}

// Lee las hojas de cuadrante: [[persona (texto), fecha, valor, hoja, fila]].
function leer_cuadrantes(array $hojas): array
{
    $celdas = [];
    foreach ($hojas as $nombre => $hoja) {
        $mes = mes_de_texto($nombre);
        foreach (array_slice($hoja, 0, 6) as $f) if (!$mes) foreach ($f as $v) if ($mes = mes_de_texto((string)$v)) break; // «Noviembre 2026» en una celda de título
        foreach (array_slice($hoja, 0, 8, true) as $i => $f) {
            $fechas = columnas_fecha($f, $mes);
            if (count($fechas) < 7) continue;
            $primera = min(array_keys($fechas));
            // Columna de la persona: la cabecera Email / DNI / Persona…; si no hay, la primera de texto antes de las fechas.
            $cols = ['email' => null, 'dni' => null, 'nombre' => null];
            foreach ($f as $j => $v) {
                if ($j >= $primera) break;
                $n = normalizar((string)$v);
                if (in_array($n, ['email', 'e-mail', 'correo'], true)) $cols['email'] = $j;
                elseif (in_array($n, ['dni', 'nie', 'dni/nie'], true)) $cols['dni'] = $j;
                elseif ($n !== '' && $cols['nombre'] === null) $cols['nombre'] = $j;
            }
            if ($cols['email'] === null && $cols['dni'] === null) $cols['nombre'] ??= 0;
            foreach (array_slice($hoja, $i + 1, null, true) as $k => $fila) {
                $quien = array_filter(['email' => $cols['email'] !== null ? trim($fila[$cols['email']] ?? '') : '', 'dni' => $cols['dni'] !== null ? trim($fila[$cols['dni']] ?? '') : '',
                    'nombre' => $cols['nombre'] !== null ? trim($fila[$cols['nombre']] ?? '') : '']);
                if (!$quien) continue;
                foreach ($fechas as $j => $fecha) {
                    $v = trim((string)($fila[$j] ?? ''));
                    if ($v !== '') $celdas[] = [$quien, $fecha, $v, $nombre, $k + 1];
                }
            }
            break;
        }
    }
    return $celdas;
}

// Busca a la persona por email, DNI o nombre completo (solo si no hay dos con el mismo nombre).
// $personas: [['clave' => email, 'nombre' => …, 'dni' => …, 'departamento' => nombre normalizado]].
function persona_cuadrante(array $quien, array $indice): ?array
{
    if (!empty($quien['email']) && isset($indice['email'][mb_strtolower($quien['email'])])) return $indice['email'][mb_strtolower($quien['email'])];
    if (!empty($quien['dni']) && isset($indice['dni'][strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $quien['dni']))])) return $indice['dni'][strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $quien['dni']))];
    if (!empty($quien['nombre'])) {
        if (str_contains($quien['nombre'], '@')) return $indice['email'][mb_strtolower($quien['nombre'])] ?? null;
        $l = $indice['nombre'][normalizar($quien['nombre'])] ?? [];
        return count($l) === 1 ? $l[0] : null;
    }
    return null;
}

function indice_personas(array $personas): array
{
    $i = ['email' => [], 'dni' => [], 'nombre' => []];
    foreach ($personas as $p) {
        $i['email'][mb_strtolower($p['email'])] = $p;
        if ($p['dni'] !== '') $i['dni'][strtoupper($p['dni'])] = $p;
        $i['nombre'][normalizar($p['nombre'])][] = $p;
    }
    return $i;
}

// Qué significa una casilla para una persona: ['turno', nombre] | ['libre'] | ['ausencia', nombre del tipo] | ['nada'] | ['?', motivo].
// $turnos: [['nombre', 'letra', 'departamento' (normalizado)]]; $tipos: [nombres].
function valor_cuadrante(string $v, ?string $dep, array $turnos, array $tipos): array
{
    $n = normalizar($v);
    if ($n === '' || $n === 'f' || $n === 'festivo') return ['nada'];
    if (in_array($n, DESCANSO, true)) return ['libre'];
    foreach ($turnos as $t) if (normalizar($t['nombre']) === $n) return ['turno', $t['nombre']];
    $letra = array_values(array_filter($turnos, fn($t) => $t['letra'] !== '' && normalizar($t['letra']) === $n));
    if ($letra) {
        $suyos = array_values(array_filter($letra, fn($t) => $dep && $t['departamento'] === $dep));
        $generales = array_values(array_filter($letra, fn($t) => $t['departamento'] === ''));
        foreach ([$suyos, count($letra) === 1 ? $letra : [], $generales] as $l) if (count($l) === 1) return ['turno', $l[0]['nombre']];
        return ['?', "«{$v}» puede ser " . implode(' o ', array_unique(array_column($letra, 'nombre'))) . ': pon el nombre del turno o el departamento de la persona'];
    }
    foreach ($tipos as $t) if (normalizar($t) === $n) return ['ausencia', $t];
    if ($palabra = CODIGOS_AUSENCIA[$n] ?? null) foreach ($tipos as $t) if (str_contains(normalizar($t), $palabra)) return ['ausencia', $t];
    return ['?', "«{$v}» no es un turno, un descanso ni una ausencia conocidos"];
}

// Interpreta todas las casillas con las listas dadas. Devuelve el resumen y las asignaciones [email => [fecha => [tipo, valor]]].
function interpretar_cuadrante(array $celdas, array $personas, array $turnos, array $tipos): array
{
    $ind = indice_personas($personas);
    $r = ['asig' => [], 'sin_persona' => [], 'dudas' => [], 'cuenta' => ['turno' => 0, 'libre' => 0, 'ausencia' => 0], 'meses' => []];
    foreach ($celdas as [$quien, $fecha, $v, $hoja, $fila]) {
        $p = persona_cuadrante($quien, $ind);
        if (!$p) { $k = implode(' · ', $quien); $r['sin_persona'][$k] = ($r['sin_persona'][$k] ?? 0) + 1; continue; }
        $x = valor_cuadrante($v, $p['departamento'] ?? null, $turnos, $tipos);
        if ($x[0] === 'nada') continue;
        if ($x[0] === '?') { $r['dudas'][$x[1]] = ($r['dudas'][$x[1]] ?? 0) + 1; continue; }
        $r['asig'][mb_strtolower($p['email'])][$fecha] = $x;
        $r['cuenta'][$x[0]]++;
        $r['meses'][substr($fecha, 0, 7)] = true;
    }
    ksort($r['meses']);
    $r['meses'] = array_keys($r['meses']);
    return $r;
}

// Listas desde la BD (para aplicar) o completadas con lo que trae el libro (para revisar antes).
function listas_cuadrante(PDO $db, array $plan = []): array
{
    $deps = $db->query('SELECT id, nombre FROM departamentos')->fetchAll(PDO::FETCH_KEY_PAIR);
    $personas = array_map(fn($u) => ['email' => $u['email'], 'nombre' => nombre($u), 'dni' => (string)$u['dni'], 'departamento' => normalizar($deps[$u['departamento_id']] ?? '')],
        $db->query("SELECT * FROM usuarios WHERE rol <> 'inspeccion'")->fetchAll());
    foreach ($plan['secciones']['personas']['previa']['filas'] ?? [] as $f) if ($f['estado'] !== 'error') $personas[] = ['email' => $f['email'],
        'nombre' => trim($f['nombre'] . ' ' . $f['apellidos']), 'dni' => (string)$f['dni'], 'departamento' => normalizar($f['departamento'] ?? '')];
    $turnos = array_map(fn($h) => ['nombre' => $h['nombre'], 'letra' => $h['abreviatura'], 'departamento' => normalizar($deps[$h['departamento_id']] ?? '')], array_values(array_filter(horarios($db), fn($h) => $h['activo'])));
    // Los del libro sustituyen a los de la BD con el mismo nombre (así se ve lo que habrá después de importar).
    $turnos = array_column($turnos, null, 'nombre');
    foreach ($plan['secciones']['turnos']['filas'] ?? [] as $f) if (!$f['errores']) {
        foreach (array_keys($turnos) as $k) if (normalizar($k) === normalizar($f['nombre'])) unset($turnos[$k]);
        $turnos[$f['nombre']] = ['nombre' => $f['nombre'], 'letra' => $f['letra'] ?? '', 'departamento' => normalizar($f['departamento'] ?? '')];
    }
    $turnos = array_values($turnos);
    // Igual con las personas: las del libro mandan (su departamento puede cambiar).
    $personas = array_values(array_column($personas, null, 'email'));
    $tipos = array_column(tipos_ausencia($db), 'nombre');
    return [$personas, $turnos, $tipos];
}

// Escribe el cuadrante: turnos y descansos en la planificación; las ausencias, agrupadas en días seguidos, como aprobadas.
// Devuelve [días escritos, ausencias creadas, incumplimientos del convenio (avisos, no bloquean: es su cuadrante real)].
function aplicar_cuadrante(PDO $db, array $yo, array $r): array
{
    $hors = $tipos = [];
    foreach ($db->query('SELECT id, nombre FROM horarios') as $h) $hors[$h['nombre']] = (int)$h['id'];
    foreach (tipos_ausencia($db, false) as $id => $t) $tipos[$t['nombre']] = $id;
    [$dias, $aus, $mal] = [0, 0, 0];
    foreach ($r['asig'] as $email => $fechas) {
        $u = consulta($db, 'SELECT * FROM usuarios WHERE email = ?', [$email])->fetch();
        if (!$u) continue;
        ksort($fechas);
        $tramo = null;
        $cerrar = function () use (&$tramo, &$aus, $db, $u, $yo, $tipos) {
            if (!$tramo) return;
            [$tipo, $a, $b] = $tramo;
            $tramo = null;
            $tid = $tipos[$tipo] ?? null;
            if (!$tid || consulta($db, "SELECT 1 FROM ausencias WHERE usuario_id = ? AND tipo_id = ? AND desde = ? AND hasta = ? AND estado IN ('pendiente','aprobada')", [$u['id'], $tid, $a, $b])->fetchColumn()) return;
            consulta($db, "INSERT INTO ausencias (usuario_id, tipo_id, desde, hasta, estado, pedida_por, pedida_utc, resuelta_por, resuelta_utc, comentario) VALUES (?, ?, ?, ?, 'aprobada', ?, ?, ?, ?, 'Importada del cuadrante')",
                [$u['id'], $tid, $a, $b, $yo['id'], ahora(), $yo['id'], ahora()]);
            $aus++;
        };
        foreach ($fechas as $f => $x) {
            if ($x[0] === 'ausencia') {
                if ($tramo && $tramo[0] === $x[1] && date('Y-m-d', strtotime("{$tramo[2]} +1 day")) === $f) $tramo[2] = $f;
                else { $cerrar(); $tramo = [$x[1], $f, $f]; }
                continue;
            }
            $cerrar();
            $hid = $x[0] === 'turno' ? ($hors[$x[1]] ?? null) : null;
            if ($x[0] === 'turno' && !$hid) continue;
            consulta($db, 'REPLACE INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, ?, ?)', [$u['id'], $f, $hid]);
            $dias++;
        }
        $cerrar();
        $mal += count(array_filter(comprobar_plan($db, $u, min(array_keys($fechas)), max(array_keys($fechas))), fn($m) => $m[3] === 'bloqueo'));
    }
    auditar($db, (int)$yo['id'], 'cuadrante_importado', "dias=$dias ausencias=$aus incumplimientos=$mal");
    return [$dias, $aus, $mal];
}

// Hojas de cuadrante de un año con lo planificado (para la plantilla): una por mes.
function hojas_cuadrante(PDO $db, int $anio): array
{
    $hors = horarios($db);
    $personas = $db->query("SELECT * FROM usuarios WHERE activo = 1 AND rol <> 'inspeccion' ORDER BY nombre, apellidos")->fetchAll();
    $plan = [];
    foreach (consulta($db, 'SELECT usuario_id, fecha, horario_id FROM planificacion WHERE fecha BETWEEN ? AND ?', ["$anio-01-01", "$anio-12-31"]) as $x)
        $plan[$x['usuario_id']][$x['fecha']] = $x['horario_id'] ? ($hors[$x['horario_id']]['abreviatura'] ?: $hors[$x['horario_id']]['nombre']) : 'D';
    $codigo = function (string $tipo): string {
        foreach (CODIGOS_AUSENCIA as $c => $palabra) if (strlen($c) <= 2 && $c !== 'b' && str_contains(normalizar($tipo), $palabra)) return strtoupper($c);
        return $tipo;
    };
    foreach (consulta($db, "SELECT a.usuario_id, a.desde, a.hasta, t.nombre FROM ausencias a JOIN tipos_ausencia t ON t.id = a.tipo_id WHERE a.estado = 'aprobada' AND a.hasta >= ? AND a.desde <= ?", ["$anio-01-01", "$anio-12-31"]) as $a)
        foreach (rango_fechas(max($a['desde'], "$anio-01-01"), min($a['hasta'], "$anio-12-31")) as $f) $plan[$a['usuario_id']][$f] = $codigo($a['nombre']);
    $out = [];
    for ($m = 1; $m <= 12; $m++) {
        $n = (int)date('t', strtotime("$anio-$m-01"));
        $cab = ['Email', 'Persona'];
        for ($d = 1; $d <= $n; $d++) $cab[] = mb_substr(DIAS[(int)date('N', strtotime("$anio-$m-$d"))], 0, 1) . " $d";
        $h = [$cab];
        foreach ($personas as $u) {
            $fila = [$u['email'], nombre($u)];
            for ($d = 1; $d <= $n; $d++) $fila[] = $plan[$u['id']][sprintf('%04d-%02d-%02d', $anio, $m, $d)] ?? '';
            $h[] = $fila;
        }
        $out[ucfirst(MESES[$m]) . " $anio"] = $h;
    }
    return $out;
}
