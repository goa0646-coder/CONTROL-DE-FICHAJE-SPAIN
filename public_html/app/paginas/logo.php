<?php
// Logo de la empresa (público: sale en la pantalla de entrada).
$n = ajuste(db(), 'logo');
$ruta = $n ? carpeta_archivos() . '/' . basename($n) : '';
if (!$n || !is_file($ruta)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . (['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][pathinfo($n, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($ruta);
