<?php
declare(strict_types=1);

date_default_timezone_set('UTC');
const ZONA = 'Europe/Madrid';
const ROLES = ['empleado', 'responsable', 'admin', 'inspeccion'];

require __DIR__ . '/fichajes.php';
require __DIR__ . '/correcciones.php';
require __DIR__ . '/usuarios.php';

// config-fichaje.php vive fuera de public_html (ver INSTALAR.md).
function config(): array
{
    static $c;
    if ($c) return $c;
    if (isset($GLOBALS['CONFIG'])) return $c = $GLOBALS['CONFIG'];
    foreach ([2, 3, 4] as $n) {
        $f = dirname(__DIR__, $n) . '/config-fichaje.php';
        if (is_file($f)) return $c = require $f;
    }
    http_response_code(500);
    exit('Falta config-fichaje.php (ver INSTALAR.md)');
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
    if ($u && !acceso_vigente($u)) $u = null;
    return $u;
}

function requiere(string ...$roles): array
{
    $u = usuario_actual();
    if (!$u) redirigir('login');
    if (!in_array($u['rol'], $roles, true)) {
        http_response_code(403);
        exit('No tienes permiso para ver esta página.');
    }
    return $u;
}

function inicio_de(array $u): string
{
    return $u['rol'] === 'inspeccion' ? 'equipo' : 'fichar';
}
