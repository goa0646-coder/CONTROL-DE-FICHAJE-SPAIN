<?php
// Cron de Hostinger cada 5 minutos (para cerrar las salidas a tiempo): php /home/USUARIO/.../public_html/app/cron.php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/errores.php';
activar_registro_errores();
require __DIR__ . '/inicio.php';

$lock = fopen(__FILE__, 'r');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit;

require_once __DIR__ . '/migrar.php';
try { migrar_si_hace_falta(db()); } catch (Throwable $e) { error_log($e); }
try { consulta(db(), 'REPLACE INTO ajustes VALUES (?, ?)', ['cron_ultimo', ahora()]); } catch (Throwable $e) { error_log($e); }
try { fichajes_automaticos(db()); } catch (Throwable $e) { error_log($e); }
try { cerrar_jornadas(db()); } catch (Throwable $e) { error_log($e); }
try { avisar_olvidos(db()); } catch (Throwable $e) { error_log($e); }
try { revisar_avisos(db()); } catch (Throwable $e) { error_log($e); }
try { recordar_firmas(db()); } catch (Throwable $e) { error_log($e); }
try { revisar_ratios(db()); } catch (Throwable $e) { error_log($e); }
try { revisar_justificantes(db()); } catch (Throwable $e) { error_log($e); }
try {
    if ($n = purgar_gps(db())) auditar(db(), null, 'purga_gps', "coordenadas borradas: $n");
} catch (Throwable $e) { error_log($e); }
