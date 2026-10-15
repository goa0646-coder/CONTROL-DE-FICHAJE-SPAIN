<?php
$token = $_GET['t'] ?? '';
if ($token !== '') {
    $s = db()->prepare('SELECT * FROM usuarios WHERE reset_hash = ? AND reset_expira_utc > ? AND activo = 1');
    $s->execute([hash('sha256', $token), ahora()]);
    $u = $s->fetch();
    if (!$u) {
        aviso('El enlace no es válido o ha caducado.', 'error');
        redirigir('recuperar');
    }
    if (es_post()) {
        if (strlen($_POST['nueva'] ?? '') < 8) {
            aviso('Mínimo 8 caracteres.', 'error');
            redirigir('recuperar', ['t' => $token]);
        }
        db()->prepare('UPDATE usuarios SET password_hash = ?, reset_hash = NULL, reset_expira_utc = NULL WHERE id = ?')
            ->execute([password_hash($_POST['nueva'], PASSWORD_DEFAULT), $u['id']]);
        auditar(db(), (int)$u['id'], 'recuperar_clave');
        aviso('Contraseña cambiada. Ya puedes entrar.');
        redirigir('login');
    }
    cabecera('Nueva contraseña');
    ?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <label>Nueva contraseña (mínimo 8) <input type="password" name="nueva" required minlength="8" autocomplete="new-password"></label>
  <button>Guardar</button>
</form>
<?php
    pie();
    return;
}

if (es_post()) {
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $s = db()->prepare('SELECT id FROM usuarios WHERE email = ? AND activo = 1');
    $s->execute([$email]);
    if (($id = $s->fetchColumn()) && !bloqueado(db(), 'reset:' . $email)) {
        fallo(db(), 'reset:' . $email); // máximo 5 correos cada 15 min
        $t = bin2hex(random_bytes(32));
        db()->prepare('UPDATE usuarios SET reset_hash = ?, reset_expira_utc = ? WHERE id = ?')
            ->execute([hash('sha256', $t), gmdate('Y-m-d H:i:s', time() + 3600), $id]);
        $c = config();
        mail($email, 'Cambiar tu contraseña de fichaje',
            "Para elegir una contraseña nueva entra aquí (válido 1 hora):\n{$c['url']}/?p=recuperar&t=$t\n\nSi no lo has pedido tú, ignora este correo.",
            "From: {$c['correo_desde']}\r\nContent-Type: text/plain; charset=utf-8");
    }
    aviso('Si el email existe, te hemos enviado un enlace.');
    redirigir('login');
}
cabecera('Recuperar contraseña');
?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <label>Tu email <input type="email" name="email" required></label>
  <button>Enviar enlace</button>
</form>
<?php pie();
