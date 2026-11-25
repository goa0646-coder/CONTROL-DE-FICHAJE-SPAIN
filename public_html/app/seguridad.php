<?php
// Medidas opcionales que administración activa o desactiva en Empresa:
// 1) cifrado de DNI, nº de la Seguridad Social y fecha de nacimiento en la base de datos (AES-256-GCM). La clave vive en un
//    archivo junto a config-inda.php, fuera de public_html: si se pierde, esos datos no se recuperan (haced copia).
// 2) verificación en dos pasos para administración con una app de autenticación (Google Authenticator, Microsoft…).
//    Emergencia: 'sin_dos_pasos' => true en config-inda.php la desactiva.

function archivo_clave_datos(): string
{
    return dirname(__DIR__, 2) . '/clave-datos-inda.key';
}

function clave_datos(): ?string
{
    static $k = false;
    if (isset($GLOBALS['CLAVE_DATOS'])) return $GLOBALS['CLAVE_DATOS'];
    if ($k !== false) return $k;
    $f = archivo_clave_datos();
    return $k = is_file($f) ? (base64_decode(trim((string)file_get_contents($f)), true) ?: null) : null;
}

function cifrado_activo(PDO $db): bool
{
    return ajuste($db, 'cifrado_datos') === 'si' && clave_datos();
}

