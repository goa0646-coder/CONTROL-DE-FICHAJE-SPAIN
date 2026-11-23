<?php
// Cron de Hostinger cada 15 minutos: php /home/USUARIO/.../public_html/app/cron.php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/inicio.php';
revisar_avisos(db());
recordar_firmas(db());
if ($n = purgar_gps(db())) auditar(db(), null, 'purga_gps', "coordenadas borradas: $n");
