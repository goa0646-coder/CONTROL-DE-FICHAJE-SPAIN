<?php
// Registro de errores: Hostinger no enseña los fallos de PHP (página en blanco). Aquí se guardan con su detalle
// fuera de public_html y se enseñan en Administración → Diagnóstico. A los demás solo se les da un código.

function archivo_errores(): string
{
    try {
        return carpeta_archivos() . '/errores.log';
    } catch (Throwable) {
        return sys_get_temp_dir() . '/inda-errores.log';
    }
}

// Guarda el error (una línea JSON) y devuelve su código corto.
function registrar_error(string $mensaje, string $archivo, int $linea, string $traza = ''): string
{
    $codigo = strtoupper(substr(md5($mensaje . $archivo . $linea), 0, 6));
    $base = realpath(__DIR__ . '/..') ?: '';
    $e = ['fecha' => gmdate('Y-m-d H:i:s'), 'codigo' => $codigo, 'pagina' => preg_replace('/[^a-z]/', '', $_GET['p'] ?? (PHP_SAPI === 'cli' ? 'cron' : '')),
        'mensaje' => mb_substr($mensaje, 0, 1000), 'donde' => str_replace($base, '', $archivo) . ':' . $linea, 'traza' => mb_substr(str_replace($base, '', $traza), 0, 3000),
        'php' => PHP_VERSION, 'version' => defined('VERSION') ? VERSION : ''];
    $f = archivo_errores();
    @file_put_contents($f, json_encode($e, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    // Como mucho unas 300 líneas.
    if (@filesize($f) > 400000) @file_put_contents($f, implode('', array_slice((array)@file($f), -300)), LOCK_EX);
    return $codigo;
}

function errores_recientes(int $n = 50): array
{
    $l = @file(archivo_errores(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_reverse(array_filter(array_map(fn($x) => json_decode($x, true), array_slice($l, -$n))));
}

// Página de error amable. A administración se le enseña el detalle; al resto, solo el código.
function pagina_error(string $codigo, string $mensaje, string $donde): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Error $codigo: $mensaje ($donde)\n");
        return;
    }
    $admin = false;
    try {
        $admin = function_exists('usuario_actual') && ($u = usuario_actual()) && $u['rol'] === 'admin';
    } catch (Throwable) {
    }
    while (ob_get_level()) ob_end_clean();
    if (!headers_sent()) http_response_code(500);
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Error · INDA</title>'
        . '<body style="font:16px/1.5 system-ui,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem;color:#101828">'
        . '<h1 style="font-size:1.4rem">Algo ha fallado</h1><p>No se ha podido completar la acción. Si se repite, díselo a administración con este código: <strong>' . $h($codigo) . '</strong>.</p>'
        . ($admin ? '<div style="background:#fef3f2;border:1px solid #fecdca;border-radius:10px;padding:1rem;margin:1rem 0"><strong>Detalle (solo lo ve administración):</strong><br><code style="word-break:break-word">' . $h($mensaje) . '</code><br><small>' . $h($donde) . '</small></div>'
            . '<p><a href="?p=diagnostico">Ver el diagnóstico completo</a></p>' : '')
        . '<p><a href="?">Volver al inicio</a></p></body>';
}

function activar_registro_errores(): void
{
    // Nunca enseñar avisos de PHP (rutas, consultas) en pantalla aunque el hosting lo tenga activado: van al registro.
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    set_exception_handler(function (Throwable $e) {
        $c = registrar_error(get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        pagina_error($c, $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine());
    });
    register_shutdown_function(function () {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) return;
        $c = registrar_error($e['message'], $e['file'], $e['line']);
        pagina_error($c, $e['message'], basename($e['file']) . ':' . $e['line']);
    });
}
