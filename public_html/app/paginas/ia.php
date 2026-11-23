<?php
// Lectura de DNI con IA (la usa el formulario de Usuarios). Devuelve JSON; no guarda la imagen.
$yo = requiere('admin');
header('Content-Type: application/json; charset=utf-8');
try {
    if (!es_post()) throw new DomainException('Usa el formulario.');
    $d = ia_leer('dni', $_FILES['archivo'] ?? null);
    auditar(db(), (int)$yo['id'], 'ia_dni', 'leído ' . ($d['dni_valido'] ? 'DNI válido' : 'DNI dudoso'));
    exit(json_encode($d, JSON_UNESCAPED_UNICODE));
} catch (DomainException $e) {
    http_response_code(422);
    exit(json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
}
