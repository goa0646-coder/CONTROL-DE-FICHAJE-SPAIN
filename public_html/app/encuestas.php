<?php
// Encuestas y evaluaciones del desempeño.
// Encuesta: la responde cada destinatario (anónima o no). Evaluación: su responsable (o quien la crea) valora a cada persona.

const TIPOS_PREGUNTA = ['escala' => 'Escala 1 a 5', 'si_no' => 'Sí / No', 'opciones' => 'Opciones', 'texto' => 'Texto libre'];

function encuesta(PDO $db, int $id): ?array
{
    return consulta($db, 'SELECT * FROM encuestas WHERE id = ?', [$id])->fetch() ?: null;
}

function preguntas(PDO $db, int $id): array
{
    return consulta($db, 'SELECT * FROM encuesta_preguntas WHERE encuesta_id = ? ORDER BY orden', [$id])->fetchAll();
}

// $d: titulo, descripcion, tipo, anonima, cierra; $preguntas: [[texto, tipo, opciones], …]; $personas: ids (destinatarios o evaluados).
function crear_encuesta(PDO $db, array $yo, array $d, array $preguntas, array $personas): int
{
    $titulo = trim($d['titulo'] ?? '') ?: throw new DomainException('Pon un título.');
    $tipo = ($d['tipo'] ?? '') === 'evaluacion' ? 'evaluacion' : 'encuesta';
    $preguntas = array_values(array_filter($preguntas, fn($p) => trim($p[0] ?? '') !== ''));
    if (!$preguntas) throw new DomainException('Añade al menos una pregunta.');
    foreach ($preguntas as [$t, $tp, $op]) {
        if (!isset(TIPOS_PREGUNTA[$tp])) throw new DomainException('Tipo de pregunta no válido.');
        if ($tp === 'opciones' && count(opciones_de($op)) < 2) throw new DomainException("Pon al menos dos opciones (una por línea) en «{$t}».");
    }
    $gente = array_filter(array_map(fn($id) => usuario($db, (int)$id), array_unique($personas)), fn($p) => $p && $p['activo'] && puede_ver($yo, $p));
    if (!$gente) throw new DomainException($tipo === 'evaluacion' ? 'Elige a quién evaluar.' : 'Elige a quién va.');
    $cierra = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['cierra'] ?? '') ? $d['cierra'] : null;
    $db->beginTransaction();
    consulta($db, 'INSERT INTO encuestas (titulo, descripcion, tipo, anonima, autor_id, creada_utc, cierra) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [mb_substr($titulo, 0, 150), mb_substr(trim($d['descripcion'] ?? ''), 0, 5000), $tipo, $tipo === 'encuesta' && !empty($d['anonima']) ? 1 : 0, $yo['id'], ahora(), $cierra]);
    $id = (int)$db->lastInsertId();
    foreach ($preguntas as $i => [$t, $tp, $op]) consulta($db, 'INSERT INTO encuesta_preguntas (encuesta_id, orden, texto, tipo, opciones) VALUES (?, ?, ?, ?, ?)',
        [$id, $i + 1, mb_substr(trim($t), 0, 300), $tp, $tp === 'opciones' ? implode("\n", opciones_de($op)) : '']);
    $avisar = [];
    foreach ($gente as $p) {
        // Evaluación: la hace su responsable; si no tiene, quien la crea.
        [$responde, $sobre] = $tipo === 'evaluacion' ? [(int)($p['responsable_id'] ?: $yo['id']), (int)$p['id']] : [(int)$p['id'], null];
        if ($responde === $sobre) $responde = (int)$yo['id'];
        consulta($db, 'INSERT IGNORE INTO encuesta_asignaciones (encuesta_id, responde_id, sobre_id) VALUES (?, ?, ?)', [$id, $responde, $sobre]);
        $avisar[$responde] = true;
    }
    $db->commit();
    foreach (array_keys($avisar) as $uid) notificar($db, $uid, "encuesta:$id", ($tipo === 'evaluacion' ? 'Tienes evaluaciones por hacer: ' : 'Nueva encuesta: ') . $titulo . '.', 'encuestas&id=' . $id);
    auditar($db, (int)$yo['id'], 'encuesta_creada', "encuesta=$id personas=" . count($gente));
    return $id;
}

function opciones_de(string $txt): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\r?\n|;/', $txt)), fn($x) => $x !== ''));
}

