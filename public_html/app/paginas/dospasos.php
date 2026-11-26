<?php
// Segundo paso de la entrada de administración: código de 6 cifras de la app de autenticación (o darla de alta la primera vez).
$p = $_SESSION['dos_pasos'] ?? null;
$u = $p && $p['hasta'] > time() ? usuario(db(), (int)$p['uid']) : null;
if (!$u || !acceso_vigente($u)) {
    unset($_SESSION['dos_pasos']);
    redirigir('login');
}
$nuevo = !$u['totp_secreto'];
if ($nuevo) $_SESSION['totp_nuevo'] ??= base32(random_bytes(20));
$secreto = $nuevo ? $_SESSION['totp_nuevo'] : $u['totp_secreto'];
if (es_post()) {
    $_SESSION['dos_pasos_fallos'] = ($_SESSION['dos_pasos_fallos'] ?? 0) + 1;
    // También por cuenta: volver a entrar con la contraseña no da 5 intentos nuevos sin fin.
    if ($_SESSION['dos_pasos_fallos'] > 5 || bloqueado(db(), 'totp:' . $u['id'], 10)) {
        unset($_SESSION['dos_pasos'], $_SESSION['dos_pasos_fallos']);
        auditar(db(), (int)$u['id'], 'dos_pasos_bloqueado');
        aviso('Demasiados códigos incorrectos. Vuelve a entrar.', 'error');
        redirigir('login');
    }
    if (!totp_valido($secreto, (string)($_POST['codigo'] ?? ''))) {
        fallo(db(), 'totp:' . $u['id']);
        aviso('Código incorrecto. Mira la app y escribe el de ahora.', 'error');
        redirigir('dospasos');
    }
    if ($nuevo) consulta(db(), 'UPDATE usuarios SET totp_secreto = ? WHERE id = ?', [$secreto, $u['id']]);
    unset($_SESSION['dos_pasos'], $_SESSION['dos_pasos_fallos'], $_SESSION['totp_nuevo']);
    // Ya verificado: entra (sin volver a pedir el código).
    $GLOBALS['DOS_PASOS_HECHO'] = true;
    completar_entrada(db(), $u, (bool)$p['recordar'], $p['como'] . ($nuevo ? '+alta_2fa' : '+2fa'));
}
$emisor = rawurlencode(nombre_empresa() ?: 'INDA');
$enlace = "otpauth://totp/$emisor:" . rawurlencode($u['email']) . "?secret=$secreto&issuer=$emisor";
cabecera('Verificación en dos pasos');
?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <?php if ($nuevo): ?>
  <h2>Activa la verificación en dos pasos</h2>
  <ol>
    <li>Instala en tu móvil Google Authenticator (o Microsoft Authenticator).</li>
    <li>En la app pulsa <strong>+</strong> → <strong>Escanear código QR</strong> y apunta a este código:</li>
  </ol>
  <div class="qr" data-qr="<?= e($enlace) ?>"></div>
  <p class="nota">¿Estás entrando desde el mismo móvil? Pulsa <a href="<?= e($enlace) ?>">este enlace</a>, o en la app elige «Introducir clave de configuración» y escribe: <strong><code><?= e(trim(chunk_split($secreto, 4, ' '))) ?></code></strong></p>
  <ol start="3"><li>Escribe aquí el código de 6 cifras que te muestra la app.</li></ol>
  <?php else: ?>
  <h2>Código de verificación</h2>
  <p class="nota">Abre tu app de autenticación y escribe el código de INDA.</p>
  <?php endif ?>
  <label>Código <input name="codigo" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus></label>
  <button>Entrar</button>
  <p class="nota">Marca «Mantener la sesión abierta» al entrar y en este dispositivo no te lo volverá a pedir. ¿Has perdido el móvil? Otro administrador puede quitarte la app desde tu ficha.</p>
</form>
<?php if ($nuevo): ?><script src="assets/qrcode.js"></script><?php endif ?>
<?php pie();
