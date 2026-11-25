<?php
// Importar desde Excel: un libro con hojas Personas, Turnos, Ratios, Festivos, Ausencias, Departamentos y Puestos
// (o una sola hoja de personas, como la exportación de Woffu). 1) subir y revisar (no se guarda nada), 2) confirmar.
$yo = requiere('admin');
if (isset($_GET['plantilla'])) {
    auditar(db(), (int)$yo['id'], 'plantilla_excel_descargada');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="INDA-datos-' . local(ahora(), 'Y-m-d') . '.xlsx"');
    $anio = (int)($_GET['cuadrante'] ?? 0);
    exit(escribir_xlsx(hojas_actuales(db()) + ($anio >= 2020 && $anio <= 2100 ? hojas_cuadrante(db(), $anio) : [])));
}
// Informe de lo que no está bien, en CSV (para Excel): el de la revisión en curso o el de la última importación.
if (isset($_GET['informe'])) {
    $filas = ($_GET['informe'] === 'revision' && isset($_SESSION['importacion'])) ? informe_libro($_SESSION['importacion']) : (json_decode(ajuste(db(), 'informe_importacion') ?? '', true)['filas'] ?? []);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="INDA-informe-importacion-' . local(ahora(), 'Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Sección', 'Hoja', 'Fila', 'Persona', 'Día', 'Valor', 'Qué pasa'], ';');
    foreach ($filas as $f) fputcsv($out, $f, ';');
    exit;
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
            $nombres = ['cuadrante' => 'Cuadrante', 'departamentos' => 'Departamentos', 'puestos' => 'Puestos', 'turnos' => 'Turnos', 'personas' => 'Personas', 'ratios' => 'Ratios', 'festivos' => 'Festivos', 'ausencias' => 'Ausencias'];
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
$titulos = ['personas' => 'Personas', 'turnos' => 'Turnos', 'ratios' => 'Ratios (mínimos por turno)', 'festivos' => 'Festivos', 'ausencias' => 'Ausencias (vacaciones, bajas, permisos)', 'departamentos' => 'Departamentos', 'puestos' => 'Puestos', 'cuadrante' => 'Cuadrante (turnos día a día)'];
$ultimo = json_decode(ajuste(db(), 'informe_importacion') ?? '', true);
// Tabla del informe: [sección, hoja, fila, persona, día, valor, motivo].
$tabla_informe = function (array $filas, string $csv) { ?>
  <table class="informe-import">
    <tr><th>Sección</th><th>Hoja</th><th>Fila</th><th>Persona</th><th>Día</th><th>Valor</th><th>Qué pasa</th></tr>
    <?php foreach (array_slice($filas, 0, 400) as $f): ?>
    <tr><td><?= e($f[0]) ?></td><td><?= e($f[1]) ?></td><td><?= e($f[2]) ?></td><td><?= e($f[3]) ?></td><td><?= $f[4] ? e(date('d/m/Y', strtotime($f[4]))) : '' ?></td><td><code><?= e($f[5]) ?></code></td><td class="<?= str_starts_with($f[6], 'Importado') ? '' : 'error' ?>"><?= e($f[6]) ?></td></tr>
    <?php endforeach ?>
  </table>
  <p class="nota"><?= count($filas) > 400 ? 'Se muestran 400 de ' . count($filas) . '. ' : '' ?><a href="?p=importar&informe=<?= $csv ?>">Descargar el informe completo (Excel / CSV)</a></p>
<?php };
cabecera('Importar desde Excel', $yo);
?>
<?php if (!$plan): ?>
<?php if ($ultimo): ?>
<details class="tarjeta" <?= $ultimo['filas'] && $ultimo['fecha'] > gmdate('Y-m-d H:i:s', time() - 3600) ? 'open' : '' ?>>
  <summary>Informe de la última importación · <?= local($ultimo['fecha']) ?> · <?= e($ultimo['archivo']) ?> · <?= $ultimo['filas'] ? count($ultimo['filas']) . ' cosas a revisar' : 'todo correcto' ?></summary>
  <p class="nota"><?= e(implode(' · ', array_map(fn($k, $v) => ucfirst($k) . ": $v", array_keys($ultimo['resumen']), $ultimo['resumen']))) ?></p>
  <?php if ($ultimo['filas']) $tabla_informe($ultimo['filas'], 'ultimo'); ?>
</details>
<?php endif ?>
<div class="tarjeta">
  <h2>1. Descarga la plantilla</h2>
  <p>Un Excel con una hoja para cada cosa: <strong>Personas, Turnos, Ratios, Festivos, Ausencias, Departamentos y Puestos</strong>, ya rellena con lo que tienes ahora en INDA (o con ejemplos si está vacío). Cámbiala en Excel y súbela.</p>
  <form class="filtros">
    <input type="hidden" name="p" value="importar"><input type="hidden" name="plantilla" value="1">
    <label>Con el cuadrante mes a mes de <select name="cuadrante"><option value="">(sin cuadrante)</option><?php for ($a = (int)local(ahora(), 'Y') - 1; $a <= (int)local(ahora(), 'Y') + 1; $a++): ?><option value="<?= $a ?>" <?= $a === (int)local(ahora(), 'Y') + 1 ? 'selected' : '' ?>><?= $a ?></option><?php endfor ?></select></label>
    <button class="secundario">Descargar plantilla con mis datos (.xlsx)</button>
  </form>
  <p class="nota">El cuadrante va en una hoja por mes («Enero 2027»…): una fila por persona y una columna por día, con la letra del turno (M, T, N, P…), D = descanso o la ausencia (V vacaciones, IT baja, LD libre disposición, AP asuntos propios).</p>
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
<form method="post" enctype="multipart/form-data" class="tarjeta filtros">
  <?= csrf_campo() ?>
  <span class="nota">¿No es el archivo bueno? Sube otro: sustituye a este en la revisión. Al importar, lo nuevo corrige lo anterior (no se duplica nada).</span>
  <label>Otro archivo <input type="file" name="archivo" accept=".xlsx,.xls,.csv,.txt" required></label>
  <button name="que" value="leer" class="secundario">Revisar este otro</button>
</form>
<div class="cifras">
  <?php foreach ($plan['secciones'] as $sec => $s): $mal = $sec === 'cuadrante' ? array_sum($s['resumen']['dudas']) + array_sum($s['resumen']['sin_persona']) : count(array_filter($s['filas'], fn($f) => !empty($f['errores']) || ($f['estado'] ?? '') === 'error')); ?>
  <div><span><?= e($titulos[$sec]) ?></span><strong><?= $sec === 'cuadrante' ? array_sum($s['resumen']['cuenta']) . ' días' : count($s['filas']) - $mal ?></strong><?= $mal ? '<span class="error">' . $mal . ' con errores</span>' : '' ?></div>
  <?php endforeach ?>
</div>
<?php if ($plan['ignoradas']): ?><p class="nota">Hojas que no se usan: <?= e(implode(', ', $plan['ignoradas'])) ?>.</p><?php endif ?>
<?php $problemas = informe_libro($plan); ?>
<div class="tarjeta">
  <h2><?= $problemas ? 'Qué no está bien (' . count($problemas) . ')' : 'Todo correcto' ?></h2>
  <?php if ($problemas): ?>
  <p class="nota">Esto no se importará. El resto sí. Corrígelo en el Excel y vuelve a subirlo (no se duplica nada), o arréglalo después en INDA.</p>
  <?php $tabla_informe($problemas, 'revision'); ?>
  <?php else: ?><p class="nota">No he encontrado nada que impida importar.</p><?php endif ?>
</div>
<?php foreach ($plan['secciones']['cuadrante']['resumen']['vista'] ?? [] as $mes => $filas): $dias = rango_fechas("$mes-01", date('Y-m-t', strtotime("$mes-01"))); ?>
<div class="tarjeta vista-previa">
  <h2>Vista previa · <?= ucfirst(MESES[(int)substr($mes, 5)]) . ' ' . substr($mes, 0, 4) ?></h2>
  <p class="nota">Así quedará el calendario. En rojo, las casillas que no se importan (pasa el ratón por encima para ver el motivo).</p>
  <table class="calendario cuadrante">
    <tr><th class="persona"></th><?php foreach ($dias as $f): $n = (int)date('N', strtotime($f)); ?><th class="<?= $n >= 6 ? 'finde' : '' ?>"><?= mb_substr(DIAS[$n], 0, 1) ?><br><?= (int)substr($f, 8) ?></th><?php endforeach ?></tr>
    <?php foreach ($filas as $quien => $celdas): ?>
    <tr><td class="persona"><?= e($quien) ?></td>
      <?php foreach ($dias as $f): [$t, $col, $tit, $mal] = $celdas[$f] ?? ['', '', '', false]; ?>
      <td class="<?= $mal ? 'celda-mal' : ($col ? 'turno' : ($t === 'D' || $t === 'F' ? 'descanso' : '')) ?>" title="<?= e($tit) ?>"<?= $col && !$mal ? ' style="background:' . e($col) . '"' : '' ?>><?= e($t) ?></td>
      <?php endforeach ?></tr>
    <?php endforeach ?>
  </table>
</div>
<?php endforeach ?>

<?php foreach ($plan['secciones'] as $sec => $s): ?>
<details class="tarjeta" <?= array_filter($s['filas'], fn($f) => !empty($f['errores'])) || !empty($s['error']) || $sec === 'cuadrante' ? 'open' : '' ?>>
  <summary><?= e($titulos[$sec]) ?><?= $sec === 'cuadrante' ? '' : ' · ' . count($s['filas']) . ' filas' ?><?= isset($plan['hojas'][$sec]) ? ' (hoja «' . e($plan['hojas'][$sec]) . '»)' : '' ?></summary>
  <?php if (!empty($s['error'])): ?><p class="error"><?= e($s['error']) ?></p><?php endif ?>
  <?php if ($sec === 'cuadrante'): $r = $s['resumen']; ?>
    <p><?= count($r['asig']) ?> personas · meses: <?= e(implode(', ', array_map(fn($m) => MESES[(int)substr($m, 5)] . ' ' . substr($m, 0, 4), $r['meses']))) ?></p>
    <p><?= $r['cuenta']['turno'] ?> turnos · <?= $r['cuenta']['libre'] ?> descansos · <?= $r['cuenta']['ausencia'] ?> días de ausencia</p>
    <?php if ($r['sin_persona']): ?><p class="error">Personas que no encuentro (sus filas no se importan): <?= e(implode(' · ', array_slice(array_keys($r['sin_persona']), 0, 30))) ?></p><?php endif ?>
    <?php if ($r['dudas']): ?><p class="error">Casillas que no entiendo (no se importan): <?= e(implode(' · ', array_map(fn($k, $n) => "$k ($n)", array_keys($r['dudas']), $r['dudas']))) ?></p><?php endif ?>
    <p class="nota">Sustituye lo planificado en esos días. Las casillas vacías no cambian nada. Las ausencias se crean como aprobadas, agrupando los días seguidos.</p>
  <?php elseif ($sec === 'personas' && !empty($s['previa'])): $p = $s['previa']; $n = array_count_values(array_column($p['filas'], 'estado')); ?>
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
        <td class="<?= $f['estado'] === 'error' ? 'error' : '' ?>"><?= ['nuevo' => 'Nueva', 'actualizar' => 'Ya existe', 'error' => '<span class="pildora mal">No se importa</span> ' . e(implode(', ', $f['errores']))][$f['estado']] ?></td></tr>
      <?php endforeach ?>
    </table>
  <?php elseif ($s['filas']): $cols = array_values(array_diff(array_keys(array_merge(...array_map(fn($f) => $f, $s['filas']))), ['fila', 'errores'])); ?>
    <table>
      <tr><th>Fila</th><?php foreach ($cols as $c): ?><th><?= e($c) ?></th><?php endforeach ?><th></th></tr>
      <?php foreach (array_slice($s['filas'], 0, 300) as $f): ?>
      <tr><td><?= $f['fila'] ?></td><?php foreach ($cols as $c): ?><td><?= e($f[$c] ?? '') ?></td><?php endforeach ?>
        <td class="<?= $f['errores'] ? 'error' : '' ?>"><?= $f['errores'] ? '<span class="pildora mal">No se importa</span> ' . e(implode(', ', $f['errores'])) : '<span class="pildora ok">Correcta</span>' ?></td></tr>
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
  <p class="nota">Se aplica en este orden: departamentos y puestos, turnos, personas, ratios, festivos, ausencias y cuadrante. Las filas marcadas «No se importa» se saltan. Las ausencias entran como aprobadas salvo que pongas «Pendiente» en Estado.</p>
  <button name="que" value="aplicar">Importar</button>
  <button name="que" value="cancelar" class="secundario">Cancelar</button>
</form>
<?php endif;
pie();
