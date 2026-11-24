<?php
// Importar desde Excel: un libro con hojas Personas, Turnos, Ratios, Festivos, Ausencias, Departamentos y Puestos
// (o una sola hoja de personas, como la exportación de Woffu). 1) subir y revisar (no se guarda nada), 2) confirmar.
$yo = requiere('admin');
if (isset($_GET['plantilla'])) {
    auditar(db(), (int)$yo['id'], 'plantilla_excel_descargada');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="INDA-datos-' . local(ahora(), 'Y-m-d') . '.xlsx"');
    exit(escribir_xlsx(hojas_actuales(db())));
}
if (es_post()) {
    try {
        $que = $_POST['que'] ?? '';
        if ($que === 'leer') {
            $f = $_FILES['archivo'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 10 * 1024 * 1024) throw new DomainException('Elige el Excel o CSV (máximo 10 MB).');
            $_SESSION['importacion'] = preparar_libro(db(), leer_libro($f['tmp_name'], strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)))) + ['archivo' => basename($f['name'])];
        } elseif ($que === 'aplicar' && ($plan = $_SESSION['importacion'] ?? null)) {
            $res = aplicar_libro(db(), $yo, $plan, isset($_POST['actualizar']));
            unset($_SESSION['importacion']);
            $enviados = 0;
            if (isset($_POST['bienvenida'])) foreach ($res['_nuevos'] ?? [] as $uid) $enviados += enviar_bienvenida(db(), usuario(db(), $uid));
            unset($res['_nuevos']);
            $nombres = ['departamentos' => 'Departamentos', 'puestos' => 'Puestos', 'turnos' => 'Turnos', 'personas' => 'Personas', 'ratios' => 'Ratios', 'festivos' => 'Festivos', 'ausencias' => 'Ausencias'];
            aviso('Importado. ' . implode(' · ', array_map(fn($k, $v) => ($nombres[$k] ?? $k) . ": $v", array_keys($res), $res)) . '.'
                . (isset($_POST['bienvenida']) && $enviados ? " Emails de bienvenida: $enviados." : ''));
            redirigir('importar');
        } else {
            unset($_SESSION['importacion']);
        }
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('importar');
}
$plan = $_SESSION['importacion'] ?? null;
$titulos = ['personas' => 'Personas', 'turnos' => 'Turnos', 'ratios' => 'Ratios (mínimos por turno)', 'festivos' => 'Festivos', 'ausencias' => 'Ausencias (vacaciones, bajas, permisos)', 'departamentos' => 'Departamentos', 'puestos' => 'Puestos'];
cabecera('Importar desde Excel', $yo);
?>
<?php if (!$plan): ?>
<div class="tarjeta">
  <h2>1. Descarga la plantilla</h2>
  <p>Un Excel con una hoja para cada cosa: <strong>Personas, Turnos, Ratios, Festivos, Ausencias, Departamentos y Puestos</strong>, ya rellena con lo que tienes ahora en INDA (o con ejemplos si está vacío). Cámbiala en Excel y súbela.</p>
  <a class="boton secundario" href="?p=importar&plantilla=1">Descargar plantilla con mis datos (.xlsx)</a>
</div>
<form method="post" enctype="multipart/form-data" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>2. Sube el Excel</h2>
  <p class="nota">Puede traer todas las hojas o solo las que quieras. También sirve un Excel o CSV con una sola hoja de personas (por ejemplo, la exportación de usuarios de Woffu tal cual). Si es un .xls antiguo, guárdalo antes como .xlsx.</p>
  <label>Archivo <input type="file" name="archivo" accept=".xlsx,.xls,.csv,.txt" required></label>
  <button name="que" value="leer">Revisar antes de importar</button>
</form>
<?php else: ?>
<h2><?= e($plan['archivo']) ?></h2>
<div class="cifras">
  <?php foreach ($plan['secciones'] as $sec => $s): $mal = count(array_filter($s['filas'], fn($f) => !empty($f['errores']) || ($f['estado'] ?? '') === 'error')); ?>
  <div><span><?= e($titulos[$sec]) ?></span><strong><?= count($s['filas']) - $mal ?></strong><?= $mal ? '<span class="error">' . $mal . ' con errores</span>' : '' ?></div>
  <?php endforeach ?>
</div>
<?php if ($plan['ignoradas']): ?><p class="nota">Hojas que no se usan: <?= e(implode(', ', $plan['ignoradas'])) ?>.</p><?php endif ?>

