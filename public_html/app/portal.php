<?php
// Documentos (nóminas, contratos…) con firma, y comunicados a la plantilla.

function documento(PDO $db, int $id): ?array
{
    return consulta($db, 'SELECT * FROM documentos WHERE id = ? AND activo = 1', [$id])->fetch() ?: null;
}

// Personal: la persona, el admin o quien tenga permiso de documentos. usuario_id NULL = toda la plantilla.
function puede_ver_documento(array $yo, array $d): bool
{
    if ($d['usuario_id'] === null) return $yo['rol'] !== 'inspeccion';
    if ($d['usuario_id'] == $yo['id'] || $yo['rol'] === 'admin') return true;
    // Un responsable con permiso de documentos solo ve los de su equipo.
    return puede($yo, 'documentos') && ($dueno = usuario(db(), (int)$d['usuario_id'])) && $dueno['responsable_id'] == $yo['id'];
}

function documentos_de(PDO $db, int $uid): array
{
    return consulta($db, 'SELECT d.*, f.firmado_utc FROM documentos d LEFT JOIN documentos_firmas f ON f.documento_id = d.id AND f.usuario_id = ?
        WHERE d.activo = 1 AND (d.usuario_id = ? OR d.usuario_id IS NULL) ORDER BY d.subido_utc DESC', [$uid, $uid])->fetchAll();
}

function firmar_documento(PDO $db, array $u, int $id, string $clave): string
{
    $d = documento($db, $id);
    if (!$d || !puede_ver_documento($u, $d) || !$d['requiere_firma']) throw new DomainException('Documento no disponible.');
    if (!password_verify($clave, $u['password_hash'])) throw new DomainException('La contraseña no es correcta.');
    $hash = hash_file('sha256', carpeta_archivos() . '/' . $d['archivo']) ?: throw new DomainException('No se encuentra el archivo.');
    consulta($db, 'INSERT IGNORE INTO documentos_firmas VALUES (?, ?, ?, ?, ?)', [$id, $u['id'], $hash, ahora(), ip()]);
    return $hash;
}

// ¿De quién es esta nómina? Primero por DNI (exacto) y, si no, por nombre completo (mismas palabras, en cualquier orden).
function emparejar_nomina(array $d, array $personas): ?array
{
    if ($d['dni'] !== '') foreach ($personas as $p) if (strtoupper($p['dni']) === $d['dni']) return ['uid' => (int)$p['id'], 'por' => 'dni'];
    $palabras = fn($s) => array_filter(explode(' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', normalizar($s))), fn($w) => strlen($w) > 2); // «NÚÑEZ, JOSÉ» también vale
    $leido = $palabras($d['nombre']);
    if (!$leido) return null;
    $hay = [];
    foreach ($personas as $p) {
        $suyo = $palabras(nombre($p));
        if ($suyo && !array_diff($suyo, $leido)) $hay[] = (int)$p['id'];
    }
    return count($hay) === 1 ? ['uid' => $hay[0], 'por' => 'nombre'] : null; // dos posibles = que elija el admin
}

function comunicados_para(PDO $db, array $u): array
{
    return consulta($db, 'SELECT c.*, a.nombre AS autor, l.leido_utc FROM comunicados c JOIN usuarios a ON a.id = c.autor_id
        LEFT JOIN comunicados_leidos l ON l.comunicado_id = c.id AND l.usuario_id = ?
        WHERE c.centro_id IS NULL OR c.centro_id = ? ORDER BY c.id DESC LIMIT 50', [$u['id'], $u['centro_id']])->fetchAll();
}

// Destinatarios activos de un comunicado (todo el centro o toda la plantilla).
function destinatarios(PDO $db, ?int $centro): array
{
    return consulta($db, "SELECT id FROM usuarios WHERE activo = 1 AND rol <> 'inspeccion' AND (? IS NULL OR centro_id = ?)", [$centro, $centro])->fetchAll(PDO::FETCH_COLUMN);
}
