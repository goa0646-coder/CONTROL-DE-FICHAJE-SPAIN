<?php
// Contratos de cada persona (pestaña «Contratos» de su ficha). Se editan; no se borran (anulado = metido por error).

const CAMPOS_CONTRATO = ['tipo' => 80, 'categoria' => 100, 'jornada' => 60, 'convenio' => 200, 'notas' => 500];

function contratos_de(PDO $db, int $uid): array
{
    return consulta($db, 'SELECT * FROM contratos WHERE usuario_id = ? ORDER BY anulado, inicio DESC, id DESC', [$uid])->fetchAll();
}

function estado_contrato(array $c, ?string $hoy = null): string
{
    $hoy ??= local(ahora(), 'Y-m-d');
    return match (true) {
        (bool)$c['anulado'] => 'Anulado',
        $c['inicio'] > $hoy => 'Futuro',
        $c['fin'] !== null && $c['fin'] < $hoy => 'Finalizado',
        default => 'Activo',
    };
}

// Crea ($id null) o cambia un contrato. $d: tipo, categoria, jornada, inicio, fin, convenio, notas, anulado. Devuelve el id.
function guardar_contrato(PDO $db, ?array $yo, int $uid, array $d, ?int $id = null): int
{
    $inicio = fecha_iso((string)($d['inicio'] ?? '')) ?: throw new DomainException('Pon la fecha de inicio del contrato.');
    $fin = trim((string)($d['fin'] ?? '')) === '' ? null : (fecha_iso((string)$d['fin']) ?: throw new DomainException('Fecha de fin no válida.'));
    if ($fin !== null && $fin < $inicio) throw new DomainException('La fecha de fin es anterior a la de inicio.');
    $v = ['inicio' => $inicio, 'fin' => $fin, 'anulado' => empty($d['anulado']) ? 0 : 1];
    foreach (CAMPOS_CONTRATO as $k => $max) $v[$k] = mb_substr(trim((string)($d[$k] ?? '')), 0, $max);
    if ($id) {
        $s = consulta($db, 'UPDATE contratos SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($v))) . ', cambiado_utc = ? WHERE id = ? AND usuario_id = ?', [...array_values($v), ahora(), $id, $uid]);
        if (!$s->rowCount() && !consulta($db, 'SELECT 1 FROM contratos WHERE id = ? AND usuario_id = ?', [$id, $uid])->fetchColumn()) throw new DomainException('Ese contrato no es de esta persona.');
    } else {
        consulta($db, 'INSERT INTO contratos (' . implode(', ', array_keys($v)) . ', usuario_id, creado_por, creado_utc) VALUES (' . rtrim(str_repeat('?, ', count($v) + 3), ', ') . ')',
            [...array_values($v), $uid, $yo['id'] ?? null, ahora()]);
        $id = (int)$db->lastInsertId();
    }
    auditar($db, isset($yo['id']) ? (int)$yo['id'] : null, 'contrato_guardado', "usuario=$uid contrato=$id");
    return $id;
}
