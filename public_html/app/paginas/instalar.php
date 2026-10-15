<?php
// Para hostings sin SSH: ?p=instalar&clave=<clave_instalacion de config-fichaje.php>
require_once __DIR__ . '/../migrar.php';
$clave = config()['clave_instalacion'] ?? '';
if (strlen($clave) < 16 || !hash_equals($clave, (string)($_GET['clave'] ?? ''))) {
    http_response_code(403);
    exit('Clave de instalación incorrecta.');
}
$log = migrar(db());
$hayAdmin = db()->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin'")->fetchColumn() > 0;

if (es_post() && !$hayAdmin) {
    if (!filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) || strlen($_POST['pass'] ?? '') < 8 || trim($_POST['nombre'] ?? '') === '') {
        aviso('Revisa los datos (contraseña mínimo 8).', 'error');
        redirigir('instalar', ['clave' => $_GET['clave']]);
    }
    db()->prepare('INSERT INTO empresa VALUES (1, ?, ?)')->execute([trim($_POST['empresa']), trim($_POST['cif'])]);
    db()->prepare("INSERT INTO usuarios (nombre, email, password_hash, rol, alta_utc) VALUES (?, ?, ?, 'admin', ?)")
        ->execute([trim($_POST['nombre']), mb_strtolower(trim($_POST['email'])), password_hash($_POST['pass'], PASSWORD_DEFAULT), ahora()]);
    auditar(db(), (int)db()->lastInsertId(), 'instalacion');
    aviso('Instalado. Ya puedes entrar.');
    redirigir('login');
}
cabecera('Instalación');
?>
<pre><?= e(implode("\n", $log)) ?></pre>
<?php if (!$hayAdmin): ?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Empresa y primer administrador</h2>
  <label>Empresa <input name="empresa" required></label>
  <label>CIF <input name="cif"></label>
  <label>Tu nombre <input name="nombre" required></label>
  <label>Tu email <input type="email" name="email" required></label>
  <label>Contraseña (mínimo 8) <input type="password" name="pass" minlength="8" required></label>
  <button>Crear</button>
</form>
<?php else: ?>
<p>Base de datos al día. <a href="?p=login">Entrar</a></p>
<?php endif;
pie();
