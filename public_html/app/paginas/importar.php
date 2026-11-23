<?php
// Alta masiva desde Excel/CSV: 1) subir y revisar (no se guarda nada), 2) confirmar.
$yo = requiere('admin');
if (es_post()) {
    try {
        $que = $_POST['que'] ?? '';
        if ($que === 'leer') {
            $f = $_FILES['archivo'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) throw new DomainException('Elige el Excel o CSV (máximo 5 MB).');
            [$filas, $cols, $ign] = filas_importables(leer_hoja($f['tmp_name'], strtolower(pathinfo($f['name'], PATHINFO_EXTENSION))));
            $_SESSION['importacion'] = preparar_importacion(db(), $filas) + ['columnas' => $cols, 'ignoradas' => $ign, 'archivo' => basename($f['name'])];
        } elseif ($que === 'aplicar' && ($plan = $_SESSION['importacion'] ?? null)) {
            $res = aplicar_importacion(db(), $plan, isset($_POST['actualizar']));
            unset($_SESSION['importacion']);
            $enviados = 0;
            if (isset($_POST['bienvenida'])) foreach ($res['nuevos'] as $uid) $enviados += enviar_bienvenida(db(), usuario(db(), $uid));
            auditar(db(), (int)$yo['id'], 'importacion_usuarios', 'nuevos=' . count($res['nuevos']) . " actualizados={$res['actualizados']}");
            aviso(count($res['nuevos']) . ' personas dadas de alta' . ($res['actualizados'] ? " y {$res['actualizados']} actualizadas" : '') . '.'
                . (isset($_POST['bienvenida']) ? " Emails de bienvenida enviados: $enviados." : '')
                . ($res['ascendidos'] ? ' Pasan a responsable (tienen equipo a su cargo): ' . implode(', ', $res['ascendidos']) . '.' : ''));
            redirigir('usuarios');
        } else {
            unset($_SESSION['importacion']);
        }
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('importar');
}
$plan = $_SESSION['importacion'] ?? null;
cabecera('Importar personas', $yo);
?>
<?php if (!$plan): ?>
<form method="post" enctype="multipart/form-data" class="tarjeta">
  <?= csrf_campo() ?>
  <p>Sube un Excel (.xlsx) o CSV con una fila de cabeceras. Sirve la exportación de usuarios de Woffu tal cual.</p>
  <p class="nota">Columnas que se reconocen: Nombre y Apellidos (o Usuario / Nombre completo), Email, DNI, Nº de empleado, Centro, Departamento, Cargo o Puesto, Horario, Responsable, Rol (Admin / Responsable / Empleado) y Usuario suspendido. Las demás se ignoran.</p>
  <label>Archivo <input type="file" name="archivo" accept=".xlsx,.csv,.txt" required></label>
  <button name="que" value="leer">Revisar antes de importar</button>
</form>
<?php else:
    $n = array_count_values(array_column($plan['filas'], 'estado')); ?>
<div class="cifras">
  <div><span>Nuevas</span><strong><?= $n['nuevo'] ?? 0 ?></strong></div>
  <div><span>Ya existen</span><strong><?= $n['actualizar'] ?? 0 ?></strong></div>
  <div><span>Con errores</span><strong class="<?= !empty($n['error']) ? 'error' : '' ?>"><?= $n['error'] ?? 0 ?></strong></div>
</div>
<div class="tarjeta">
  <h2><?= e($plan['archivo']) ?></h2>
  <?php foreach (['centros' => 'Centros', 'departamentos' => 'Departamentos', 'puestos' => 'Puestos (cargos)'] as $k => $t): if ($plan['crear'][$k]): ?>
  <p><strong>Se crearán <?= mb_strtolower($t) ?>:</strong> <?= e(implode(' · ', $plan['crear'][$k])) ?></p>
  <?php endif; endforeach ?>
  <?php if ($plan['horarios_desconocidos']): ?><p class="error">Horarios que aún no existen en INDA (esas personas quedan sin horario; créalos en Horarios y asígnalos después): <?= e(implode(' · ', $plan['horarios_desconocidos'])) ?></p><?php endif ?>
  <?php if ($plan['responsables_desconocidos']): ?><p class="error">Responsables que no encuentro por su nombre (quedan sin responsable): <?= e(implode(' · ', $plan['responsables_desconocidos'])) ?></p><?php endif ?>
  <?php if ($plan['ignoradas']): ?><p class="nota">Columnas que no se usan: <?= e(implode(', ', $plan['ignoradas'])) ?>.</p><?php endif ?>
</div>
<table>
  <tr><th>Fila</th><th>Persona</th><th>Email</th><th>Rol</th><th>Puesto</th><th>Departamento</th><th>Responsable</th><th>Estado</th></tr>
  <?php foreach ($plan['filas'] as $f): ?>
  <tr class="<?= $f['activo'] ? '' : 'anulado' ?>"><td><?= $f['fila'] ?></td><td><?= e(trim($f['nombre'] . ' ' . $f['apellidos'])) ?></td><td><?= e($f['email']) ?></td>
    <td><?= e($f['rol']) ?></td><td><?= e($f['puesto'] ?? '') ?></td><td><?= e($f['departamento'] ?? '') ?></td><td><?= e($f['responsable'] ?? '') ?></td>
    <td class="<?= $f['estado'] === 'error' ? 'error' : '' ?>"><?= ['nuevo' => 'Nueva', 'actualizar' => 'Ya existe', 'error' => '⛔ ' . implode(', ', $f['errores'])][$f['estado']] ?><?= $f['activo'] ? '' : ' · de baja' ?></td></tr>
  <?php endforeach ?>
</table>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <label><input type="checkbox" name="actualizar" checked> Actualizar los datos de las personas que ya existen (no cambia contraseñas ni quita el rol de administrador)</label>
  <label><input type="checkbox" name="bienvenida" checked> Enviar a las nuevas un email de bienvenida para que elijan su contraseña (necesita el correo configurado en Empresa → Correo)</label>
  <p class="nota">Las filas con ⛔ no se importan. Revisa los nombres: si el archivo solo trae «Nombre completo», la primera palabra se toma como nombre y el resto como apellidos.</p>
  <button name="que" value="aplicar">Importar <?= ($n['nuevo'] ?? 0) + ($n['actualizar'] ?? 0) ?> personas</button>
  <button name="que" value="cancelar" class="secundario">Cancelar</button>
</form>
<?php endif;
pie();