<?php foreach ($plan['secciones'] as $sec => $s): ?>
<details class="tarjeta" <?= array_filter($s['filas'], fn($f) => !empty($f['errores'])) || !empty($s['error']) ? 'open' : '' ?>>
  <summary><?= e($titulos[$sec]) ?> · <?= count($s['filas']) ?> filas<?= isset($plan['hojas'][$sec]) ? ' (hoja «' . e($plan['hojas'][$sec]) . '»)' : '' ?></summary>
  <?php if (!empty($s['error'])): ?><p class="error"><?= e($s['error']) ?></p><?php endif ?>
  <?php if ($sec === 'personas' && !empty($s['previa'])): $p = $s['previa']; $n = array_count_values(array_column($p['filas'], 'estado')); ?>
    <p><?= $n['nuevo'] ?? 0 ?> nuevas · <?= $n['actualizar'] ?? 0 ?> ya existen<?= !empty($n['error']) ? ' · <span class="error">' . $n['error'] . ' con errores</span>' : '' ?></p>
    <?php foreach (['centros' => 'Centros', 'departamentos' => 'Departamentos', 'puestos' => 'Puestos'] as $k => $t): if ($p['crear'][$k]): ?><p class="nota">Se crearán <?= mb_strtolower($t) ?>: <?= e(implode(' · ', $p['crear'][$k])) ?></p><?php endif; endforeach ?>
    <?php if ($p['horarios_desconocidos']): $nuevos_turnos = array_map('normalizar', array_column($plan['secciones']['turnos']['filas'] ?? [], 'nombre'));
        $faltan = array_filter($p['horarios_desconocidos'], fn($h) => !in_array(normalizar($h), $nuevos_turnos, true)); ?>
      <?php if ($faltan): ?><p class="error">Horarios que no existen ni vienen en la hoja Turnos (quedan sin horario): <?= e(implode(' · ', $faltan)) ?></p><?php endif ?>
    <?php endif ?>
    <?php if ($p['responsables_desconocidos']): ?><p class="error">Responsables que no encuentro (quedan sin responsable): <?= e(implode(' · ', $p['responsables_desconocidos'])) ?></p><?php endif ?>
    <table>
      <tr><th>Fila</th><th>Persona</th><th>Email</th><th>Departamento</th><th>Horario</th><th>Estado</th></tr>
      <?php foreach ($p['filas'] as $f): ?>
      <tr class="<?= $f['activo'] ? '' : 'anulado' ?>"><td><?= $f['fila'] ?></td><td><?= e(trim($f['nombre'] . ' ' . $f['apellidos'])) ?></td><td><?= e($f['email']) ?></td><td><?= e($f['departamento'] ?? '') ?></td><td><?= e($f['horario'] ?? '') ?></td>
        <td class="<?= $f['estado'] === 'error' ? 'error' : '' ?>"><?= ['nuevo' => 'Nueva', 'actualizar' => 'Ya existe', 'error' => '⛔ ' . implode(', ', $f['errores'])][$f['estado']] ?></td></tr>
      <?php endforeach ?>
    </table>
  <?php elseif ($s['filas']): $cols = array_values(array_diff(array_keys(array_merge(...array_map(fn($f) => $f, $s['filas']))), ['fila', 'errores'])); ?>
    <table>
      <tr><th>Fila</th><?php foreach ($cols as $c): ?><th><?= e($c) ?></th><?php endforeach ?><th></th></tr>
      <?php foreach (array_slice($s['filas'], 0, 300) as $f): ?>
      <tr><td><?= $f['fila'] ?></td><?php foreach ($cols as $c): ?><td><?= e($f[$c] ?? '') ?></td><?php endforeach ?>
        <td class="<?= $f['errores'] ? 'error' : '' ?>"><?= $f['errores'] ? '⛔ ' . e(implode(', ', $f['errores'])) : '✔' ?></td></tr>
      <?php endforeach ?>
    </table>
  <?php endif ?>
</details>
<?php endforeach ?>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <?php if (isset($plan['secciones']['personas'])): ?>
  <label><input type="checkbox" name="actualizar" checked> Actualizar las personas que ya existen (no cambia contraseñas ni quita el rol de administrador)</label>
  <label><input type="checkbox" name="bienvenida"> Enviar a las nuevas un email de bienvenida para que elijan su contraseña</label>
  <?php endif ?>
  <p class="nota">Se aplica en este orden: departamentos y puestos, turnos, personas, ratios, festivos y ausencias. Las filas con ⛔ no se importan. Las ausencias entran como aprobadas salvo que pongas «Pendiente» en Estado.</p>
  <button name="que" value="aplicar">Importar</button>
  <button name="que" value="cancelar" class="secundario">Cancelar</button>
</form>
<?php endif;
pie();
