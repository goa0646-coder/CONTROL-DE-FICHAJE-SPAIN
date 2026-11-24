<?php
// Cron de Hostinger cada 5 minutos (para cerrar las salidas a tiempo): php /home/USUARIO/.../public_html/app/cron.php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/errores.php';
activar_registro_errores();
require __DIR__ . '/inicio.php';
require_once __DIR__ . '/migrar.php';
migrar_si_hace_falta(db());
cerrar_jornadas(db());
revisar_avisos(db());
recordar_firmas(db());
revisar_ratios(db());
if ($n = purgar_gps(db())) auditar(db(), null, 'purga_gps', "coordenadas borradas: $n");
