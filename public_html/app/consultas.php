<?php
// Asistente para administración (Gemini con llamadas a funciones). Gemini NO accede a la base de datos: elige una de estas
// consultas de solo lectura, INDA la ejecuta y Gemini redacta la respuesta con esos datos. Solo se envían nombres y cifras
// (nunca DNI, emails ni teléfonos). Clave en config-inda.php ('gemini_clave').

const CONSULTAS_IA = [
    'incidencias_fichaje' => ['Incidencias de fichaje en un periodo: sin fichar la entrada con turno, entradas con retraso, jornadas sin salida, salidas cerradas automáticamente y días trabajados sin turno.', ['desde', 'hasta', 'persona']],
    'dias_trabajados' => ['Quién trabajó (según sus fichajes) en un periodo, día a día. Con dia_semana (1 = lunes … 7 = domingo) solo esos días; con todos = true, solo quien trabajó TODOS esos días.', ['desde', 'hasta', 'dia_semana', 'todos', 'persona']],
    'horas_trabajadas' => ['Horas por persona en un periodo: teóricas (cuadrante), trabajadas (fichadas), extra, nocturnas y diferencia.', ['desde', 'hasta', 'persona']],
    'ausencias' => ['Vacaciones, bajas y permisos aprobados o pendientes en un periodo.', ['desde', 'hasta', 'persona']],
    'ratios' => ['Días y turnos que no cumplen el ratio exacto (faltan o sobran personas) en un periodo.', ['desde', 'hasta']],
    'propuesta_cubrir' => ['PROPUESTA (no se aplica): qué turnos habría que añadir o cambiar, y a quién, para cumplir el ratio exacto en un periodo (p. ej. tras vacaciones o bajas), manteniendo el cuadrante del resto y con las reglas del convenio. Para aplicarla: Planificación → «Bajas, vacaciones y ausencias entre fechas» o generar propuesta.', ['desde', 'hasta']],
    'reparto_turnos' => ['Turnos PLANIFICADOS por persona en un periodo (cuadrante, no fichajes): número de turnos, horas, noches y fines de semana, para ver desequilibrios y proponer un reparto más justo.', ['desde', 'hasta', 'persona']],
    'trabajando_ahora' => ['Quién está trabajando o en pausa ahora mismo.', []],
    'solicitudes' => ['Solicitudes pendientes de decidir: cambios de turno, ausencias, correcciones, justificantes sin subir.', []],
];
const PARAMS_IA = [
    'desde' => ['type' => 'STRING', 'description' => 'Fecha inicial AAAA-MM-DD'],
    'hasta' => ['type' => 'STRING', 'description' => 'Fecha final AAAA-MM-DD (incluida)'],
    'persona' => ['type' => 'STRING', 'description' => 'Parte del nombre de una persona (opcional)'],
    'dia_semana' => ['type' => 'INTEGER', 'description' => '1 = lunes … 7 = domingo (opcional)'],
    'todos' => ['type' => 'BOOLEAN', 'description' => 'Solo quien trabajó todos los días pedidos (opcional)'],
];

function herramientas_ia(): array
{
    $f = [];
    foreach (CONSULTAS_IA as $n => [$d, $ps]) {
        $props = array_intersect_key(PARAMS_IA, array_flip($ps));
        $f[] = ['name' => $n, 'description' => $d] + ($props ? ['parameters' => ['type' => 'OBJECT', 'properties' => $props, 'required' => array_values(array_intersect(['desde', 'hasta'], $ps))]] : []);
    }
    return [['functionDeclarations' => $f]];
}

