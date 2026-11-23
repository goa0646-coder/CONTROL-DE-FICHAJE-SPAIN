<?php
$yo = requiere('admin');
if (es_post()) {
    if ($id = (int)($_POST['revocar'] ?? 0)) {
        db()->prepare('UPDATE quioscos SET activo = 0 WHERE id = ?')->execute([$id]);
        auditar(db(), (int)$yo['id'], 'quiosco_revocado', "quiosco=$id");
        aviso('Quiosco desactivado.');
        redirigir('quioscos');
    }
    $centro = (int)($_POST['centro_id'] ?? 0);
    if (!$centro || trim($_POST['nombre'] ?? '') === '') {
        aviso('Elige centro y nombre.', 'error');
        redirigir('quioscos');
    }
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO quioscos (centro_id, nombre, token_hash, alta_utc) VALUES (?, ?, ?, ?)')
        ->execute([$centro, trim($_POST['nombre']), hash('sha256', $token), ahora()]);
    auditar(db(), (int)$yo['id'], 'quiosco_activado', 'quiosco=' . db()->lastInsertId());
    setcookie('quiosco', $token, ['expires' => time() + 10 * 365 * 86400, 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Strict']);
    // Este navegador queda como quiosco: se cierra la sesión del admin.
    $_SESSION = [];
    session_destroy();
    header('Location: ?p=quiosco');
    exit;
}
$lista = db()->query('SELECT q.*, c.nombre AS centro FROM quioscos q JOIN centros c ON c.id = q.centro_id ORDER BY q.activo DESC, q.nombre')->fetchAll();
$centros = db()->query('SELECT id, nombre FROM centros WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
cabecera('Quioscos', $yo);
?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Convertir ESTE dispositivo en quiosco</h2>
  <p>Hazlo desde la tablet de la entrada. Se cerrará tu sesión y la tablet quedará fija en la pantalla de fichaje.</p>
  <label>Nombre <input name="nombre" placeholder="Tablet recepción" required></label>
  <label>Centro <select name="centro_id" required><?= opciones(['' => 'Elige…'] + $centros, '') ?></select></label>
  <button>Activar quiosco aquí</button>
</form>
<table>
  <tr><th>Nombre</th><th>Centro</th><th>Alta</th><th></th></tr>
  <?php foreach ($lista as $q): ?>
  <tr class="<?= $q['activo'] ? '' : 'anulado' ?>">
    <td><?= e($q['nombre']) ?></td><td><?= e($q['centro']) ?></td><td><?= local($q['alta_utc']) ?></td>
    <td><?php if ($q['activo']): ?><form method="post"><?= csrf_campo() ?><button name="revocar" value="<?= e($q['id']) ?>" class="secundario">Desactivar</button></form><?php endif ?></td>
  </tr>
  <?php endforeach ?>
</table>
<?php pie();
