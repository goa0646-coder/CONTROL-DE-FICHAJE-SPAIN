<?php

function usuario(PDO $db, int $id): ?array
{
    $s = $db->prepare('SELECT * FROM usuarios WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
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

function login(PDO $db, string $email, string $clave): array|string
{
    $email = mb_strtolower(trim($email));
    if (bloqueado($db, $email)) return 'Demasiados intentos. Espera 15 minutos.';
    $s = $db->prepare('SELECT * FROM usuarios WHERE email = ?');
    $s->execute([$email]);
    $u = $s->fetch();
    if (!$u || !password_verify($clave, $u['password_hash'])) {
        fallo($db, $email);
        return 'Email o contraseña incorrectos.';
    }
    if (!acceso_vigente($u)) return 'Tu acceso no está activo.';
    return $u;
}

// Usuario de quiosco por nº de empleado + PIN.
function login_pin(PDO $db, string $num, string $pin): array|string
{
    $clave = 'pin:' . $num;
    if (bloqueado($db, $clave)) return 'Demasiados intentos. Espera 15 minutos.';
    $s = $db->prepare("SELECT * FROM usuarios WHERE num_empleado = ? AND rol <> 'inspeccion'");
    $s->execute([$num]);
    $u = $s->fetch();
    if (!$u || !$u['pin_hash'] || !password_verify($pin, $u['pin_hash']) || !acceso_vigente($u)) {
        fallo($db, $clave);
        return 'Número o PIN incorrectos.';
    }
    return $u;
}
