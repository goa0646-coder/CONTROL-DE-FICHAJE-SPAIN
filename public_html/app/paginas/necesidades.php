<?php
// Puestos (qué sabe hacer cada uno) y necesidades: cuántas personas hacen falta en cada turno.
$yo = requiere('responsable', 'admin');
if (!puede($yo, 'planificar')) {
    http_response_code(403);
    exit('No tienes permiso para planificar.');
}
if (es_post()) {
    $que = $_POST['que'] ?? '';
    if ($que === 'puesto') {
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre !== '') {
            if ($id = (int)($_POST['id'] ?? 0)) consulta(db(), 'UPDATE puestos SET nombre = ?, activo = ? WHERE id = ?', [$nombre, isset($_POST['activo']) ? 1 : 0, $id]);
            else consulta(db(), 'INSERT INTO puestos (nombre) VALUES (?)', [mb_substr($nombre, 0, 80)]);
        }
    } elseif ($que === 'necesidad') {
        $h = (int)($_POST['horario_id'] ?? 0);
        $fecha = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST[$k] ?? '') ? $_POST[$k] : null;
        $datos = [mb_substr(trim($_POST['nombre'] ?? ''), 0, 100), $h, (int)($_POST['puesto_id'] ?? 0) ?: null, (int)($_POST['centro_id'] ?? 0) ?: null,
            max(0, min(7, (int)($_POST['dia'] ?? 0))), max(1, min(99, (int)($_POST['personas'] ?? 1))), $fecha('desde'), $fecha('hasta'), isset($_POST['festivos']) ? 1 : 0, isset($_POST['activo']) ? 1 : 0];
        if ($h && ($id = (int)($_POST['id'] ?? 0))) consulta(db(), 'UPDATE necesidades SET nombre = ?, horario_id = ?, puesto_id = ?, centro_id = ?, dia = ?, personas = ?, desde = ?, hasta = ?, festivos = ?, activo = ? WHERE id = ?', [...$datos, $id]);
        elseif ($h) consulta(db(), 'INSERT INTO necesidades (nombre, horario_id, puesto_id, centro_id, dia, personas, desde, hasta, festivos, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $datos);
    } elseif ($que === 'presencia') {
        $pu = (int)($_POST['puesto_id'] ?? 0);
        $d = [mb_substr(trim($_POST['nombre'] ?? ''), 0, 100) ?: 'PRESENCIA', $pu, max(1, min(99, (int)($_POST['personas'] ?? 1))), isset($_POST['activo']) ? 1 : 0];
        if ($pu && ($id = (int)($_POST['id'] ?? 0))) consulta(db(), 'UPDATE presencias SET nombre = ?, puesto_id = ?, personas = ?, activo = ? WHERE id = ?', [...$d, $id]);
        elseif ($pu) consulta(db(), 'INSERT INTO presencias (nombre, puesto_id, personas, activo) VALUES (?, ?, ?, ?)', $d);
    } elseif ($que === 'quitar') {
        consulta(db(), 'DELETE FROM necesidades WHERE id = ?', [(int)$_POST['id']]);
    } elseif ($que === 'ratios') {
        [$creadas, $faltan] = cargar_ratios(db());
        auditar(db(), (int)$yo['id'], 'ratios_cargados', implode(', ', $creadas));
        aviso(($creadas ? 'Creadas: ' . implode(', ', $creadas) . '.' : 'No se ha creado ninguna regla nueva.')
            . ($faltan ? ' Faltan los turnos ' . implode(', ', $faltan) . ': créalos en Horarios con ese nombre y vuelve a pulsar.' : ''), $faltan && !$creadas ? 'error' : 'ok');
        redirigir('necesidades');
    }
    auditar(db(), (int)$yo['id'], 'necesidades_editadas', $que);
    aviso('Guardado.');
    redirigir('necesidades');
}
$puestos = db()->query('SELECT * FROM puestos ORDER BY activo DESC, nombre')->fetchAll();
$hors = db()->query('SELECT id, nombre FROM horarios WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
$lista = db()->query('SELECT n.*, h.nombre AS turno, p.nombre AS puesto, c.nombre AS centro FROM necesidades n JOIN horarios h ON h.id = n.horario_id LEFT JOIN puestos p ON p.id = n.puesto_id LEFT JOIN centros c ON c.id = n.centro_id ORDER BY n.activo DESC, h.nombre, n.dia')->fetchAll();
$centros = db()->query('SELECT id, nombre FROM centros WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
$editar = null;
foreach ($lista as $n) if ($n['id'] == ($_GET['editar'] ?? 0)) $editar = $n;
$pp = puestos_por_persona(db());
$dias = [0 => 'Todos los días'] + DIAS;
cabecera('Puestos y necesidades', $yo);
?>
<p class="nota">1) Crea los puestos. 2) En <a href="?p=usuarios">Usuarios</a> marca qué puestos sabe cubrir cada persona. 3) Indica cuántas personas hacen falta en cada turno. Después, en <a href="?p=planificacion">Planificación</a>, pulsa «Generar cuadrante».</p>

