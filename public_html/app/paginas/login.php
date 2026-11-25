<?php
if (es_post()) {
    $r = login(db(), $_POST['email'] ?? '', $_POST['clave'] ?? '');
    if (is_string($r)) {
        aviso($r, 'error');
        redirigir('login');
    }
    completar_entrada(db(), $r, isset($_POST['recordar']), 'login');
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
