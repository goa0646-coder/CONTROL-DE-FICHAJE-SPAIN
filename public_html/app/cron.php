<?php
// Cron de Hostinger cada 5 minutos (para cerrar las salidas a tiempo): php /home/USUARIO/.../public_html/app/cron.php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/errores.php';
activar_registro_errores();
require __DIR__ . '/inicio.php';

$lock = fopen(__FILE__, 'r');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit;

require_once __DIR__ . '/migrar.php';
// Cada tarea por separado: si una falla, las demás siguen y el fallo se ve en Administración → Diagnóstico.
$tareas = [
    'migrar_si_hace_falta' => fn() => migrar_si_hace_falta(db()),
    'cron_ultimo' => fn() => consulta(db(), 'REPLACE INTO ajustes VALUES (?, ?)', ['cron_ultimo', ahora()]),
    'fichajes_automaticos' => fn() => fichajes_automaticos(db()),
    'cerrar_jornadas' => fn() => cerrar_jornadas(db()),
    'avisar_olvidos' => fn() => avisar_olvidos(db()),
    'revisar_avisos' => fn() => revisar_avisos(db()),
    'recordar_firmas' => fn() => recordar_firmas(db()),
    'revisar_ratios' => fn() => revisar_ratios(db()),
    'revisar_justificantes' => fn() => revisar_justificantes(db()),
    'recordar_solicitudes' => fn() => recordar_solicitudes(db()),
    'purgar_gps' => function () { if ($n = purgar_gps(db())) auditar(db(), null, 'purga_gps', "coordenadas borradas: $n"); },
];
foreach ($tareas as $nombre => $tarea) {
    try {
        $tarea();
    } catch (Throwable $e) {
        registrar_error("Cron ($nombre): " . get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    }
}