<h2>Puestos</h2>
<?php foreach ([...$puestos, ['id' => '', 'nombre' => '', 'activo' => 1]] as $p): ?>
<form method="post" class="filtros">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($p['id']) ?>">
  <label><input name="nombre" value="<?= e($p['nombre']) ?>" placeholder="Nuevo puesto (Encargado, Operario…)" required></label>
  <?php if ($p['id']): ?><label><input type="checkbox" name="activo" <?= $p['activo'] ? 'checked' : '' ?>> Activo</label>
    <span class="nota"><?= count(array_filter($pp, fn($l) => in_array((int)$p['id'], $l, true))) ?> personas lo cubren</span><?php endif ?>
  <button name="que" value="puesto" class="<?= $p['id'] ? 'secundario' : '' ?>"><?= $p['id'] ? 'Guardar' : 'Añadir' ?></button>
</form>
<?php endforeach ?>

<h2>Reglas de presencia mínima (ratios)</h2>
<p class="nota">Cuántas personas tiene que haber como mínimo en cada turno. INDA las usa para generar el cuadrante, marca en rojo los días que no llegan y avisa a administración si hoy o mañana falta gente por bajas o vacaciones.</p>
<?php if (!$hors): ?><p class="aviso error">Primero crea los turnos (horarios con hora de entrada y salida) en <a href="?p=horarios">Horarios</a>.</p><?php endif ?>
<table>
  <tr><th>Regla</th><th>Turno</th><th>Puesto</th><th>Mínimo</th><th>Cuándo</th><th>Estado</th><th></th></tr>
  <?php foreach ($lista as $n): ?>
  <tr class="<?= $n['activo'] ? '' : 'anulado' ?>"><td><?= e($n['nombre'] ?: '—') ?></td><td><?= e($n['turno']) ?></td><td><?= e($n['puesto'] ?? 'Cualquiera') ?></td><td><strong><?= e($n['personas']) ?></strong> personas</td>
    <td><?= $dias[$n['dia']] ?><?= $n['desde'] || $n['hasta'] ? '<br><small class="nota">' . ($n['desde'] ? 'desde ' . date('d/m/Y', strtotime($n['desde'])) : '') . ($n['hasta'] ? ' hasta ' . date('d/m/Y', strtotime($n['hasta'])) : '') . '</small>' : '' ?>
      <?= $n['festivos'] ? '' : '<br><small class="nota">no en festivos</small>' ?><?= $n['centro'] ? '<br><small class="nota">' . e($n['centro']) . '</small>' : '' ?></td>
    <td><span class="estado <?= $n['activo'] ? 'dentro' : 'fuera' ?>"><?= $n['activo'] ? 'Activa' : 'Desactivada' ?></span></td>
    <td class="acciones"><a href="?p=necesidades&editar=<?= e($n['id']) ?>#regla">Editar</a>
      <form method="post" style="display:inline"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($n['id']) ?>"><button name="que" value="quitar" class="secundario" data-confirmar="¿Quitar la regla <?= e($n['nombre'] ?: $n['turno']) ?>?">Quitar</button></form></td></tr>
  <?php endforeach ?>
