<?php
// Libro de Excel completo: Personas, Turnos, Ratios, Festivos, Ausencias, Departamentos y Puestos en hojas.
// Se lee entero, se enseña lo que va a pasar (nada se guarda) y se aplica en orden: lo que una hoja necesita de otra
// (el turno de una persona, la persona de una ausencia) se busca después de crear lo anterior.

// Nombre de la hoja (normalizado) → sección.
const HOJAS = [
    'personas' => 'personas', 'usuarios' => 'personas', 'empleados' => 'personas', 'plantilla' => 'personas', 'trabajadores' => 'personas', 'data' => 'personas',
    'turnos' => 'turnos', 'horarios' => 'turnos',
    'ratios' => 'ratios', 'necesidades' => 'ratios', 'minimos' => 'ratios', 'presencia minima' => 'ratios',
    'festivos' => 'festivos', 'calendario laboral' => 'festivos',
    'ausencias' => 'ausencias', 'vacaciones' => 'ausencias', 'bajas' => 'ausencias', 'permisos' => 'ausencias',
    'departamentos' => 'departamentos', 'puestos' => 'puestos', 'cargos' => 'puestos',
];

// Cabeceras de cada sección (normalizadas) → campo.
const CABECERAS = [
    'turnos' => ['nombre' => 'nombre', 'turno' => 'nombre', 'horario' => 'nombre', 'letra' => 'letra', 'abreviatura' => 'letra', 'color' => 'color',
        'entrada' => 'entrada', 'hora entrada' => 'entrada', 'inicio' => 'entrada', 'salida' => 'salida', 'hora salida' => 'salida', 'fin' => 'salida',
        'horas' => 'horas', 'duracion' => 'horas', 'departamento' => 'departamento'],
    'ratios' => ['regla' => 'nombre', 'nombre' => 'nombre', 'turno' => 'turno', 'horario' => 'turno', 'puesto' => 'puesto', 'cargo' => 'puesto',
        'minimo' => 'personas', 'personas' => 'personas', 'minimo de empleados' => 'personas', 'dia' => 'dia', 'desde' => 'desde', 'fecha inicio' => 'desde',
        'hasta' => 'hasta', 'fecha fin' => 'hasta', 'festivos' => 'festivos', 'aplicable a festivos' => 'festivos', 'activa' => 'activo', 'activo' => 'activo'],
    'festivos' => ['fecha' => 'fecha', 'dia' => 'fecha', 'nombre' => 'nombre', 'festivo' => 'nombre', 'descripcion' => 'nombre', 'centro' => 'centro'],
    'ausencias' => ['email' => 'email', 'e-mail' => 'email', 'correo' => 'email', 'dni' => 'dni', 'persona' => 'persona', 'nombre' => 'persona',
        'tipo' => 'tipo', 'motivo' => 'tipo', 'desde' => 'desde', 'fecha inicio' => 'desde', 'inicio' => 'desde', 'hasta' => 'hasta', 'fecha fin' => 'hasta', 'fin' => 'hasta',
        'hora desde' => 'hora_desde', 'hora hasta' => 'hora_hasta', 'estado' => 'estado', 'comentario' => 'comentario', 'observaciones' => 'comentario'],
    'departamentos' => ['nombre' => 'nombre', 'departamento' => 'nombre'],
    'puestos' => ['nombre' => 'nombre', 'puesto' => 'nombre', 'cargo' => 'nombre'],
];

