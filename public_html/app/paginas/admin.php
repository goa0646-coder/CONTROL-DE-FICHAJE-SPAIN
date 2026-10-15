<?php
$yo = requiere('admin');
$gps = ['no' => 'Sin GPS', 'opcional' => 'Opcional', 'obligatorio' => 'Obligatorio'];

if (es_post()) {
    if (isset($_POST['empresa'])) {
        db()->prepare('INSERT INTO empresa VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), cif = VALUES(cif)')
            ->execute([trim($_POST['nombre']), trim($_POST['cif'])]);
        auditar(db(), (int)$yo['id'], 'empresa_editada');
    } else {
        $num = fn($k, $min, $max) => ($_POST[$k] ?? '') === '' ? null : filter_var($_POST[$k], FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        $d = [trim($_POST['nombre'] ?? ''), trim($_POST['direccion'] ?? ''), $num('lat', -90, 90), $num('lng', -180, 180),
            max(10, (int)($_POST['radio_m'] ?? 150)), isset($gps[$_POST['gps_modo'] ?? '']) ? $_POST['gps_modo'] : 'no', isset($_POST['activo']) ? 1 : 0];
        if ($d[0] === '' || $d[2] === false || $d[3] === false) {
            aviso('Revisa el nombre y las coordenadas.', 'error');
            redirigir('admin');
        }
        if ($id = (int)($_POST['id'] ?? 0)) {
            db()->prepare('UPDATE centros SET nombre=?, direccion=?, lat=?, lng=?, radio_m=?, gps_modo=?, activo=? WHERE id=?')->execute([...$d, $id]);
        } else {
            db()->prepare('INSERT INTO centros (nombre, direccion, lat, lng, radio_m, gps_modo, activo) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute($d);
        }
        auditar(db(), (int)$yo['id'], 'centro_guardado', $d[0]);
    }
    aviso('Guardado.');
    redirigir('admin');
}

$empresa = db()->query('SELECT * FROM empresa')->fetch() ?: ['nombre' => '', 'cif' => ''];
$centros = db()->query('SELECT * FROM centros ORDER BY nombre')->fetchAll();
$c = isset($_GET['c']) ? centro(db(), (int)$_GET['c']) : null;
$c ??= ['id' => '', 'nombre' => '', 'direccion' => '', 'lat' => '', 'lng' => '', 'radio_m' => 150, 'gps_modo' => 'no', 'activo' => 1];
cabecera('Empresa y centros', $yo);
?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Empresa</h2>
  <label>Nombre <input name="nombre" value="<?= e($empresa['nombre']) ?>" required></label>
  <label>CIF <input name="cif" value="<?= e($empresa['cif']) ?>"></label>
  <button name="empresa" value="1">Guardar</button>
</form>

<h2>Centros</h2>
<table>
  <tr><th>Nombre</th><th>GPS</th><th>Radio</th><th></th></tr>
  <?php foreach ($centros as $x): ?>
  <tr class="<?= $x['activo'] ? '' : 'anulado' ?>"><td><?= e($x['nombre']) ?></td><td><?= $gps[$x['gps_modo']] ?></td><td><?= e($x['radio_m']) ?> m</td>
    <td><a href="?p=admin&c=<?= e($x['id']) ?>">Editar</a></td></tr>
  <?php endforeach ?>
</table>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>">
  <h2><?= $c['id'] ? 'Editar centro' : 'Nuevo centro' ?></h2>
  <label>Nombre <input name="nombre" value="<?= e($c['nombre']) ?>" required></label>
  <label>Dirección <input name="direccion" value="<?= e($c['direccion']) ?>"></label>
  <label>Latitud <input name="lat" value="<?= e($c['lat']) ?>" inputmode="decimal" placeholder="40.416800"></label>
  <label>Longitud <input name="lng" value="<?= e($c['lng']) ?>" inputmode="decimal" placeholder="-3.703800"></label>
  <label>Radio del centro (metros) <input type="number" name="radio_m" min="10" value="<?= e($c['radio_m']) ?>"></label>
  <label>GPS al fichar <select name="gps_modo"><?= opciones($gps, $c['gps_modo']) ?></select></label>
  <label><input type="checkbox" name="activo" <?= $c['activo'] ? 'checked' : '' ?>> Activo</label>
  <button>Guardar centro</button>
  <p class="nota">Coordenadas: en Google Maps, clic derecho sobre el centro → copia los dos números.</p>
</form>
<?php pie();
