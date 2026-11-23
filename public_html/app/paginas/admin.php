<?php
$yo = requiere('admin');
$gps = ['no' => 'Sin GPS', 'opcional' => 'Opcional', 'obligatorio' => 'Obligatorio'];
$fuera = ['aceptar' => 'Aceptar y marcar «fuera»', 'rechazar' => 'No dejar fichar'];

if (es_post()) {
    $que = $_POST['que'] ?? '';
    if ($que === 'empresa') {
        $horas = ($_POST['horas_anuales'] ?? '') === '' ? null : max(0, (int)$_POST['horas_anuales']);
        db()->prepare('INSERT INTO empresa (id, nombre, cif, horas_anuales) VALUES (1, ?, ?, ?) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), cif = VALUES(cif), horas_anuales = VALUES(horas_anuales)')
            ->execute([trim($_POST['nombre']), trim($_POST['cif']), $horas]);
        auditar(db(), (int)$yo['id'], 'empresa_editada');
    } elseif ($que === 'departamento') {
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre === '') {
            aviso('Pon un nombre.', 'error');
            redirigir('admin');
        }
        if ($id = (int)($_POST['id'] ?? 0)) consulta(db(), 'UPDATE departamentos SET nombre = ?, activo = ? WHERE id = ?', [$nombre, isset($_POST['activo']) ? 1 : 0, $id]);
        else consulta(db(), 'INSERT INTO departamentos (nombre) VALUES (?)', [$nombre]);
        auditar(db(), (int)$yo['id'], 'departamento_guardado', $nombre);
    } elseif ($que === 'permisos') {
        db()->exec('DELETE FROM permisos_rol');
        foreach (['responsable', 'empleado', 'inspeccion'] as $rol) {
            // Inspección es solo lectura: como mucho, informes.
            foreach (array_keys(PERMISOS) as $p) if (isset($_POST["$rol:$p"]) && ($rol !== 'inspeccion' || $p === 'informes')) consulta(db(), 'INSERT INTO permisos_rol VALUES (?, ?)', [$rol, $p]);
        }
        auditar(db(), (int)$yo['id'], 'permisos_editados');
    } else {
        $num = fn($k, $min, $max) => ($_POST[$k] ?? '') === '' ? null : filter_var($_POST[$k], FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        $d = [trim($_POST['nombre'] ?? ''), trim($_POST['direccion'] ?? ''), $num('lat', -90, 90), $num('lng', -180, 180),
            max(10, (int)($_POST['radio_m'] ?? 150)), isset($gps[$_POST['gps_modo'] ?? '']) ? $_POST['gps_modo'] : 'no',
            isset($fuera[$_POST['fuera_zona'] ?? '']) ? $_POST['fuera_zona'] : 'aceptar', mb_substr(trim($_POST['ips'] ?? ''), 0, 500), isset($_POST['activo']) ? 1 : 0];
        if ($d[0] === '' || $d[2] === false || $d[3] === false) {
            aviso('Revisa el nombre y las coordenadas.', 'error');
            redirigir('admin');
        }
        if ($id = (int)($_POST['id'] ?? 0)) {
            db()->prepare('UPDATE centros SET nombre=?, direccion=?, lat=?, lng=?, radio_m=?, gps_modo=?, fuera_zona=?, ips=?, activo=? WHERE id=?')->execute([...$d, $id]);
        } else {
            db()->prepare('INSERT INTO centros (nombre, direccion, lat, lng, radio_m, gps_modo, fuera_zona, ips, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($d);
        }
        auditar(db(), (int)$yo['id'], 'centro_guardado', $d[0]);
    }
    aviso('Guardado.');
    redirigir('admin');
}

$empresa = db()->query('SELECT * FROM empresa')->fetch() ?: ['nombre' => '', 'cif' => '', 'horas_anuales' => null];
$centros = db()->query('SELECT * FROM centros ORDER BY nombre')->fetchAll();
$deps = db()->query('SELECT * FROM departamentos ORDER BY activo DESC, nombre')->fetchAll();
$permisos = db()->query('SELECT CONCAT(rol, ":", permiso) FROM permisos_rol')->fetchAll(PDO::FETCH_COLUMN);
$c = isset($_GET['c']) ? centro(db(), (int)$_GET['c']) : null;
$c ??= ['id' => '', 'nombre' => '', 'direccion' => '', 'lat' => '', 'lng' => '', 'radio_m' => 150, 'gps_modo' => 'no', 'fuera_zona' => 'aceptar', 'ips' => '', 'activo' => 1];
cabecera('Empresa y centros', $yo);
?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Empresa</h2>
  <label>Nombre <input name="nombre" value="<?= e($empresa['nombre']) ?>" required></label>
  <label>CIF <input name="cif" value="<?= e($empresa['cif']) ?>"></label>
  <label>Horas anuales del convenio <input type="number" name="horas_anuales" min="0" value="<?= e($empresa['horas_anuales']) ?>" placeholder="p. ej. 1780"></label>
  <button name="que" value="empresa">Guardar</button>
</form>

<h2>Centros</h2>
<table>
  <tr><th>Nombre</th><th>GPS</th><th>Radio</th><th>Fuera de zona</th><th>Redes</th><th></th></tr>
  <?php foreach ($centros as $x): ?>
  <tr class="<?= $x['activo'] ? '' : 'anulado' ?>"><td><?= e($x['nombre']) ?></td><td><?= $gps[$x['gps_modo']] ?></td><td><?= e($x['radio_m']) ?> m</td>
    <td><?= $fuera[$x['fuera_zona']] ?></td><td><?= e($x['ips'] ?: 'Cualquiera') ?></td>
    <td><a href="?p=admin&c=<?= e($x['id']) ?>">Editar</a> · <a href="?p=cartel&c=<?= e($x['id']) ?>">Cartel QR</a></td></tr>
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
  <label>Si fichan fuera del radio <select name="fuera_zona"><?= opciones($fuera, $c['fuera_zona']) ?></select></label>
  <label>Redes permitidas (IP o inicio de IP, separadas por comas; vacío = cualquiera) <input name="ips" value="<?= e($c['ips']) ?>" placeholder="88.12.34.56, 10.0."></label>
  <label><input type="checkbox" name="activo" <?= $c['activo'] ? 'checked' : '' ?>> Activo</label>
  <button>Guardar centro</button>
  <p class="nota">Coordenadas: en Google Maps, clic derecho sobre el centro → copia los dos números. Tu IP ahora: <?= e(ip()) ?>. El quiosco no tiene límite de red.</p>
</form>

<h2>Departamentos</h2>
<?php foreach ($deps as $d): ?>
<form method="post" class="filtros">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($d['id']) ?>">
  <label><input name="nombre" value="<?= e($d['nombre']) ?>" required></label>
  <label><input type="checkbox" name="activo" <?= $d['activo'] ? 'checked' : '' ?>> Activo</label>
  <button name="que" value="departamento" class="secundario">Guardar</button>
</form>
<?php endforeach ?>
<form method="post" class="filtros">
  <?= csrf_campo() ?>
  <label><input name="nombre" placeholder="Nuevo departamento" required></label>
  <button name="que" value="departamento">Añadir</button>
</form>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Permisos por rol</h2>
  <p class="nota">El administrador lo puede todo. El responsable solo actúa sobre su equipo.</p>
  <table>
    <tr><th>Permiso</th><th>Responsable</th><th>Empleado</th><th>Inspección</th></tr>
    <?php foreach (PERMISOS as $p => $txt): ?>
    <tr><td><?= e($txt) ?></td>
      <?php foreach (['responsable', 'empleado', 'inspeccion'] as $rol): ?>
      <td><?php if ($rol !== 'inspeccion' || $p === 'informes'): ?><input type="checkbox" name="<?= "$rol:$p" ?>" <?= in_array("$rol:$p", $permisos, true) ? 'checked' : '' ?>><?php endif ?></td>
      <?php endforeach ?></tr>
    <?php endforeach ?>
  </table>
  <button name="que" value="permisos">Guardar permisos</button>
</form>
<?php pie();