// Lo que le queda por responder a $u (encuestas abiertas).
function pendientes_encuesta(PDO $db, array $u): array
{
    return consulta($db, "SELECT a.*, e.titulo, e.tipo, e.cierra FROM encuesta_asignaciones a JOIN encuestas e ON e.id = a.encuesta_id
        WHERE a.responde_id = ? AND a.respondida_utc IS NULL AND e.activa = 1 AND (e.cierra IS NULL OR e.cierra >= ?) ORDER BY e.id DESC, a.id", [$u['id'], local(ahora(), 'Y-m-d')])->fetchAll();
}

function responder_encuesta(PDO $db, array $u, int $asignacion, array $valores): void
{
    $a = consulta($db, 'SELECT a.*, e.anonima, e.activa, e.cierra FROM encuesta_asignaciones a JOIN encuestas e ON e.id = a.encuesta_id WHERE a.id = ?', [$asignacion])->fetch();
    if (!$a || $a['responde_id'] != $u['id'] || $a['respondida_utc'] || !$a['activa'] || ($a['cierra'] && $a['cierra'] < local(ahora(), 'Y-m-d'))) throw new DomainException('Esta encuesta ya no está pendiente.');
    $limpio = [];
    foreach (preguntas($db, (int)$a['encuesta_id']) as $p) {
        $v = trim((string)($valores[$p['id']] ?? ''));
        $ok = match ($p['tipo']) {
            'escala' => in_array($v, ['1', '2', '3', '4', '5'], true),
            'si_no' => in_array($v, ['si', 'no'], true),
            'opciones' => in_array($v, opciones_de($p['opciones']), true),
            'texto' => true,
        };
        if (!$ok || ($p['tipo'] !== 'texto' && $v === '')) throw new DomainException("Responde a «{$p['texto']}».");
        $limpio[$p['id']] = mb_substr($v, 0, 3000);
    }
    $db->beginTransaction();
    $s = consulta($db, 'UPDATE encuesta_asignaciones SET respondida_utc = ? WHERE id = ? AND respondida_utc IS NULL', [ahora(), $asignacion]);
    if (!$s->rowCount()) { $db->rollBack(); throw new DomainException('Ya la habías respondido.'); }
    consulta($db, 'INSERT INTO encuesta_envios (encuesta_id, asignacion_id, sobre_id) VALUES (?, ?, ?)', [$a['encuesta_id'], $a['anonima'] ? null : $asignacion, $a['sobre_id']]);
    $envio = (int)$db->lastInsertId();
    foreach ($limpio as $pid => $v) consulta($db, 'INSERT INTO encuesta_respuestas (envio_id, pregunta_id, valor) VALUES (?, ?, ?)', [$envio, $pid, $v]);
    $db->commit();
}

// Resultados: [pregunta_id => ['media', 'cuentas' => [valor => n], 'textos' => […]]], y por persona evaluada si es evaluación.
function resultados_encuesta(PDO $db, int $id, ?int $sobre = null): array
{
    $out = [];
    foreach (preguntas($db, $id) as $p) $out[$p['id']] = ['p' => $p, 'cuentas' => [], 'textos' => [], 'suma' => 0, 'n' => 0];
    $q = 'SELECT r.*, v.sobre_id FROM encuesta_respuestas r JOIN encuesta_envios v ON v.id = r.envio_id WHERE v.encuesta_id = ?' . ($sobre ? ' AND v.sobre_id = ?' : '');
    foreach (consulta($db, $q, $sobre ? [$id, $sobre] : [$id]) as $r) {
        $x = &$out[$r['pregunta_id']];
        if ($x['p']['tipo'] === 'texto') { if ($r['valor'] !== '') $x['textos'][] = $r['valor']; }
        else $x['cuentas'][$r['valor']] = ($x['cuentas'][$r['valor']] ?? 0) + 1;
        if ($x['p']['tipo'] === 'escala') { $x['suma'] += (int)$r['valor']; $x['n']++; }
        unset($x);
    }
    foreach ($out as &$x) $x['media'] = $x['n'] ? round($x['suma'] / $x['n'], 2) : null;
    return $out;
}

function participacion(PDO $db, int $id): array
{
    return consulta($db, 'SELECT COUNT(*) AS total, SUM(respondida_utc IS NOT NULL) AS hechas FROM encuesta_asignaciones WHERE encuesta_id = ?', [$id])->fetch();
}
