<?php
require __DIR__ . '/app/inicio.php';
require __DIR__ . '/app/vista.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
iniciar_sesion();
config(); // Sin configuración todavía: muestra el asistente de instalación.

$p = $_GET['p'] ?? '';
if (!preg_match('/^[a-z]+$/', $p) || !is_file(__DIR__ . "/app/paginas/$p.php")) {
    $u = usuario_actual();
    $p = $u ? inicio_de($u) : 'login';
    $_GET['p'] = $p;
}
// api y chat se autentican con token o firma, no con sesión.
if (es_post() && !in_array($p, ['api', 'chat'], true) && !csrf_ok()) {
    http_response_code(400);
    exit('El formulario ha caducado. Vuelve atrás y recarga la página.');
}
require __DIR__ . "/app/paginas/$p.php";