const DIAS_RATIO = ['' => 0, 'todos' => 0, 'todos los dias' => 0, 'lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7];

// Filas de una hoja como [campo => valor] según sus cabeceras (busca la fila de cabeceras en las 5 primeras).
function filas_seccion(array $hoja, array $mapa, string $obligatorio): array
{
    foreach (array_slice($hoja, 0, 5, true) as $i => $f) {
        $cols = [];
        foreach ($f as $j => $v) if (isset($mapa[normalizar($v)])) $cols[$j] ??= $mapa[normalizar($v)];
        if (!in_array($obligatorio, $cols, true)) continue;
        $out = [];
        foreach (array_slice($hoja, $i + 1, null, true) as $k => $fila) {
            $d = ['fila' => $k + 1];
            foreach ($cols as $j => $campo) $d[$campo] ??= trim($fila[$j] ?? '');
            if (array_filter(array_diff_key($d, ['fila' => 1]), fn($v) => $v !== '')) $out[] = $d;
        }
        return $out;
    }
    throw new DomainException("No encuentro la fila de cabeceras (hace falta la columna «{$obligatorio}»).");
}

// "7:00", "07:00:00" o la fracción de día de Excel (0,2916) → "07:00".
function hora_hhmm(string $v): ?string
{
    $v = trim(str_replace(',', '.', $v));
    if (is_numeric($v) && $v >= 0 && $v < 1) return sprintf('%02d:%02d', intdiv((int)round($v * 1440), 60), (int)round($v * 1440) % 60); // fracción de día de Excel
    if (preg_match('/^(\d{1,2})[:.h](\d{2})/', $v, $m) && $m[1] < 24 && $m[2] < 60) return sprintf('%02d:%02d', $m[1], $m[2]);
    if (preg_match('/^\d{1,2}$/', $v) && $v < 24) return sprintf('%02d:00', $v);
    return null;
}

function si_no(string $v, bool $defecto): bool
{
    $v = normalizar($v);
    if ($v === '') return $defecto;
    return in_array($v, ['si', 'sí', 'yes', 'x', '1', 'true', 'verdadero', 'activa', 'activo'], true);
}

// Lee el libro y prepara cada sección. Nada se guarda. ['secciones' => [...], 'avisos' => [...]].
function preparar_libro(PDO $db, array $libro): array
{
    $plan = ['secciones' => [], 'hojas' => [], 'ignoradas' => []];
    $cuadrantes = [];
    foreach ($libro as $nombre => $hoja) {
        $sec = HOJAS[normalizar($nombre)] ?? null;
        if (!$sec && es_hoja_cuadrante((string)$nombre)) { $cuadrantes[$nombre] = $hoja; continue; }
        // Una hoja suelta con columna Email (p. ej. la exportación de Woffu o un CSV): personas.
        if (!$sec && count($libro) === 1) $sec = leer_cuadrantes([$nombre => $hoja]) ? null : 'personas';
        if (!$sec && count($libro) === 1) { $cuadrantes[$nombre] = $hoja; continue; }
        if (!$sec || isset($plan['secciones'][$sec])) {
            if (trim($nombre) !== '' && normalizar($nombre) !== 'instrucciones') $plan['ignoradas'][] = $nombre;
            continue;
        }
        try {
            $plan['secciones'][$sec] = $sec === 'personas' ? ['filas' => filas_importables($hoja)[0]]
                : ['filas' => filas_seccion($hoja, CABECERAS[$sec], $sec === 'ausencias' ? 'tipo' : (in_array($sec, ['festivos'], true) ? 'fecha' : ($sec === 'ratios' ? 'turno' : 'nombre')))];
            $plan['hojas'][$sec] = $nombre;
        } catch (DomainException $e) {
            $plan['secciones'][$sec] = ['filas' => [], 'error' => "Hoja «{$nombre}»: " . $e->getMessage()];
        }
    }
    if ($cuadrantes) $plan['secciones']['cuadrante'] = ['filas' => [], 'celdas' => leer_cuadrantes($cuadrantes)];
    if (!$plan['secciones']) throw new DomainException('No reconozco ninguna hoja. Llámalas Personas, Turnos, Ratios, Festivos, Ausencias, Departamentos o Puestos (descarga la plantilla).');
    // Nombres que existirán tras importar (en la BD o en el propio libro), para avisar antes de lo que no cuadra.
    $existen = fn(string $sql, array $extra) => array_merge(array_map('normalizar', $db->query($sql)->fetchAll(PDO::FETCH_COLUMN)), array_map('normalizar', $extra));
    $turnos = $existen('SELECT nombre FROM horarios', array_column($plan['secciones']['turnos']['filas'] ?? [], 'nombre'));
    $puestos = $existen('SELECT nombre FROM puestos', array_merge(array_column($plan['secciones']['puestos']['filas'] ?? [], 'nombre'), array_filter(array_merge([], ...array_map(fn($x) => array_map('trim', explode(';', (string)$x)), array_column($plan['secciones']['personas']['filas'] ?? [], 'puesto'))))));
    $tipos = $existen('SELECT nombre FROM tipos_ausencia', []);
    $emails = $existen('SELECT email FROM usuarios', array_column($plan['secciones']['personas']['filas'] ?? [], 'email'));
    foreach ($plan['secciones'] as $sec => &$s) {
        foreach ($s['filas'] as &$f) {
            $e = [];
            if ($sec === 'turnos') {
                if (!hora_hhmm($f['entrada'] ?? '') || !hora_hhmm($f['salida'] ?? '')) $e[] = 'falta la hora de entrada o de salida';
            } elseif ($sec === 'ratios') {
                if (!in_array(normalizar($f['turno'] ?? ''), $turnos, true)) $e[] = 'el turno no existe';
                foreach (array_filter(array_map('trim', explode(';', (string)($f['puesto'] ?? '')))) as $x) if (!in_array(normalizar($x), $puestos, true)) $e[] = "el puesto «{$x}» no existe";
                if ((int)($f['personas'] ?? 0) < 1) $e[] = 'pon el mínimo de personas';
                if (!array_key_exists(normalizar($f['dia'] ?? ''), DIAS_RATIO)) $e[] = 'día no válido (Todos, Lunes…Domingo)';
            } elseif ($sec === 'festivos') {
                if (!fecha_iso($f['fecha'] ?? '')) $e[] = 'fecha no válida';
            } elseif ($sec === 'ausencias') {
                if (!fecha_iso($f['desde'] ?? '')) $e[] = 'fecha de inicio no válida';
                if (($f['hasta'] ?? '') !== '' && !fecha_iso($f['hasta'])) $e[] = 'fecha de fin no válida';
                if (!in_array(normalizar($f['tipo'] ?? ''), $tipos, true)) $e[] = 'tipo de ausencia desconocido';
                if (($f['email'] ?? '') === '' && ($f['dni'] ?? '') === '') $e[] = 'pon el email o el DNI de la persona';
                elseif (($f['email'] ?? '') !== '' && !in_array(normalizar($f['email']), $emails, true)) $e[] = 'no hay nadie con ese email';
            }
            $f['errores'] = $e;
        }
        unset($f);
        if ($sec === 'personas' && $s['filas']) $s['previa'] = preparar_importacion($db, $s['filas']);
    }
    unset($s);
    // El cuadrante, al final: puede usar personas y turnos que vienen en el mismo libro.
    if (isset($plan['secciones']['cuadrante'])) $plan['secciones']['cuadrante']['resumen'] = interpretar_cuadrante($plan['secciones']['cuadrante']['celdas'], ...listas_cuadrante($db, $plan));
    return $plan;
}

// Aplica el libro en orden. Devuelve [sección => texto del resultado].
function aplicar_libro(PDO $db, array $yo, array $plan, bool $actualizar): array
{
    $s = $plan['secciones'];
    $ok = fn($sec) => array_values(array_filter($s[$sec]['filas'] ?? [], fn($f) => empty($f['errores'])));
    $res = [];
    foreach (['departamentos' => 'departamentos', 'puestos' => 'puestos'] as $sec => $tabla) {
        if (!isset($s[$sec])) continue;
        $n = 0;
        foreach ($ok($sec) as $f) {
            if ($f['nombre'] === '' || consulta($db, "SELECT 1 FROM $tabla WHERE nombre = ?", [$f['nombre']])->fetchColumn()) continue;
            consulta($db, "INSERT INTO $tabla (nombre) VALUES (?)", [mb_substr($f['nombre'], 0, 80)]);
            $n++;
        }
        $res[$sec] = "$n nuevos";
    }
    if (isset($s['turnos'])) {
        [$c, $a] = guardar_turnos($db, array_map(fn($f) => [$f['nombre'], $f['letra'] ?? '', $f['color'] ?? '', hora_hhmm($f['entrada']), hora_hhmm($f['salida']),
            ($f['horas'] ?? '') !== '' ? (hora_hhmm($f['horas']) ?? horas_desde_numero($f['horas'])) : duracion(hora_hhmm($f['entrada']), hora_hhmm($f['salida'])), $f['departamento'] ?? ''], $ok('turnos')));
        $res['turnos'] = "$c nuevos, $a actualizados";
    }
    if (isset($s['personas']) && $s['personas']['filas']) {
        // Se vuelve a preparar ahora: los turnos y puestos del libro ya existen.
        $r = aplicar_importacion($db, preparar_importacion($db, $s['personas']['filas']), $actualizar);
        $res['personas'] = count($r['nuevos']) . " nuevas, {$r['actualizados']} actualizadas";
        $res['_nuevos'] = $r['nuevos'];
    }
    if (isset($s['ratios'])) {
        $hors = $pts = [];
        foreach ($db->query('SELECT id, nombre FROM horarios') as $h) $hors[normalizar($h['nombre'])] = (int)$h['id'];
        foreach ($db->query('SELECT id, nombre FROM puestos') as $p) $pts[normalizar($p['nombre'])] = (int)$p['id'];
        [$c, $a] = [0, 0];
        foreach ($ok('ratios') as $f) {
            $hid = $hors[normalizar($f['turno'])] ?? null;
            if (!$hid) continue;
            $d = [mb_substr($f['nombre'] ?? '', 0, 100), $hid, ($f['puesto'] ?? '') !== '' ? ($pts[normalizar($f['puesto'])] ?? null) : null, DIAS_RATIO[normalizar($f['dia'] ?? '')],
                min(99, (int)$f['personas']), fecha_iso($f['desde'] ?? ''), fecha_iso($f['hasta'] ?? ''), (int)si_no($f['festivos'] ?? '', true), (int)si_no($f['activo'] ?? '', true)];
            $id = $d[0] !== '' ? consulta($db, 'SELECT id FROM necesidades WHERE nombre = ?', [$d[0]])->fetchColumn() : false;
            if ($id) {
                consulta($db, 'UPDATE necesidades SET nombre = ?, horario_id = ?, puesto_id = ?, dia = ?, personas = ?, desde = ?, hasta = ?, festivos = ?, activo = ? WHERE id = ?', [...$d, $id]);
                $a++;
            } else {
                consulta($db, 'INSERT INTO necesidades (nombre, horario_id, puesto_id, dia, personas, desde, hasta, festivos, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', $d);
                $c++;
            }
        }
        $res['ratios'] = "$c nuevos, $a actualizados";
    }
    if (isset($s['festivos'])) {
        $centros = [];
        foreach ($db->query('SELECT id, nombre FROM centros') as $c) $centros[normalizar($c['nombre'])] = (int)$c['id'];
        $n = 0;
        foreach ($ok('festivos') as $f) {
            $fecha = fecha_iso($f['fecha']);
            $centro = ($f['centro'] ?? '') !== '' ? ($centros[normalizar($f['centro'])] ?? null) : null;
            if (consulta($db, 'SELECT 1 FROM festivos WHERE fecha = ? AND centro_id <=> ?', [$fecha, $centro])->fetchColumn()) continue;
            consulta($db, 'INSERT INTO festivos (centro_id, fecha, nombre) VALUES (?, ?, ?)', [$centro, $fecha, mb_substr($f['nombre'] ?: 'Festivo', 0, 100)]);
            $n++;
        }
        $res['festivos'] = "$n nuevos";
    }
    if (isset($s['ausencias'])) {
        $tipos = [];
        foreach (tipos_ausencia($db, false) as $id => $t) $tipos[normalizar($t['nombre'])] = $id;
        [$n, $mal] = [0, 0];
        foreach ($ok('ausencias') as $f) {
            $u = ($f['email'] ?? '') !== '' ? consulta($db, 'SELECT * FROM usuarios WHERE email = ?', [mb_strtolower($f['email'])])->fetch()
                : consulta($db, 'SELECT * FROM usuarios WHERE dni = ?', [strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $f['dni']))])->fetch();
            $desde = fecha_iso($f['desde']);
            $hasta = fecha_iso($f['hasta'] ?? '') ?: $desde;
            $tid = $tipos[normalizar($f['tipo'])] ?? null;
            if (!$u || !$tid || $hasta < $desde) { $mal++; continue; }
            // La misma ausencia ya importada no se repite.
            if (consulta($db, "SELECT 1 FROM ausencias WHERE usuario_id = ? AND tipo_id = ? AND desde = ? AND hasta = ? AND estado IN ('pendiente','aprobada')", [$u['id'], $tid, $desde, $hasta])->fetchColumn()) continue;
            $aprobada = !in_array(normalizar($f['estado'] ?? ''), ['pendiente', 'solicitada'], true);
            consulta($db, 'INSERT INTO ausencias (usuario_id, tipo_id, desde, hasta, hora_desde, hora_hasta, motivo, estado, pedida_por, pedida_utc, resuelta_por, resuelta_utc, comentario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$u['id'], $tid, $desde, $hasta, ($h = hora_hhmm($f['hora_desde'] ?? '')) ? "$h:00" : null, ($h = hora_hhmm($f['hora_hasta'] ?? '')) ? "$h:00" : null,
                    mb_substr($f['comentario'] ?? '', 0, 500), $aprobada ? 'aprobada' : 'pendiente', $yo['id'], ahora(), $aprobada ? $yo['id'] : null, $aprobada ? ahora() : null, 'Importada desde Excel']);
            $n++;
        }
        $res['ausencias'] = "$n nuevas" . ($mal ? ", $mal no encontradas" : '');
    }
    $convenio = [];
    if (!empty($s['cuadrante']['celdas'])) {
        $r = interpretar_cuadrante($s['cuadrante']['celdas'], ...listas_cuadrante($db));
        [$d, $a, $convenio] = aplicar_cuadrante($db, $yo, $r);
        $res['cuadrante'] = "$d días de turno o descanso, $a ausencias" . ($r['crear_tipos'] ? ' (creados los tipos ' . implode(', ', $r['crear_tipos']) . ')' : '')
            . ($convenio ? ' · ' . count($convenio) . ' incumplimientos del convenio (en el informe)' : '');
    }
    auditar($db, (int)$yo['id'], 'importacion_libro', json_encode(array_diff_key($res, ['_nuevos' => 1]), JSON_UNESCAPED_UNICODE));
    // Informe para administración: lo que no entró y lo que entró pero incumple el convenio.
    $informe = informe_libro($plan);
    foreach ($convenio as [$quien, $f, $txt]) $informe[] = ['Cuadrante', '', '', $quien, $f, '', 'Importado, pero incumple el convenio: ' . $txt];
    consulta($db, 'REPLACE INTO ajustes VALUES (?, ?)', ['informe_importacion', json_encode(['fecha' => ahora(), 'archivo' => $plan['archivo'] ?? '', 'resumen' => array_diff_key($res, ['_nuevos' => 1]), 'filas' => array_slice($informe, 0, 3000)], JSON_UNESCAPED_UNICODE)]);
    return $res;
}

