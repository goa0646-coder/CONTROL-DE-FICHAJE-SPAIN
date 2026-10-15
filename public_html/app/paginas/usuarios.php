<?php
$yo = requiere('admin');
$roles = ['empleado' => 'Empleado', 'responsable' => 'Responsable', 'admin' => 'Administrador', 'inspeccion' => 'Inspección (solo lectura)'];
$gps = ['' => 'Lo que diga su centro', 'no' => 'Sin GPS', 'opcional' => 'Opcional', 'obligatorio' => 'Obligatorio'];

if (es_post()) {
    $id = (int)($_POST['id'] ?? 0);
    $p = array_map(fn($v) => is_string($v) ? trim($v) : $v, $_POST);
    $error = match (true) {
        $p['nombre'] === '' => 'El nombre es obligatorio.',
        !filter_var($p['email'], FILTER_VALIDATE_EMAIL) => 'El email no es válido.',
        !isset($roles[$p['rol']]) => 'Rol no válido.',
        !$id && strlen($p['clave']) < 8, $p['clave'] !== '' && strlen($p['clave']) < 8 => 'La contraseña debe tener al menos 8 caracteres.',
        $p['pin'] !== '' && !preg_match('/^\d{4,8}$/', $p['pin']) => 'El PIN debe tener de 4 a 8 cifras.',
        $p['pin'] !== '' && $p['num_empleado'] === '' => 'Para usar el quiosco hace falta nº de empleado.',
        $id && $id == $yo['id'] && ($p['rol'] !== 'admin' || !isset($p['activo'])) => 'No puedes quitarte el rol de administrador ni darte de baja.',
        $id && $id == (int)$p['responsable_id'] => 'Una persona no puede ser su propia responsable.',
        default => null,
    };
    if ($error) {
        aviso($error, 'error');
        redirigir('usuarios', $id ? ['u' => $id] : []);
    }
    $antes = $id ? usuario(db(), $id) : null;
    $activo = isset($p['activo']) ? 1 : 0;
    $campos = [
        'num_empleado' => $p['num_empleado'] ?: null, 'nombre' => $p['nombre'], 'apellidos' => $p['apellidos'], 'dni' => strtoupper($p['dni']),
        'email' => mb_strtolower($p['email']), 'rol' => $p['rol'], 'centro_id' => (int)$p['centro_id'] ?: null,
        'responsable_id' => (int)$p['responsable_id'] ?: null, 'gps_modo' => isset($gps[$p['gps_modo']]) && $p['gps_modo'] !== '' ? $p['gps_modo'] : null,
        'acceso_hasta' => $p['rol'] === 'inspeccion' ? ($p['acceso_hasta'] ?: gmdate('Y-m-d', strtotime('+30 days'))) : null,
        'activo' => $activo,
        'baja_utc' => $activo ? null : ($antes['baja_utc'] ?? ahora()),
    ];
    if ($p['clave'] !== '') $campos['password_hash'] = password_hash($p['clave'], PASSWORD_DEFAULT);
    if ($p['pin'] !== '') $campos['pin_hash'] = password_hash($p['pin'], PASSWORD_DEFAULT);
    try {
        if ($id) {
            db()->prepare('UPDATE usuarios SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos))) . ' WHERE id = ?')
                ->execute([...array_values($campos), $id]);
        } else {
            $campos['alta_utc'] = ahora();
            db()->prepare('INSERT INTO usuarios (' . implode(', ', array_keys($campos)) . ') VALUES (' . rtrim(str_repeat('?, ', count($campos)), ', ') . ')')
                ->execute(array_values($campos));
            $id = (int)db()->lastInsertId();
        }
    } catch (PDOException $e) {
        aviso($e->getCode() == 23000 ? 'Ese email o nº de empleado ya existe.' : 'No se pudo guardar.', 'error');
        redirigir('usuarios', $id ? ['u' => $id] : []);
    }
    auditar(db(), (int)$yo['id'], 'usuario_guardado', "usuario=$id rol={$campos['rol']} activo=$activo");
    aviso('Guardado.');
    redirigir('usuarios');
}

$todos = db()->query('SELECT u.*, c.nombre AS centro FROM usuarios u LEFT JOIN centros c ON c.id = u.centro_id ORDER BY u.activo DESC, u.nombre, u.apellidos')->fetchAll();
$centros = db()->query('SELECT id, nombre FROM centros WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
$jefes = [];
foreach ($todos as $x) if (in_array($x['rol'], ['responsable', 'admin'], true) && $x['activo']) $jefes[$x['id']] = nombre($x);
$u = isset($_GET['u']) ? usuario(db(), (int)$_GET['u']) : null;
$u ??= ['id' => '', 'num_empleado' => '', 'nombre' => '', 'apellidos' => '', 'dni' => '', 'email' => '', 'rol' => 'empleado',
    'centro_id' => '', 'responsable_id' => '', 'gps_modo' => '', 'acceso_hasta' => '', 'activo' => 1, 'pin_hash' => null];
cabecera('Usuarios', $yo);
?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($u['id']) ?>">
  <h2><?= $u['id'] ? 'Editar ' . e(nombre($u)) : 'Nueva persona' ?></h2>
  <label>Nombre <input name="nombre" value="<?= e($u['nombre']) ?>" required></label>
  <label>Apellidos <input name="apellidos" value="<?= e($u['apellidos']) ?>"></label>
  <label>DNI/NIE <input name="dni" value="<?= e($u['dni']) ?>"></label>
  <label>Email <input type="email" name="email" value="<?= e($u['email']) ?>" required></label>
  <label>Rol <select name="rol"><?= opciones($roles, $u['rol']) ?></select></label>
  <label>Centro <select name="centro_id"><?= opciones(['' => '—'] + $centros, $u['centro_id']) ?></select></label>
  <label>Responsable <select name="responsable_id"><?= opciones(['' => '—'] + $jefes, $u['responsable_id']) ?></select></label>
  <label>GPS al fichar <select name="gps_modo"><?= opciones($gps, $u['gps_modo'] ?? '') ?></select></label>
  <label>Nº de empleado (quiosco) <input name="num_empleado" value="<?= e($u['num_empleado']) ?>"></label>
  <label>PIN de quiosco (4–8 cifras) <input name="pin" inputmode="numeric" autocomplete="off" placeholder="<?= $u['pin_hash'] ? 'Dejar vacío para no cambiarlo' : '' ?>"></label>
  <label>Contraseña <input type="password" name="clave" autocomplete="new-password" placeholder="<?= $u['id'] ? 'Dejar vacío para no cambiarla' : 'Mínimo 8 caracteres' ?>"></label>
  <label>Acceso hasta (solo Inspección) <input type="date" name="acceso_hasta" value="<?= e($u['acceso_hasta']) ?>"></label>
  <label><input type="checkbox" name="activo" <?= $u['activo'] ? 'checked' : '' ?>> Activo (desmarcar = baja; su registro se conserva)</label>
  <button>Guardar</button>
  <?php if ($u['id']): ?><a href="?p=usuarios">Nueva persona</a><?php endif ?>
</form>

<table>
  <tr><th>Nombre</th><th>Rol</th><th>Centro</th><th>Email</th><th></th></tr>
  <?php foreach ($todos as $x): ?>
  <tr class="<?= $x['activo'] ? '' : 'anulado' ?>">
    <td><?= e(nombre($x)) ?></td><td><?= $roles[$x['rol']] ?></td><td><?= e($x['centro'] ?? '') ?></td><td><?= e($x['email']) ?></td>
    <td><a href="?p=usuarios&u=<?= e($x['id']) ?>">Editar</a></td>
  </tr>
  <?php endforeach ?>
</table>
<?php pie();