// Ejecuta una consulta con los permisos de $yo. Devuelve datos sencillos (para Gemini) y nunca lanza: los errores van en 'error'.
function ejecutar_consulta(PDO $db, array $yo, string $nombre, array $a): array
{
    try {
        $hoy = local(ahora(), 'Y-m-d');
        $fecha = fn($k, $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($a[$k] ?? '')) ? $a[$k] : $def;
        $desde = $fecha('desde', $hoy);
        $hasta = max($desde, $fecha('hasta', $desde));
        if (strtotime($hasta) - strtotime($desde) > 92 * 86400) return ['error' => 'Periodo demasiado largo: como mucho 3 meses por consulta.'];
        $q = normalizar((string)($a['persona'] ?? ''));
        $gente = array_values(array_filter(personas_visibles($db, $yo), fn($p) => $p['activo'] && $p['rol'] !== 'inspeccion' && ($q === '' || str_contains(normalizar(nombre($p)), $q))));
        $h = fn(int $s) => horas($s);
        switch ($nombre) {
            case 'incidencias_fichaje':
                $out = [];
                foreach (rango_fechas($desde, min($hasta, $hoy)) as $f) foreach (presencia_dia($db, $gente, $f) as $x) {
                    $p = nombre($x['persona']);
                    if ($x['teorico'] > 0 && !$x['entrada'] && $f < $hoy) $out[] = [$f, $p, 'no fichó la entrada (tenía ' . $x['jornada'] . ')'];
                    if ($x['entrada'] && $x['horario_entrada'] && local($x['entrada'], 'H:i') > substr($x['horario_entrada'], 0, 5)) {
                        $tarde = (strtotime(local($x['entrada'], 'Y-m-d H:i')) - strtotime("$f " . substr($x['horario_entrada'], 0, 5))) / 60;
                        if ($tarde > 10) $out[] = [$f, $p, 'entró ' . (int)$tarde . ' min tarde (' . local($x['entrada'], 'H:i') . ' y su turno era a las ' . substr($x['horario_entrada'], 0, 5) . ')'];
                    }
                    if ($x['abierta'] && $f < $hoy) $out[] = [$f, $p, 'no fichó la salida'];
                    if ($x['entrada'] && !$x['teorico'] && !$x['ausencia']) $out[] = [$f, $p, 'trabajó ' . $h($x['trabajado']) . ' h sin turno ese día'];
                }
                foreach (consulta($db, "SELECT usuario_id, momento_utc FROM fichajes WHERE origen = 'automatico' AND tipo = 'salida' AND momento_utc BETWEEN ? AND ?", [a_utc("$desde 00:00:00"), a_utc(date('Y-m-d', strtotime("$hasta +1 day")) . ' 00:00:00')]) as $r) {
                    foreach ($gente as $p) if ($p['id'] == $r['usuario_id']) $out[] = [local($r['momento_utc'], 'Y-m-d'), nombre($p), 'salida cerrada automáticamente a las ' . local($r['momento_utc'], 'H:i') . ' (no fichó al salir)'];
                }
                usort($out, fn($x, $y) => $x <=> $y);
                return ['periodo' => "$desde a $hasta", 'incidencias' => array_map(fn($x) => ['fecha' => $x[0], 'persona' => $x[1], 'que' => $x[2]], array_slice($out, 0, 300)), 'total' => count($out)];
            case 'dias_trabajados':
                $dw = (int)($a['dia_semana'] ?? 0);
                $dias = array_values(array_filter(rango_fechas($desde, min($hasta, $hoy)), fn($f) => !$dw || (int)date('N', strtotime($f)) === $dw));
                $por = [];
                foreach ($dias as $f) foreach (presencia_dia($db, $gente, $f) as $x) if ($x['entrada']) $por[nombre($x['persona'])][] = $f;
                if (!empty($a['todos'])) $por = array_filter($por, fn($l) => count($l) === count($dias));
                ksort($por);
                return ['dias_consultados' => $dias, 'personas' => array_map(fn($n, $l) => ['persona' => $n, 'dias' => $l, 'n' => count($l)], array_keys($por), $por)];
            case 'horas_trabajadas':
                return ['periodo' => "$desde a $hasta", 'personas' => array_map(function ($p) use ($db, $desde, $hasta, $h) {
                    $t = resumen($db, $p, $desde, $hasta)['total'];
                    return ['persona' => nombre($p), 'teoricas' => $h($t['teorico']), 'trabajadas' => $h($t['trabajado']), 'extra' => $h($t['extra']), 'nocturnas' => $h($t['nocturno']), 'diferencia' => horas_signo($t['diferencia'])];
                }, $gente)];
            case 'ausencias':
                $ids = array_column($gente, null, 'id');
                $r = consulta($db, "SELECT a.usuario_id, a.desde, a.hasta, a.estado, a.hora_desde, a.hora_hasta, t.nombre AS tipo FROM ausencias a JOIN tipos_ausencia t ON t.id = a.tipo_id
                    WHERE a.estado IN ('aprobada','pendiente') AND a.desde <= ? AND a.hasta >= ? ORDER BY a.desde", [$hasta, $desde])->fetchAll();
                return ['ausencias' => array_values(array_map(fn($x) => ['persona' => nombre($ids[$x['usuario_id']]), 'tipo' => $x['tipo'], 'desde' => $x['desde'], 'hasta' => $x['hasta'], 'estado' => $x['estado']]
                    + ($x['hora_desde'] ? ['horas' => substr($x['hora_desde'], 0, 5) . '-' . substr($x['hora_hasta'], 0, 5)] : []), array_filter($r, fn($x) => isset($ids[$x['usuario_id']]))))];
            case 'ratios':
                $hn = array_column(horarios($db), 'nombre', 'id');
                $todos = $db->query("SELECT * FROM usuarios WHERE activo = 1 AND rol <> 'inspeccion'")->fetchAll();
                return ['desvios' => array_map(fn($x) => ['fecha' => $x[0], 'turno' => $hn[$x[1]] ?? '', 'que' => $x[3] > 0 ? 'faltan ' . $x[3] : 'sobran ' . -$x[3]], desvios($db, $todos, $desde, $hasta))];
            case 'propuesta_cubrir':
                if (strtotime($hasta) - strtotime($desde) > 31 * 86400) return ['error' => 'Para propuestas, como mucho un mes por consulta.'];
                $hn = array_column(horarios($db), 'nombre', 'id');
                $todos = $db->query("SELECT * FROM usuarios WHERE activo = 1 AND rol IN ('empleado','responsable','admin')")->fetchAll();
                $nom = array_column(array_map(fn($p) => ['id' => $p['id'], 'n' => nombre($p)], $todos), 'n', 'id');
                $r = planificar($db, $todos, max($desde, $hoy), $hasta, ['mantener' => true]);
                return ['aviso' => 'Es solo una propuesta: no se ha cambiado nada.',
                    'cambios_propuestos' => array_map(fn($x) => ['persona' => $nom[$x[0]] ?? '', 'fecha' => $x[1], 'turno' => $hn[$x[2]] ?? ''], $r['nuevos']),
                    'sin_cubrir' => array_map(fn($x) => ['fecha' => $x[0], 'turno' => $hn[$x[1]] ?? '', 'faltan' => $x[3], 'motivo' => $x[4]], $r['faltan'])];
            case 'reparto_turnos':
                return ['periodo' => "$desde a $hasta", 'personas' => array_map(function ($p) use ($db, $desde, $hasta, $h) {
                    $n = $min = $noches = $findes = 0;
                    foreach (calendario_teorico($db, $p, $desde, $hasta) as $f => $c) {
                        if (!$c['turno']) continue;
                        $n++;
                        $min += $c['turno'];
                        if ($c['entrada'] && ((int)substr($c['entrada'], 0, 2) >= 20 || (int)substr((string)$c['salida'], 0, 2) <= 7 && $c['salida'] < $c['entrada'])) $noches++;
                        if ((int)date('N', strtotime($f)) >= 6) $findes++;
                    }
                    return ['persona' => nombre($p), 'turnos' => $n, 'horas' => $h($min * 60), 'noches' => $noches, 'fines_de_semana' => $findes,
                        'preferencias' => array_map(fn($x) => texto_preferencia($x, horarios($db)), preferencias_activas($db, [(int)$p['id']])[$p['id']] ?? [])];
                }, $gente)];
            case 'trabajando_ahora':
                $out = [];
                foreach ($gente as $p) if (($e = estado_actual($db, (int)$p['id'])) !== 'fuera') $out[] = ['persona' => nombre($p), 'estado' => $e === 'pausa' ? 'en pausa' : 'trabajando'];
                return ['ahora' => local(ahora(), 'd/m/Y H:i'), 'personas' => $out];
            case 'solicitudes':
                $s = solicitudes_pendientes($db, $yo);
                return ['cambios_de_turno' => array_map(fn($c) => ['pide' => nombre(usuario($db, (int)$c['solicitante_id'])), 'con' => nombre(usuario($db, (int)$c['companero_id'])), 'dias' => fechas_cambio($c), 'estado' => ESTADOS_CAMBIO[$c['estado']]], $s['cambios']),
                    'ausencias' => array_map(fn($x) => ['persona' => nombre($x), 'tipo' => $x['tipo'], 'desde' => $x['desde'], 'hasta' => $x['hasta']], $s['ausencias']),
                    'correcciones' => array_map(fn($x) => ['persona' => nombre($x), 'pedida' => local($x['pedida_utc'], 'd/m H:i')], $s['correcciones']),
                    'justificantes_sin_subir' => array_map(fn($x) => ['persona' => nombre(usuario($db, (int)$x['usuario_id'])), 'pausa' => $x['motivo'], 'fecha' => local($x['momento_utc'], 'd/m')], $s['justificantes'])];
        }
        return ['error' => 'Consulta desconocida.'];
    } catch (Throwable $e) {
        return ['error' => 'No se pudo hacer la consulta: ' . $e->getMessage()];
    }
}

// Llamada a Gemini (generateContent). Se puede sustituir en las pruebas.
function gemini_llamar(array $cuerpo): array
{
    $c = config();
    if (empty($c['gemini_clave'])) throw new DomainException('Falta la clave de Gemini en config-inda.php («gemini_clave»).');
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($c['gemini_modelo'] ?? 'gemini-flash-latest') . ':generateContent');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $c['gemini_clave']], CURLOPT_POSTFIELDS => json_encode($cuerpo)]);
    $txt = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($txt === false) throw new DomainException("No se pudo conectar con Gemini ($err).");
    $r = json_decode($txt, true) ?: [];
    if (isset($r['error'])) throw new DomainException('Gemini: ' . ($r['error']['message'] ?? 'error'));
    return $r;
}

