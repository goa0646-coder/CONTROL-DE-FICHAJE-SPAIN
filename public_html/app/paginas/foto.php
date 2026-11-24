<?php
// Foto de perfil: la propia, y las del equipo para responsables y administración (dato personal, no público).
$yo = requiere(...ROLES);
$u = usuario(db(), (int)($_GET['u'] ?? 0));
$ruta = $u && $u['foto'] && puede_ver($yo, $u) ? carpeta_archivos() . '/' . basename($u['foto']) : ''; // la suya, o las de su equipo si es responsable
if (!$ruta || !is_file($ruta)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . (['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][pathinfo($ruta, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Cache-Control: private, max-age=604800');
readfile($ruta);
