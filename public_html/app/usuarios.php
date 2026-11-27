<?php

function usuario(PDO $db, int $id): ?array
{
    $s = $db->prepare('SELECT * FROM usuarios WHERE id = ?');
    $s->execute([$id]);
    return ($u = $s->fetch()) ? en_claro($u) : null;
}

function centro(PDO $db, int $id): ?array
{
    $s = $db->prepare('SELECT * FROM centros WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function nombre(array $u): string
{
    return trim($u['nombre'] . ' ' . $u['apellidos']);
}

function acceso_vigente(array $u): bool
{
    return $u['activo'] && ($u['acceso_hasta'] === null || $u['acceso_hasta'] >= gmdate('Y-m-d'));
}

function puede_ver(array $yo, array $otro): bool
{
    return $yo['id'] == $otro['id']
        || in_array($yo['rol'], ['admin', 'inspeccion'], true)
        || ($yo['rol'] === 'responsable' && $otro['responsable_id'] == $yo['id']);
}

// Nadie aprueba sus propias correcciones.
function puede_aprobar(array $yo, array $otro): bool
{
    return $yo['id'] != $otro['id']
        && ($yo['rol'] === 'admin' || ($yo['rol'] === 'responsable' && $otro['responsable_id'] == $yo['id']));
}

// Personas cuyo registro puede ver $yo (sin contar las cuentas de Inspección).
function equipo_de(PDO $db, array $yo): array
{
    if ($yo['rol'] === 'responsable') {
        $s = $db->prepare("SELECT * FROM usuarios WHERE responsable_id = ? AND rol <> 'inspeccion' ORDER BY nombre, apellidos");
        $s->execute([$yo['id']]);
        return $s->fetchAll();
    }
    return $db->query("SELECT * FROM usuarios WHERE rol <> 'inspeccion' ORDER BY activo DESC, nombre, apellidos")->fetchAll();
}

const PERMISOS = ['informes' => 'Ver informes', 'planificar' => 'Planificar horarios y turnos', 'documentos' => 'Subir documentos', 'comunicados' => 'Publicar comunicados'];

// Permisos extra configurables por rol. El admin los tiene todos.
function puede(array $u, string $permiso): bool
{
    if ($u['rol'] === 'admin') return true;
    static $tabla;
    $tabla ??= db()->query('SELECT CONCAT(rol, ":", permiso) FROM permisos_rol')->fetchAll(PDO::FETCH_COLUMN);
    return in_array("{$u['rol']}:$permiso", $tabla, true);
}

// --- Sesión mantenida en el dispositivo (móvil) ---

const DISPOSITIVO_COOKIE = 'inda_dispositivo';
const DISPOSITIVO_DIAS = 365;

function nombre_dispositivo(string $ua): string
{
    $so = match (true) {
        str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad', str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'Linux') => 'Linux', default => 'Dispositivo',
    };
    $nav = match (true) {
        str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'Firefox') => 'Firefox', str_contains($ua, 'Chrome') => 'Chrome', str_contains($ua, 'Safari') => 'Safari', default => '',
    };
    return trim("$so $nav");
}

// Crea el token del dispositivo y lo devuelve (va en la cookie). La Inspección no lo puede usar.
function crear_dispositivo(PDO $db, array $u, string $ua = ''): ?string
{
    if ($u['rol'] === 'inspeccion') return null;
    $t = bin2hex(random_bytes(32));
    consulta($db, 'INSERT INTO dispositivos (usuario_id, token_hash, nombre, creado_utc, usado_utc) VALUES (?, ?, ?, ?, ?)',
        [$u['id'], hash('sha256', $t), nombre_dispositivo($ua), ahora(), ahora()]);
    return $t;
}

// Usuario del token si sigue activo, no ha caducado y la persona sigue de alta.
function usuario_por_dispositivo(PDO $db, string $t): ?array
{
    if ($t === '') return null;
    $d = consulta($db, 'SELECT * FROM dispositivos WHERE token_hash = ? AND activo = 1 AND usado_utc > ?',
        [hash('sha256', $t), gmdate('Y-m-d H:i:s', time() - DISPOSITIVO_DIAS * 86400)])->fetch();
    $u = $d ? usuario($db, (int)$d['usuario_id']) : null;
    if (!$u || !acceso_vigente($u) || $u['rol'] === 'inspeccion') return null;
    consulta($db, 'UPDATE dispositivos SET usado_utc = ? WHERE id = ?', [ahora(), $d['id']]);
    return $u;
}

// Cierra las sesiones guardadas de una persona (todas o menos la del token indicado).
function cerrar_dispositivos(PDO $db, int $uid, string $salvo = ''): void
{
    consulta($db, 'UPDATE dispositivos SET activo = 0 WHERE usuario_id = ? AND token_hash <> ?', [$uid, $salvo === '' ? '' : hash('sha256', $salvo)]);
}

function login(PDO $db, string $email, string $clave): array|string
{
    $email = mb_strtolower(trim($email));
    // Por cuenta (5) y por conexión (30: en la residencia muchos comparten la misma wifi), contra pruebas de contraseñas en muchas cuentas.
    if (bloqueado($db, $email) || bloqueado($db, 'ip:' . ip(), 30)) return 'Demasiados intentos. Espera 15 minutos.';
    $s = $db->prepare('SELECT * FROM usuarios WHERE email = ?');
    $s->execute([$email]);
    $u = $s->fetch();
    if (!$u || !password_verify($clave, $u['password_hash'])) {
        fallo($db, $email);
        fallo($db, 'ip:' . ip());
        return 'Email o contraseña incorrectos.';
    }
    if (!acceso_vigente($u)) return 'Tu acceso no está activo.';
    return $u;
}

// Usuario de quiosco por nº de empleado + PIN.
function login_pin(PDO $db, string $num, string $pin): array|string
{
    $clave = 'pin:' . $num;
    if (bloqueado($db, $clave) || bloqueado($db, 'ip:' . ip(), 30)) return 'Demasiados intentos. Espera 15 minutos.';
    $s = $db->prepare("SELECT * FROM usuarios WHERE num_empleado = ? AND rol <> 'inspeccion'");
    $s->execute([$num]);
    $u = $s->fetch();
    if (!$u || !$u['pin_hash'] || !password_verify($pin, $u['pin_hash']) || !acceso_vigente($u)) {
        fallo($db, $clave);
        fallo($db, 'ip:' . ip());
        return 'Número o PIN incorrectos.';
    }
    return $u;
}

// Acceso de Inspección por enlace (sin contraseña): crea o reactiva la cuenta «INSPECCIÓN» con acceso hasta $hasta y
// devuelve el enlace secreto (solo se guarda su huella). Un enlace nuevo invalida el anterior.
function crear_acceso_inspeccion(PDO $db, array $yo, string $hasta): string
{
    if ($yo['rol'] !== 'admin') throw new DomainException('Solo administración.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) || $hasta < gmdate('Y-m-d')) throw new DomainException('Pon hasta qué día tiene acceso.');
    $email = 'inspeccion@' . (parse_url(config()['url'] ?? '', PHP_URL_HOST) ?: 'inda.local');
    $u = consulta($db, 'SELECT * FROM usuarios WHERE email = ?', [$email])->fetch();
    if ($u) consulta($db, "UPDATE usuarios SET activo = 1, baja_utc = NULL, rol = 'inspeccion', acceso_hasta = ? WHERE id = ?", [$hasta, $u['id']]);
    else consulta($db, "INSERT INTO usuarios (nombre, apellidos, email, password_hash, rol, acceso_hasta, alta_utc) VALUES ('INSPECCIÓN', '', ?, ?, 'inspeccion', ?, ?)",
        [$email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $hasta, ahora()]);
    $uid = $u ? (int)$u['id'] : (int)$db->lastInsertId();
    $t = bin2hex(random_bytes(24));
    consulta($db, 'REPLACE INTO ajustes VALUES (?, ?)', ['inspeccion_enlace', hash('sha256', $t) . "|$uid"]);
    auditar($db, (int)$yo['id'], 'inspeccion_enlace', "hasta $hasta");
    return rtrim(config()['url'] ?? '', '/') . '/?p=acceso&t=' . $t;
}

// Usuario de un enlace de Inspección válido (cuenta activa y dentro de su fecha), o null.
function usuario_de_enlace(PDO $db, string $t): ?array
{
    $v = ajuste($db, 'inspeccion_enlace');
    if (!$v || $t === '' || !str_contains($v, '|')) return null;
    [$h, $uid] = explode('|', $v, 2);
    if (!hash_equals($h, hash('sha256', $t))) return null;
    $u = usuario($db, (int)$uid);
    return $u && $u['rol'] === 'inspeccion' && acceso_vigente($u) ? $u : null;
}