function cifrar(string $v): string
{
    $iv = random_bytes(12);
    $c = openssl_encrypt($v, 'aes-256-gcm', clave_datos(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:' . base64_encode($iv . $tag . $c);
}

function descifrar(?string $v): ?string
{
    if ($v === null || !str_starts_with($v, 'enc:')) return $v;
    $b = base64_decode(substr($v, 4), true);
    if (!$b || !clave_datos()) return '';
    $p = openssl_decrypt(substr($b, 28), 'aes-256-gcm', clave_datos(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
    return $p === false ? '' : $p;
}

function huella_dni(string $dni): string
{
    return hash_hmac('sha256', strtoupper($dni), clave_datos() ?? '');
}

// Fila de usuarios con los datos en claro (para mostrarlos o compararlos).
function en_claro(array $u): array
{
    if (array_key_exists('dni', $u)) $u['dni'] = (string)descifrar($u['dni']);
    if (array_key_exists('nss', $u)) $u['nss'] = descifrar($u['nss']);
    if (!empty($u['nacimiento_c'])) $u['fecha_nacimiento'] = descifrar($u['nacimiento_c']) ?: null;
    return $u;
}

// Campos a guardar en usuarios: con el cifrado activo, dni/nss/fecha_nacimiento se guardan cifrados (y la huella del DNI).
function campos_seguros(PDO $db, array $c): array
{
    $on = cifrado_activo($db);
    if (array_key_exists('dni', $c)) {
        $c['dni_huella'] = $on && $c['dni'] !== '' ? huella_dni($c['dni']) : null;
        if ($on && $c['dni'] !== '') $c['dni'] = cifrar($c['dni']);
    }
    if (array_key_exists('nss', $c) && $on && $c['nss'] !== null && $c['nss'] !== '') $c['nss'] = cifrar($c['nss']);
    if (array_key_exists('fecha_nacimiento', $c)) {
        $c['nacimiento_c'] = $on && $c['fecha_nacimiento'] ? cifrar($c['fecha_nacimiento']) : null;
        if ($on) $c['fecha_nacimiento'] = null;
    }
    return $c;
}

function usuario_por_dni(PDO $db, string $dni): ?array
{
    $dni = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $dni));
    if ($dni === '') return null;
    $u = consulta($db, 'SELECT * FROM usuarios WHERE dni = ? OR (dni_huella IS NOT NULL AND dni_huella = ?)', [$dni, huella_dni($dni)])->fetch();
    return $u ? en_claro($u) : null;
}

// Activa (crea la clave si no existe y cifra todo) o desactiva (deja todo en claro). La clave no se borra nunca.
function cambiar_cifrado(PDO $db, bool $si): int
{
    if ($si && !clave_datos()) {
        $f = archivo_clave_datos();
        if (@file_put_contents($f, base64_encode(random_bytes(32)) . "\n") === false) throw new DomainException('No puedo crear el archivo de la clave junto a config-inda.php.');
        @chmod($f, 0600);
        $GLOBALS['CLAVE_DATOS'] = base64_decode(trim(file_get_contents($f)));
    }
    consulta($db, 'REPLACE INTO ajustes VALUES (?, ?)', ['cifrado_datos', $si ? 'si' : 'no']);
    $n = 0;
    foreach ($db->query('SELECT id, dni, nss, fecha_nacimiento, nacimiento_c FROM usuarios')->fetchAll() as $u) {
        $c = campos_seguros($db, array_intersect_key(en_claro($u), array_flip(['dni', 'nss', 'fecha_nacimiento'])));
        consulta($db, 'UPDATE usuarios SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($c))) . ' WHERE id = ?', [...array_values($c), $u['id']]);
        $n++;
    }
    return $n;
}

// --- Dos pasos (TOTP, RFC 6238: códigos de 6 cifras cada 30 s) ---

function dos_pasos_activo(PDO $db): bool
{
    return ajuste($db, 'dos_pasos') === 'si' && empty(config()['sin_dos_pasos']);
}

function pide_dos_pasos(PDO $db, array $u): bool
{
    return $u['rol'] === 'admin' && dos_pasos_activo($db);
}

function base32(string $b): string
{
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($b) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $o = '';
    foreach (str_split($bits, 5) as $g) $o .= $a[bindec(str_pad($g, 5, '0'))];
    return $o;
}

function base32_a_binario(string $s): string
{
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT);
    $o = '';
    foreach (str_split($bits, 8) as $g) if (strlen($g) === 8) $o .= chr(bindec($g));
    return $o;
}

function totp(string $secreto, ?int $t = null): string
{
    $h = hash_hmac('sha1', pack('J', intdiv($t ?? time(), 30)), base32_a_binario($secreto), true);
    $o = ord($h[19]) & 0xf;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

function totp_valido(string $secreto, string $codigo, ?int $t = null): bool
{
    $codigo = preg_replace('/\D/', '', $codigo);
    if (strlen($codigo) !== 6) return false;
    $t ??= time();
    foreach ([-30, 0, 30] as $d) if (hash_equals(totp($secreto, $t + $d), $codigo)) return true;
    return false;
}

function cambiar_dos_pasos(PDO $db, bool $si): void
{
    consulta($db, 'REPLACE INTO ajustes VALUES (?, ?)', ['dos_pasos', $si ? 'si' : 'no']);
    // Los teléfonos recordados de administración entraban sin código: se olvidan y vuelven a entrar con los dos pasos.
    if ($si) $db->exec("DELETE d FROM dispositivos d JOIN usuarios u ON u.id = d.usuario_id WHERE u.rol = 'admin'");
}

// Tras la contraseña (o Google/Microsoft): entra ya, o pasa por el código si es administración con los dos pasos activos.
function completar_entrada(PDO $db, array $u, bool $recordar, string $como): never
{
    if (pide_dos_pasos($db, $u) && empty($GLOBALS['DOS_PASOS_HECHO'])) {
        $_SESSION['dos_pasos'] = ['uid' => (int)$u['id'], 'recordar' => $recordar, 'como' => $como, 'hasta' => time() + 600];
        redirigir('dospasos');
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = $u['id'];
    consulta($db, 'UPDATE usuarios SET ultimo_acceso_utc = ? WHERE id = ?', [ahora(), $u['id']]);
    if ($recordar && ($t = crear_dispositivo($db, $u, $_SERVER['HTTP_USER_AGENT'] ?? ''))) poner_cookie_dispositivo($t);
    auditar($db, (int)$u['id'], $como);
    $volver = $_SESSION['volver'] ?? [];
    unset($_SESSION['volver']);
    if (preg_match('/^[a-z]+$/', $volver['p'] ?? '') && $volver['p'] !== 'login') redirigir($volver['p'], array_diff_key($volver, ['p' => 1]));
    redirigir(inicio_de($u));
}
