<?php
if (es_post()) {
    $r = login(db(), $_POST['email'] ?? '', $_POST['clave'] ?? '');
    if (is_string($r)) {
        aviso($r, 'error');
        redirigir('login');
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = $r['id'];
    auditar(db(), (int)$r['id'], 'login');
    redirigir(inicio_de($r));
}
cabecera('Entrar');
?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <label>Email <input type="email" name="email" required autocomplete="username"></label>
  <label>Contraseña <input type="password" name="clave" required autocomplete="current-password"></label>
  <button>Entrar</button>
  <p><a href="?p=recuperar">¿Has olvidado la contraseña?</a></p>
</form>
<?php pie();
