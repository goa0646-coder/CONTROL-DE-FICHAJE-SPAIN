<?php
// Cron diario de Hostinger: php /home/USUARIO/.../public_html/app/cron.php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/inicio.php';
$n = purgar_gps(db());
auditar(db(), null, 'purga_gps', "coordenadas borradas: $n");