// Pregunta del administrador → respuesta. $historial: [['user'|'model', texto], …] (los últimos turnos).
// Devuelve [respuesta, consultas hechas].
function preguntar_ia(PDO $db, array $yo, string $pregunta, array $historial = [], ?callable $llamar = null): array
{
    $llamar ??= 'gemini_llamar';
    $sistema = 'Eres el asistente de INDA, el sistema de registro de jornada de ' . ((function_exists('nombre_empresa') ? nombre_empresa() : '') ?: 'la empresa') . '. Hoy es ' . DIAS[(int)local(ahora(), 'N')] . ' ' . local(ahora(), 'd/m/Y') . ' (hora de Madrid). '
        . 'Respondes en español, breve y claro, a la administración. Para cualquier dato usa SIEMPRE las funciones; no inventes nombres, fechas ni cifras. '
        . 'Si la pregunta no se puede responder con las funciones, dilo. Las semanas van de lunes a domingo. «Este mes» = del día 1 a hoy. '
        . 'Da listas con nombres y fechas (dd/mm). Puedes proponer cambios de turno o mejoras de reparto, pero deja claro que son propuestas y que se aplican desde Planificación. '
        . 'El ratio de cada turno es exacto (ni una persona más ni una menos). No des consejos legales.';
    $contenidos = [];
    foreach (array_slice($historial, -8) as [$rol, $t]) $contenidos[] = ['role' => $rol, 'parts' => [['text' => $t]]];
    $contenidos[] = ['role' => 'user', 'parts' => [['text' => $pregunta]]];
    $hechas = [];
    for ($i = 0; $i < 6; $i++) {
        $r = $llamar(['systemInstruction' => ['parts' => [['text' => $sistema]]], 'contents' => $contenidos, 'tools' => herramientas_ia(), 'generationConfig' => ['temperature' => 0]]);
        $partes = $r['candidates'][0]['content']['parts'] ?? [];
        $llamadas = array_values(array_filter(array_column($partes, 'functionCall')));
        if (!$llamadas) {
            $txt = trim(implode("\n", array_filter(array_column($partes, 'text'))));
            return [$txt !== '' ? $txt : 'No he podido responder. Prueba a preguntarlo de otra forma.', $hechas];
        }
        // Al leer el JSON, {} se convierte en [] y Gemini rechaza «args: []»: se devuelve como objeto vacío.
        foreach ($partes as &$pt) if (isset($pt['functionCall']) && empty($pt['functionCall']['args'])) $pt['functionCall']['args'] = new stdClass();
        unset($pt);
        $contenidos[] = ['role' => 'model', 'parts' => $partes];
        $resp = [];
        foreach ($llamadas as $c) {
            $hechas[] = $c['name'] . ' ' . json_encode($c['args'] ?? [], JSON_UNESCAPED_UNICODE);
            $resp[] = ['functionResponse' => ['name' => $c['name'], 'response' => (object)ejecutar_consulta($db, $yo, $c['name'], (array)($c['args'] ?? []))]];
        }
        $contenidos[] = ['role' => 'user', 'parts' => $resp];
    }
    return ['La pregunta necesita demasiadas consultas. Divídela en partes más pequeñas.', $hechas];
}