</table>
<?php $r = $editar ?? ['id' => '', 'nombre' => '', 'horario_id' => '', 'puesto_id' => '', 'centro_id' => '', 'dia' => 0, 'personas' => 1, 'desde' => '', 'hasta' => '', 'festivos' => 1, 'activo' => 1]; ?>
<form method="post" class="tarjeta" id="regla">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($r['id']) ?>">
  <h3><?= $r['id'] ? 'Editar regla' : 'Nueva regla' ?></h3>
  <div class="filtros">
    <label>Nombre <input name="nombre" value="<?= e($r['nombre']) ?>" placeholder="RATIO AUXILIARES MAÑANA" maxlength="100"></label>
    <label>Turno <select name="horario_id" required><?= opciones(['' => 'Elige…'] + $hors, $r['horario_id']) ?></select></label>
    <label>Puesto (opcional) <select name="puesto_id"><?= opciones(['' => 'Cualquiera'] + array_column(array_filter($puestos, fn($p) => $p['activo']), 'nombre', 'id'), $r['puesto_id']) ?></select></label>
    <?php if (count($centros) > 1): ?><label>Centro <select name="centro_id"><?= opciones(['' => 'Todos los centros'] + $centros, $r['centro_id']) ?></select></label><?php endif ?>
  </div>
  <div class="filtros">
    <label>Mínimo de personas <input type="number" name="personas" min="1" max="99" value="<?= e($r['personas']) ?>"></label>
    <label>Día <select name="dia"><?= opciones($dias, $r['dia']) ?></select></label>
    <label>Desde (opcional) <input type="date" name="desde" value="<?= e($r['desde']) ?>"></label>
    <label>Hasta (opcional) <input type="date" name="hasta" value="<?= e($r['hasta']) ?>"></label>
  </div>
  <label><input type="checkbox" name="festivos" <?= $r['festivos'] ? 'checked' : '' ?>> También en días festivos</label>
  <label><input type="checkbox" name="activo" <?= $r['activo'] ? 'checked' : '' ?>> Regla activa</label>
  <button name="que" value="necesidad"><?= $r['id'] ? 'Guardar' : 'Añadir regla' ?></button>
  <?php if ($r['id']): ?><a class="boton secundario" href="?p=necesidades">Cancelar</a><?php endif ?>
</form>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h3>Ratios de la residencia</h3>
  <p class="nota">Crea de una vez las reglas que tenías: <?= e(implode(' · ', array_map(fn($k, $v) => "$k: {$v[1]} en {$v[0]}", array_keys(RATIOS_RESIDENCIA), RATIOS_RESIDENCIA))) ?>. Los turnos tienen que existir en Horarios con ese nombre. La de tarde añádela arriba con su número.</p>
  <button name="que" value="ratios" class="secundario">Cargar ratios de la residencia</button>
</form>
<h2>Presencia orientativa (no obligatoria)</h2>
<p class="nota">Referencia de cuántas personas de un puesto (p. ej. enfermería) conviene que haya cada día. No cuenta para generar el cuadrante ni para los huecos: solo se muestra en Planificación los días que no se llega.</p>
<?php $pres = db()->query('SELECT * FROM presencias ORDER BY nombre')->fetchAll(); $pop = array_column(array_filter($puestos, fn($p) => $p['activo']), 'nombre', 'id'); ?>
<?php foreach ([...$pres, ['id' => '', 'nombre' => '', 'puesto_id' => '', 'personas' => 1, 'activo' => 1]] as $x): ?>
<form method="post" class="filtros">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($x['id']) ?>">
  <label>Nombre <input name="nombre" value="<?= e($x['nombre']) ?>" placeholder="PRESENCIA ENFERMERÍA" maxlength="100"></label>
  <label>Puesto <select name="puesto_id" required><?= opciones(['' => 'Elige…'] + $pop, $x['puesto_id']) ?></select></label>
  <label>Personas al día <input type="number" name="personas" min="1" max="99" value="<?= e($x['personas']) ?>"></label>
  <label><input type="checkbox" name="activo" <?= $x['activo'] ? 'checked' : '' ?>> Activa</label>
  <button name="que" value="presencia" class="<?= $x['id'] ? 'secundario' : '' ?>"><?= $x['id'] ? 'Guardar' : 'Añadir' ?></button>
</form>
<?php endforeach ?>
<p class="nota">Una regla de un día concreto sustituye a la de «Todos los días» para ese turno y puesto.</p>
<?php pie();
