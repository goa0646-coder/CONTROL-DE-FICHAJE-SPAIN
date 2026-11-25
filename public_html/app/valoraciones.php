<?php
// Valoración interna de calidad (1–5 estrellas). Función oculta: solo la ve y la usa el administrador que la activa;
// ni otros administradores, ni responsables, ni el trabajador, ni Inspección. No sale en exportaciones ni en el asistente.

function valoraciones_de(PDO $db): ?int
{
    $v = ajuste($db, 'valoraciones_admin');
    return $v ? (int)$v : null;
}

function ve_valoraciones(PDO $db, array $yo): bool
{
    return $yo['rol'] === 'admin' && valoraciones_de($db) === (int)$yo['id'];
}

// Activar (solo si nadie la tiene) o desactivar (solo quien la activó). Los datos se conservan.
function activar_valoraciones(PDO $db, array $yo, bool $si): void
{
    $dueno = valoraciones_de($db);
    if ($yo['rol'] !== 'admin' || ($dueno && $dueno !== (int)$yo['id'])) throw new DomainException('No disponible.');
    consulta($db, 'REPLACE INTO ajustes VALUES (?, ?)', ['valoraciones_admin', $si ? (string)$yo['id'] : '']);
}

function valorar(PDO $db, array $yo, int $uid, int $estrellas, string $nota): void
{
    if (!ve_valoraciones($db, $yo)) throw new DomainException('No disponible.');
    if ($estrellas < 1 || $estrellas > 5) throw new DomainException('De 1 a 5 estrellas.');
    consulta($db, 'INSERT INTO valoraciones (usuario_id, estrellas, nota, autor_id, creado_utc) VALUES (?, ?, ?, ?, ?)', [$uid, $estrellas, mb_substr(trim($nota), 0, 500), $yo['id'], ahora()]);
}

// Historial de una persona (la más reciente primero) y su media.
function valoraciones(PDO $db, array $yo, int $uid): array
{
    if (!ve_valoraciones($db, $yo)) return ['lista' => [], 'media' => null];
    $l = consulta($db, 'SELECT * FROM valoraciones WHERE usuario_id = ? ORDER BY creado_utc DESC, id DESC', [$uid])->fetchAll();
    return ['lista' => $l, 'media' => $l ? round(array_sum(array_column($l, 'estrellas')) / count($l), 1) : null];
}

function estrellas(?float $n): string
{
    if ($n === null) return '—';
    $r = (int)round($n);
    return str_repeat('★', $r) . str_repeat('☆', 5 - $r);
}