// Todo lo que no se puede importar, fila a fila: [sección, hoja, fila, persona, día, valor, motivo].
function informe_libro(array $plan): array
{
    $nombres = ['personas' => 'Personas', 'turnos' => 'Turnos', 'ratios' => 'Ratios', 'festivos' => 'Festivos', 'ausencias' => 'Ausencias', 'departamentos' => 'Departamentos', 'puestos' => 'Puestos'];
    $out = [];
    foreach ($plan['secciones'] as $sec => $s) {
        if (!empty($s['error'])) $out[] = [$nombres[$sec] ?? 'Cuadrante', $plan['hojas'][$sec] ?? '', '', '', '', '', $s['error']];
        if ($sec === 'cuadrante') {
            foreach ($s['resumen']['problemas'] ?? [] as [$hoja, $fila, $quien, $f, $v, $motivo]) $out[] = ['Cuadrante', $hoja, $fila, $quien, $f, $v, $motivo];
            continue;
        }
        if ($sec === 'personas') {
            foreach ($s['previa']['filas'] ?? [] as $f) if ($f['estado'] === 'error') $out[] = ['Personas', $plan['hojas'][$sec] ?? '', $f['fila'], trim($f['nombre'] . ' ' . $f['apellidos']) ?: $f['email'], '', $f['email'], implode(', ', $f['errores'])];
            foreach ($s['previa']['horarios_desconocidos'] ?? [] as $h) if (!in_array(normalizar($h), array_map('normalizar', array_column($plan['secciones']['turnos']['filas'] ?? [], 'nombre')), true)) $out[] = ['Personas', $plan['hojas'][$sec] ?? '', '', '', '', $h, 'Horario que no existe ni viene en la hoja Turnos: esas personas quedan sin horario'];
            foreach ($s['previa']['responsables_desconocidos'] ?? [] as $h) $out[] = ['Personas', $plan['hojas'][$sec] ?? '', '', '', '', $h, 'Responsable que no encuentro: quedan sin responsable'];
            continue;
        }
        foreach ($s['filas'] as $f) if (!empty($f['errores'])) $out[] = [$nombres[$sec], $plan['hojas'][$sec] ?? '', $f['fila'], $f['email'] ?? '', fecha_iso($f['desde'] ?? $f['fecha'] ?? '') ?? '', $f['nombre'] ?? $f['turno'] ?? $f['tipo'] ?? '', implode(', ', $f['errores'])];
    }
    return $out;
}

