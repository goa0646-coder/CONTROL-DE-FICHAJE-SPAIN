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
        $n = max(1, min(99, (int)($_POST['personas'] ?? 1)));
        $dia = max(0, min(7, (int)($_POST['dia'] ?? 0)));
        if ($h) consulta(db(), 'INSERT INTO necesidades (horario_id, puesto_id, dia, personas) VALUES (?, ?, ?, ?)', [$h, (int)$_POST['puesto_id'] ?: null, $dia, $n]);
    } elseif ($que === 'quitar') {
        consulta(db(), 'DELETE FROM necesidades WHERE id = ?', [(int)$_POST['id']]);
    }
    auditar(db(), (int)$yo['id'], 'necesidades_editadas', $que);
    aviso('Guardado.');
    redirigir('necesidades');
}
$puestos = db()->query('SELECT * FROM puestos ORDER BY activo DESC, nombre')->fetchAll();
$hors = db()->query('SELECT id, nombre FROM horarios WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
$lista = db()->query('SELECT n.*, h.nombre AS turno, p.nombre AS puesto FROM necesidades n JOIN horarios h ON h.id = n.horario_id LEFT JOIN puestos p ON p.id = n.puesto_id ORDER BY h.nombre, n.dia')->fetchAll();
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

<h2>Personas necesarias por turno</h2>
<?php if (!$hors): ?><p class="aviso error">Primero crea los turnos (horarios con hora de entrada y salida) en <a href="?p=horarios">Horarios</a>.</p><?php endif ?>
<table>
  <tr><th>Turno</th><th>Puesto</th><th>Día</th><th>Personas</th><th></th></tr>
  <?php foreach ($lista as $n): ?>
  <tr><td><?= e($n['turno']) ?></td><td><?= e($n['puesto'] ?? 'Cualquiera') ?></td><td><?= $dias[$n['dia']] ?></td><td><?= e($n['personas']) ?></td>
    <td><form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($n['id']) ?>"><button name="que" value="quitar" class="secundario">Quitar</button></form></td></tr>
  <?php endforeach ?>
</table>
<form method="post" class="filtros tarjeta">
  <?= csrf_campo() ?>
  <label>Turno <select name="horario_id" required><?= opciones(['' => 'Elige…'] + $hors, '') ?></select></label>
  <label>Puesto <select name="puesto_id"><?= opciones(['' => 'Cualquiera'] + array_column(array_filter($puestos, fn($p) => $p['activo']), 'nombre', 'id'), '') ?></select></label>
  <label>Día <select name="dia"><?= opciones($dias, 0) ?></select></label>
  <label>Personas <input type="number" name="personas" min="1" max="99" value="1"></label>
  <button name="que" value="necesidad">Añadir</button>
</form>
<p class="nota">Ejemplo: «Noche · Encargado · Todos los días · 1» y «Noche · Operario · Todos los días · 2». Una fila de un día concreto sustituye a la de «Todos los días» para ese turno y puesto.</p>
<?php pie();
