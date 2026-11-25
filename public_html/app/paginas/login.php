<?php
if (es_post()) {
    $r = login(db(), $_POST['email'] ?? '', $_POST['clave'] ?? '');
    if (is_string($r)) {
        aviso($r, 'error');
        redirigir('login');
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = $r['id'];
    consulta(db(), 'UPDATE usuarios SET ultimo_acceso_utc = ? WHERE id = ?', [ahora(), $r['id']]);
    if (isset($_POST['recordar']) && ($t = crear_dispositivo(db(), $r, $_SERVER['HTTP_USER_AGENT'] ?? ''))) poner_cookie_dispositivo($t);
    auditar(db(), (int)$r['id'], 'login');
    $volver = $_SESSION['volver'] ?? [];
    unset($_SESSION['volver']);
    if (preg_match('/^[a-z]+$/', $volver['p'] ?? '') && $volver['p'] !== 'login') redirigir($volver['p'], array_diff_key($volver, ['p' => 1]));
    redirigir(inicio_de($r));
}
cabecera('Entrar');
?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <label>Email <input type="email" name="email" required autocomplete="username"></label>
  <label>Contraseña <input type="password" name="clave" required autocomplete="current-password"></label>
  <label><input type="checkbox" name="recordar" checked> Mantener la sesión abierta en este dispositivo</label>
  <p class="nota">Desmárcalo en ordenadores compartidos.</p>
  <button>Entrar</button>
  <p><a href="?p=recuperar">¿Has olvidado la contraseña?</a></p>
</form>
<?php if (!empty(config()['sso']['cliente_id'])): ?>
<p class="centro"><a class="boton secundario" href="?p=sso">Entrar con <?= (config()['sso']['proveedor'] ?? '') === 'microsoft' ? 'Microsoft' : 'Google' ?></a></p>
<?php endif ?>
<?php pie();
