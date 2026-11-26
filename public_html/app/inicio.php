<?php
declare(strict_types=1);

date_default_timezone_set('UTC');
const ZONA = 'Europe/Madrid';
// Versión que se ve abajo en el menú: sirve para comprobar que el servidor tiene la última subida.
const VERSION = '2026.11.26i';

// Dirección de un archivo de assets con su fecha: así el navegador y el móvil no usan uno viejo de su caché.
function asset(string $ruta): string
{
    $f = __DIR__ . '/../' . $ruta;
    return $ruta . (is_file($f) ? '?v=' . filemtime($f) : '');
}

const ROLES = ['empleado', 'responsable', 'admin', 'inspeccion'];

require __DIR__ . '/fichajes.php';
require __DIR__ . '/correcciones.php';
require __DIR__ . '/usuarios.php';
require __DIR__ . '/horarios.php';
require __DIR__ . '/ausencias.php';
require __DIR__ . '/avisos.php';
require __DIR__ . '/correo.php';
require __DIR__ . '/push.php';
require __DIR__ . '/informes.php';
require __DIR__ . '/portal.php';
require __DIR__ . '/api.php';
require __DIR__ . '/ia.php';
require __DIR__ . '/convenio.php';
require __DIR__ . '/cuadrante.php';
require __DIR__ . '/sustituciones.php';
require __DIR__ . '/importar.php';
require __DIR__ . '/anuncios.php';
require __DIR__ . '/presencia.php';
require __DIR__ . '/turnos.php';
require __DIR__ . '/estudio.php';
require __DIR__ . '/libro.php';
require __DIR__ . '/cuadrante_excel.php';
require __DIR__ . '/marca.php';
require __DIR__ . '/cambios.php';
require __DIR__ . '/valoraciones.php';
require __DIR__ . '/seguridad.php';
require __DIR__ . '/contratos.php';
require __DIR__ . '/pdf.php';
require __DIR__ . '/encuestas.php';
require __DIR__ . '/consultas.php';
require __DIR__ . '/preferencias.php';

// config-inda.php vive fuera de public_html (ver INSTALAR.md).
// config-fichaje.php se sigue aceptando para instalaciones antiguas.
function config(): array
{
    static $c;
    if ($c) return $c;
    if (isset($GLOBALS['CONFIG'])) return $c = $GLOBALS['CONFIG'];
    foreach ([2, 3, 4] as $n) {
        foreach (['config-inda.php', 'config-fichaje.php'] as $nombre) {
            $f = dirname(__DIR__, $n) . '/' . $nombre;
            if (is_file($f)) return $c = require $f;
        }
    }
    // Primera vez en el servidor: asistente para crear la configuración.
    if (PHP_SAPI !== 'cli' && function_exists('cabecera')) {
        require_once __DIR__ . '/asistente.php';
        asistente(dirname(__DIR__, 2) . '/config-inda.php');
    }
    http_response_code(500);
    exit('Falta config-inda.php (ver INSTALAR.md)');
}

function db(): PDO
{
    static $db;
    return $db ??= conectar(config()['bd']);
}

