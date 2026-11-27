<?php
// Avisos push sin contenido (Web Push + VAPID): el navegador despierta al service worker,
// que pide a INDA el último aviso. Así no hace falta cifrar el mensaje.

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function ajuste(PDO $db, string $clave): ?string
{
    $v = consulta($db, 'SELECT valor FROM ajustes WHERE clave = ?', [$clave])->fetchColumn();
    return $v === false ? null : (string)$v;
}

// Par de claves VAPID (se genera la primera vez y se guarda en la BD).
function claves_vapid(PDO $db): array
{
    if ($pem = ajuste($db, 'vapid_privada')) return [$pem, ajuste($db, 'vapid_publica')];
    $k = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k) return [null, null]; // El servidor no tiene OpenSSL con curvas EC: sin push.
    openssl_pkey_export($k, $pem);
    $d = openssl_pkey_get_details($k)['ec'];
    $pub = b64url("\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT));
    consulta($db, 'INSERT IGNORE INTO ajustes VALUES (?, ?), (?, ?)', ['vapid_privada', $pem, 'vapid_publica', $pub]);
    return [ajuste($db, 'vapid_privada'), ajuste($db, 'vapid_publica')];
}

// Firma DER de OpenSSL → r||s de 64 bytes (formato JWT ES256).
function der_a_raw(string $der): string
{
    $pos = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

function jwt_vapid(string $pem, string $endpoint, string $contacto, ?int $ahora = null): string
{
    $p = parse_url($endpoint);
    $datos = b64url('{"typ":"JWT","alg":"ES256"}') . '.' . b64url(json_encode([
        'aud' => "{$p['scheme']}://{$p['host']}", 'exp' => ($ahora ?? time()) + 12 * 3600, 'sub' => "mailto:$contacto"]));
    openssl_sign($datos, $der, $pem, OPENSSL_ALGO_SHA256);
    return $datos . '.' . b64url(der_a_raw($der));
}

function enviar_push(PDO $db, int $uid): int
{
    $subs = consulta($db, 'SELECT id, endpoint FROM suscripciones_push WHERE usuario_id = ?', [$uid])->fetchAll();
    if (!$subs || !function_exists('curl_init')) return 0;
    [$pem, $pub] = claves_vapid($db);
    if (!$pem) return 0;
    $ok = 0;
    foreach ($subs as $s) {
        $ch = curl_init($s['endpoint']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['TTL: 86400', 'Urgency: normal', 'Authorization: vapid t=' . jwt_vapid($pem, $s['endpoint'], config()['correo_desde'] ?? 'admin@localhost') . ", k=$pub"]]);
        curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (in_array($codigo, [404, 410], true)) consulta($db, 'DELETE FROM suscripciones_push WHERE id = ?', [$s['id']]);
        $ok += $codigo >= 200 && $codigo < 300;
    }
    return $ok;
}

function suscribir_push(PDO $db, int $uid, string $endpoint): void
{
    $p = parse_url($endpoint);
    if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || strlen($endpoint) > 700) throw new DomainException('Suscripción no válida.');
    consulta($db, 'INSERT INTO suscripciones_push (usuario_id, endpoint, endpoint_hash, creado_utc) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE usuario_id = VALUES(usuario_id)', [$uid, $endpoint, hash('sha256', $endpoint), ahora()]);
}