function duracion(?string $e, ?string $s): string
{
    if (!$e || !$s) return '';
    $m = ((strtotime("2000-01-01 $s") - strtotime("2000-01-01 $e")) + 86400) % 86400 / 60;
    return sprintf('%d:%02d', intdiv((int)$m, 60), (int)$m % 60);
}

// "8" o "7,5" horas → "8:00" / "7:30".
function horas_desde_numero(string $v): string
{
    $h = (float)str_replace(',', '.', $v);
    return sprintf('%d:%02d', (int)$h, (int)round(($h - (int)$h) * 60));
}

// ---------- Plantilla descargable con los datos actuales ----------

function hojas_actuales(PDO $db): array
{
    $hors = horarios($db);
    $deps = $db->query('SELECT id, nombre FROM departamentos ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
    $pts = $db->query('SELECT id, nombre FROM puestos ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
    $centros = $db->query('SELECT id, nombre FROM centros ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
    $pp = puestos_por_persona($db);
    $todos = $db->query("SELECT * FROM usuarios WHERE rol <> 'inspeccion' ORDER BY nombre, apellidos")->fetchAll();
    $por_id = array_column($todos, null, 'id');
    $hhmm = fn($t) => $t ? substr($t, 0, 5) : '';
    $roles = ['empleado' => 'Empleado', 'responsable' => 'Responsable', 'admin' => 'Admin'];
    $h = ['Instrucciones' => [['Plantilla de INDA'], [''],
        ['Rellena las hojas que quieras y súbela en Usuarios → Importar desde Excel. Las hojas vacías o que no uses se ignoran.'],
        ['Personas: una fila por persona. El email es obligatorio y sirve para reconocerla (si ya existe, se actualiza).'],
        ['Turnos: nombre, letra para el calendario (M, T, N…), color (#rrggbb), entrada y salida (07:00), horas y departamento.'],
        ['Ratios: mínimo de personas por turno. Día: Todos o Lunes…Domingo. Festivos: Sí/No.'],
        ['Festivos: fecha (dd/mm/aaaa) y nombre. Centro solo para festivos locales.'],
        ['Ausencias: vacaciones, bajas o permisos ya concedidos. Email (o DNI) de la persona, tipo tal como se llama en INDA, desde y hasta. Estado: Aprobada (por defecto) o Pendiente.'],
        ['Cuadrante (opcional): una hoja por mes llamada «Enero 2027», «Febrero 2027»… Fila = persona (email o nombre); columna = día. En cada casilla: la letra o el nombre del turno (M, T, N, P…), D = descanso, o la ausencia: V vacaciones, IT baja, LD libre disposición, AP asuntos propios (o el nombre del tipo). Vacía = su horario habitual.'],
        ['Tipos de ausencia que existen ahora: ' . implode(', ', array_column(tipos_ausencia($db), 'nombre'))],
    ]];
    $h['Personas'] = [['Nombre', 'Apellidos', 'Email', 'DNI', 'Teléfono', 'Nº Seguridad Social', 'Fecha de nacimiento', 'Nº de empleado', 'Centro', 'Departamento', 'Puesto', 'Horario', 'Responsable', 'Rol', 'Usuario suspendido']];
    foreach ($todos as $u) $h['Personas'][] = [$u['nombre'], $u['apellidos'], $u['email'], $u['dni'], $u['telefono'] ?? '', $u['nss'] ?? '',
        !empty($u['fecha_nacimiento']) ? date('d/m/Y', strtotime($u['fecha_nacimiento'])) : '', $u['num_empleado'] ?? '', $centros[$u['centro_id']] ?? '', $deps[$u['departamento_id']] ?? '',
        $pts[($pp[$u['id']] ?? [0])[0]] ?? '', $hors[$u['horario_id']]['nombre'] ?? '', isset($por_id[$u['responsable_id']]) ? nombre($por_id[$u['responsable_id']]) : '', $roles[$u['rol']] ?? 'Empleado', $u['activo'] ? 'No' : 'Si'];
    $h['Turnos'] = [['Nombre', 'Letra', 'Color', 'Entrada', 'Salida', 'Horas', 'Departamento']];
    foreach ($hors as $x) if ($x['activo'] && !$x['flexible'] && ($d = reset($x['dias']) ?: null)) $h['Turnos'][] = [$x['nombre'], $x['abreviatura'], $x['color'], $hhmm($d['entrada']), $hhmm($d['salida']), horas($d['minutos'] * 60), $deps[$x['departamento_id']] ?? ''];
    if (count($h['Turnos']) === 1) foreach (TURNOS_RESIDENCIA as $t) $h['Turnos'][] = $t;
    $h['Ratios'] = [['Regla', 'Turno', 'Puesto', 'Mínimo', 'Día', 'Desde', 'Hasta', 'Festivos', 'Activa']];
    foreach ($db->query('SELECT * FROM necesidades ORDER BY id') as $n) $h['Ratios'][] = [$n['nombre'], $hors[$n['horario_id']]['nombre'] ?? '', $pts[$n['puesto_id']] ?? '', $n['personas'],
        ucfirst(['Todos', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'][$n['dia']]), $n['desde'] ? date('d/m/Y', strtotime($n['desde'])) : '', $n['hasta'] ? date('d/m/Y', strtotime($n['hasta'])) : '', $n['festivos'] ? 'Sí' : 'No', $n['activo'] ? 'Sí' : 'No'];
    // Sin ratios todavía: los de la residencia como ejemplo, solo para turnos que vienen en la hoja Turnos.
    $en_turnos = array_map('normalizar', array_column(array_slice($h['Turnos'], 1), 0));
    if (count($h['Ratios']) === 1) foreach (RATIOS_RESIDENCIA as $nom => [$t, $min]) if (in_array(normalizar($t), $en_turnos, true)) $h['Ratios'][] = [$nom, $t, '', $min, 'Todos', '', '', 'Sí', 'Sí'];
    $h['Festivos'] = [['Fecha', 'Nombre', 'Centro']];
    foreach ($db->query('SELECT * FROM festivos WHERE fecha >= CURDATE() - INTERVAL 1 YEAR ORDER BY fecha') as $f) $h['Festivos'][] = [date('d/m/Y', strtotime($f['fecha'])), $f['nombre'], $centros[$f['centro_id']] ?? ''];
    $h['Ausencias'] = [['Email', 'DNI', 'Tipo', 'Desde', 'Hasta', 'Hora desde', 'Hora hasta', 'Estado', 'Comentario']];
    $h['Departamentos'] = [['Nombre'], ...array_map(fn($n) => [$n], array_values($deps))];
    $h['Puestos'] = [['Nombre'], ...array_map(fn($n) => [$n], array_values($pts))];
    return $h;
}

// Escribe un .xlsx sencillo (texto en línea, primera fila en negrita) sin librerías. Devuelve el contenido binario.
function escribir_xlsx(array $hojas): string
{
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = function (int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; };
    $ruta = tempnam(sys_get_temp_dir(), 'inda');
    $z = new ZipArchive();
    $z->open($ruta, ZipArchive::OVERWRITE);
    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $libro = $rels = $tipos = '';
    $i = 0;
    foreach ($hojas as $nombre => $filas) {
        $i++;
        $xml = '';
        foreach (array_values($filas) as $r => $fila) {
            $xml .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($fila) as $c => $v) if ((string)$v !== '') $xml .= '<c r="' . $col($c) . ($r + 1) . '" t="inlineStr"' . ($r === 0 && $nombre !== 'Instrucciones' ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $esc($v) . '</t></is></c>';
            $xml .= '</row>';
        }
        $z->addFromString("xl/worksheets/sheet$i.xml", "<?xml version=\"1.0\" encoding=\"UTF-8\"?><worksheet $ns><sheetData>$xml</sheetData></worksheet>");
        $libro .= '<sheet name="' . $esc(mb_substr($nombre, 0, 31)) . "\" sheetId=\"$i\" r:id=\"rId$i\"/>";
        $rels .= "<Relationship Id=\"rId$i\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$i.xml\"/>";
        $tipos .= "<Override PartName=\"/xl/worksheets/sheet$i.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
    }
    $rels .= '<Relationship Id="rIdE" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $tipos . '</Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><workbook $ns><sheets>$libro</sheets></workbook>");
    $z->addFromString('xl/_rels/workbook.xml.rels', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">$rels</Relationships>");
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $z->close();
    $bin = (string)file_get_contents($ruta);
    unlink($ruta);
    return $bin;
}