function conectar(array $c): PDO
{
    $db = new PDO("mysql:host={$c['host']};dbname={$c['nombre']};charset=utf8mb4", $c['usuario'], $c['clave'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}

function consulta(PDO $db, string $sql, array $p = []): PDOStatement
{
    $s = $db->prepare($sql);
    $s->execute($p);
    return $s;
}

function ahora(): string
{
    return gmdate('Y-m-d H:i:s');
}

function local(?string $utc, string $fmt = 'd/m/Y H:i'): string
{
    if (!$utc) return '';
    return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(ZONA))->format($fmt);
}

function a_utc(string $local): string
{
    return (new DateTime($local, new DateTimeZone(ZONA)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function e(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

function ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function auditar(PDO $db, ?int $uid, string $accion, string $detalle = ''): void
{
    $db->prepare('INSERT INTO auditoria (usuario_id, accion, detalle, ip, momento_utc) VALUES (?, ?, ?, ?, ?)')
        ->execute([$uid, $accion, mb_substr($detalle, 0, 1000), ip(), ahora()]);
}

// --- Bloqueo tras 5 fallos en 15 minutos (login y PIN de quiosco) ---

function bloqueado(PDO $db, string $clave): bool
{
    $s = $db->prepare('SELECT COUNT(*) FROM intentos_login WHERE clave = ? AND momento_utc > ?');
    $s->execute([$clave, gmdate('Y-m-d H:i:s', time() - 900)]);
    return $s->fetchColumn() >= 5;
}

function fallo(PDO $db, string $clave): void
{
    $db->prepare('INSERT INTO intentos_login VALUES (?, ?)')->execute([$clave, ahora()]);
}

// --- Sesión, CSRF y avisos ---

function iniciar_sesion(): void
{
    session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Lax']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function csrf_ok(): bool
{
    return hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '');
}

function es_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function aviso(string $texto, string $tipo = 'ok'): void
{
    $_SESSION['aviso'] = [$texto, $tipo];
}

function redirigir(string $pagina, array $params = []): never
{
    header('Location: ?' . http_build_query(['p' => $pagina] + $params));
    exit;
}

function usuario_actual(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    $u = isset($_SESSION['uid']) ? usuario(db(), (int)$_SESSION['uid']) : null;
    // Sin sesión pero con el teléfono recordado: entra solo (como una app).
    if (!$u && ($t = $_COOKIE[DISPOSITIVO_COOKIE] ?? '') !== '') {
        $u = usuario_por_dispositivo(db(), $t);
        if ($u) {
            session_regenerate_id(true);
            $_SESSION['uid'] = $u['id'];
            consulta(db(), 'UPDATE usuarios SET ultimo_acceso_utc = ? WHERE id = ?', [ahora(), $u['id']]);
            poner_cookie_dispositivo($t);
        } else {
            poner_cookie_dispositivo('');
        }
    }
    if ($u && !acceso_vigente($u)) $u = null;
    return $u;
}

function poner_cookie_dispositivo(string $t): void
{
    if (headers_sent()) return;
    setcookie(DISPOSITIVO_COOKIE, $t, ['expires' => $t === '' ? 1 : time() + DISPOSITIVO_DIAS * 86400, 'path' => '/',
        'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Lax']);
}

function requiere(string ...$roles): array
{
    $u = usuario_actual();
    if (!$u) {
        // Tras entrar, vuelve a donde iba (p. ej. un QR escaneado).
        $_SESSION['volver'] = array_filter($_GET, 'is_string');
        redirigir('login');
    }
    if (!in_array($u['rol'], $roles, true)) {
        http_response_code(403);
        exit('No tienes permiso para ver esta página.');
    }
    // Mantenimiento: administración trabaja con INDA cerrado a la plantilla (si se deja, solo pueden fichar).
    if (in_array($u['rol'], ['empleado', 'responsable'], true) && ($m = ajuste(db(), 'mantenimiento')) && $m !== 'no'
        && !($m === 'fichar' && in_array($_GET['p'] ?? '', ['fichar', 'salir', 'logo', 'version'], true)) && ($_GET['p'] ?? '') !== 'salir') {
        if ($m === 'fichar' && ($_GET['p'] ?? '') !== 'fichar') redirigir('fichar');
        http_response_code(503);
        header('Retry-After: 1800');
        exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>INDA</title><body style="font-family:system-ui;max-width:32rem;margin:15vh auto;padding:1rem;text-align:center">'
            . '<h1>INDA está en mantenimiento</h1><p>Administración está haciendo cambios. Vuelve a entrar en un rato.</p><p><a href="?p=salir">Salir</a></p></body>');
    }
    // Comunicado con firma requerida sin firmar: no se sigue hasta firmarlo (fichar nunca se bloquea: es el registro legal).
    if (in_array($u['rol'], ['empleado', 'responsable'], true) && !in_array($_GET['p'] ?? '', ['comunicados', 'fichar', 'salir', 'archivo', 'foto', 'logo', 'version'], true)
        && ($pend = firma_pendiente(db(), $u))) {
        aviso('Tienes un comunicado que debes firmar antes de seguir.', 'error');
        redirigir('comunicados', ['id' => $pend]);
    }
    return $u;
}

function inicio_de(array $u): string
{
    return match ($u['rol']) { 'inspeccion' => 'equipo', 'responsable', 'admin' => 'panel', default => 'fichar' };
}
