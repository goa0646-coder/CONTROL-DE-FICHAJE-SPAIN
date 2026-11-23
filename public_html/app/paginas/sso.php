<?php
// Entrar con Google o Microsoft (OpenID Connect). Se activa en config-inda.php:
// 'sso' => ['proveedor' => 'google'|'microsoft', 'cliente_id' => '…', 'secreto' => '…', 'tenant' => 'ID del directorio (solo Microsoft)']
// URL de retorno a registrar en el proveedor: https://TU-DOMINIO/?p=sso
$s = config()['sso'] ?? null;
if (!$s || empty($s['cliente_id'])) exit('El acceso con Google/Microsoft no está configurado.');
$ms = ($s['proveedor'] ?? '') === 'microsoft';
// Microsoft: solo el directorio de la empresa (con "common" el email no está garantizado).
if ($ms && !preg_match('/^[0-9a-f-]{36}$/i', $s['tenant'] ?? '')) exit('Falta el ID del directorio de Microsoft (tenant) en la configuración.');
$base = $ms ? 'https://login.microsoftonline.com/' . $s['tenant'] . '/oauth2/v2.0' : '';
$auth = $ms ? "$base/authorize" : 'https://accounts.google.com/o/oauth2/v2/auth';
$token_url = $ms ? "$base/token" : 'https://oauth2.googleapis.com/token';
$retorno = rtrim(config()['url'], '/') . '/?p=sso';

if (!isset($_GET['code'])) {
    $_SESSION['sso'] = ['state' => bin2hex(random_bytes(16)), 'nonce' => bin2hex(random_bytes(16))];
    header('Location: ' . $auth . '?' . http_build_query(['client_id' => $s['cliente_id'], 'response_type' => 'code', 'scope' => 'openid email profile',
        'redirect_uri' => $retorno, 'state' => $_SESSION['sso']['state'], 'nonce' => $_SESSION['sso']['nonce'], 'prompt' => 'select_account']));
    exit;
}
$esperado = $_SESSION['sso'] ?? null;
unset($_SESSION['sso']);
$fallo = function (string $m) {
    aviso($m, 'error');
    redirigir('login');
};
if (!$esperado || !hash_equals($esperado['state'], (string)($_GET['state'] ?? ''))) $fallo('La sesión de acceso ha caducado. Inténtalo otra vez.');

$ch = curl_init($token_url);
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POSTFIELDS => http_build_query([
    'code' => $_GET['code'], 'client_id' => $s['cliente_id'], 'client_secret' => $s['secreto'], 'redirect_uri' => $retorno, 'grant_type' => 'authorization_code'])]);
$r = json_decode((string)curl_exec($ch), true);
curl_close($ch);
// El id_token llega directamente del proveedor por TLS: basta comprobar sus datos (OIDC, 3.1.3.7).
$partes = explode('.', $r['id_token'] ?? '');
$c = count($partes) === 3 ? json_decode(base64_decode(strtr($partes[1], '-_', '+/')), true) : null;
$email = mb_strtolower((string)($c['email'] ?? $c['preferred_username'] ?? ''));
if (!$c || $c['aud'] !== $s['cliente_id'] || ($c['nonce'] ?? '') !== $esperado['nonce'] || ($c['exp'] ?? 0) < time()
    || ($ms ? strcasecmp($c['tid'] ?? '', $s['tenant']) !== 0 : empty($c['email_verified'])) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $fallo('No se pudo comprobar tu cuenta.');

$u = consulta(db(), 'SELECT * FROM usuarios WHERE email = ?', [$email])->fetch();
if (!$u || !acceso_vigente($u)) $fallo("No hay una cuenta activa en INDA con $email.");
session_regenerate_id(true);
$_SESSION['uid'] = $u['id'];
auditar(db(), (int)$u['id'], 'login_sso', $s['proveedor'] ?? 'google');
redirigir(inicio_de($u));
