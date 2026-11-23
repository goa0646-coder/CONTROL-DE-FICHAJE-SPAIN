<?php
$u = requiere(...ROLES);
if (es_post()) {
    if (!password_verify($_POST['actual'] ?? '', $u['password_hash'])) {
        aviso('La contraseña actual no es correcta.', 'error');
    } elseif (strlen($_POST['nueva'] ?? '') < 8) {
        aviso('La nueva contraseña debe tener al menos 8 caracteres.', 'error');
    } else {
        db()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash($_POST['nueva'], PASSWORD_DEFAULT), $u['id']]);
        auditar(db(), (int)$u['id'], 'cambio_clave');
        aviso('Contraseña cambiada.');
    }
    redirigir('clave');
}
cabecera('Cambiar contraseña', $u);
?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <label>Contraseña actual <input type="password" name="actual" required autocomplete="current-password"></label>
  <label>Nueva (mínimo 8) <input type="password" name="nueva" required minlength="8" autocomplete="new-password"></label>
  <button>Guardar</button>
</form>
<?php pie();
