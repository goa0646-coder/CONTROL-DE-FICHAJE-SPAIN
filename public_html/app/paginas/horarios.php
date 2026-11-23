<?php
$yo = requiere('admin');

// "7:30" o "7,5" → minutos; "" → null.
$a_min = function (string $t): ?int {
    $t = trim(str_replace(',', '.', $t));
    if ($t === '') return null;
    if (preg_match('/^(\d{1,2}):([0-5]\d)$/', $t, $m)) return $m[1] * 60 + $m[2];
    if (is_numeric($t) && $t >= 0 && $t <= 24) return (int)round($t * 60);
    throw new DomainException("Horas no válidas: $t");
};

if (es_post()) {
    try {
        $que = $_POST['que'] ?? '';
        if ($que === 'horario') {
            $nombre = trim($_POST['nombre'] ?? '') ?: throw new DomainException('Pon un nombre al horario.');
            $dias = [];
            foreach (DIAS as $n => $_) {
                $e = $_POST["e$n"] ?? '';
                $s = $_POST["s$n"] ?? '';
                $min = $a_min($_POST["h$n"] ?? '');
                if ($min === null && $e !== '' && $s !== '') $min = intdiv((strtotime("2000-01-01 $s") - strtotime("2000-01-01 $e") + 86400) % 86400, 60);
                if ($min) $dias[$n] = [$e ?: null, $s ?: null, $min];
            }
            db()->beginTransaction();
            $id = (int)($_POST['id'] ?? 0);
            $d = [$nombre, isset($_POST['flexible']) ? 1 : 0, max(0, (int)($_POST['margen_min'] ?? 10)), isset($_POST['activo']) ? 1 : 0];
            if ($id) consulta(db(), 'UPDATE horarios SET nombre = ?, flexible = ?, margen_min = ?, activo = ? WHERE id = ?', [...$d, $id]);
            else {
                consulta(db(), 'INSERT INTO horarios (nombre, flexible, margen_min, activo) VALUES (?, ?, ?, ?)', $d);
                $id = (int)db()->lastInsertId();
            }
            consulta(db(), 'DELETE FROM horario_dias WHERE horario_id = ?', [$id]);
            foreach ($dias as $n => $x) consulta(db(), 'INSERT INTO horario_dias VALUES (?, ?, ?, ?, ?)', [$id, $n, ...$x]);
            db()->commit();
            auditar(db(), (int)$yo['id'], 'horario_guardado', "horario=$id");
        } elseif ($que === 'festivo') {
            $f = $_POST['fecha'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) || trim($_POST['nombre'] ?? '') === '') throw new DomainException('Pon fecha y nombre del festivo.');
            consulta(db(), 'INSERT INTO festivos (centro_id, fecha, nombre) VALUES (?, ?, ?)', [(int)$_POST['centro_id'] ?: null, $f, trim($_POST['nombre'])]);
            auditar(db(), (int)$yo['id'], 'festivo_creado', $f);
        } elseif ($que === 'borrar_festivo') {
            consulta(db(), 'DELETE FROM festivos WHERE id = ?', [(int)$_POST['id']]);
            auditar(db(), (int)$yo['id'], 'festivo_borrado', 'festivo=' . (int)$_POST['id']);
        } elseif ($que === 'copiar_festivos') {
            $anio = (int)$_POST['anio'];
            consulta(db(), 'INSERT INTO festivos (centro_id, fecha, nombre) SELECT centro_id, DATE_ADD(fecha, INTERVAL 1 YEAR), nombre FROM festivos WHERE YEAR(fecha) = ?', [$anio]);
            aviso('Copiados. Revisa los festivos que cambian de fecha cada año (Semana Santa…).');
            redirigir('horarios', ['anio' => $anio + 1]);
        } elseif ($que === 'motivo') {
            $nombre = trim($_POST['nombre'] ?? '') ?: throw new DomainException('Pon un nombre.');
            $d = [$nombre, isset($_POST['retribuida']) ? 1 : 0, isset($_POST['activo']) || empty($_POST['id']) ? 1 : 0];
            if ($id = (int)($_POST['id'] ?? 0)) consulta(db(), 'UPDATE motivos_pausa SET nombre = ?, retribuida = ?, activo = ? WHERE id = ?', [...$d, $id]);
            else consulta(db(), 'INSERT INTO motivos_pausa (nombre, retribuida, activo) VALUES (?, ?, ?)', $d);
            auditar(db(), (int)$yo['id'], 'motivo_pausa_guardado', $nombre);
        } elseif ($que === 'tipo') {
            $nombre = trim($_POST['nombre'] ?? '') ?: throw new DomainException('Pon un nombre.');
            $dias = ($_POST['dias_anuales'] ?? '') === '' ? null : max(0, (float)str_replace(',', '.', $_POST['dias_anuales']));
            $color = preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '#2563eb';
            $d = [$nombre, isset($_POST['por_horas']) ? 1 : 0, isset($_POST['retribuida']) ? 1 : 0, $dias, isset($_POST['justificante']) ? 1 : 0, $color,
                isset($_POST['activo']) || empty($_POST['id']) ? 1 : 0];
            if ($id = (int)($_POST['id'] ?? 0)) consulta(db(), 'UPDATE tipos_ausencia SET nombre=?, por_horas=?, retribuida=?, dias_anuales=?, justificante=?, color=?, activo=? WHERE id=?', [...$d, $id]);
            else consulta(db(), 'INSERT INTO tipos_ausencia (nombre, por_horas, retribuida, dias_anuales, justificante, color, activo) VALUES (?, ?, ?, ?, ?, ?, ?)', $d);
            auditar(db(), (int)$yo['id'], 'tipo_ausencia_guardado', $nombre);
        }
        aviso('Guardado.');
    } catch (DomainException $e) {
        if (db()->inTransaction()) db()->rollBack();
        aviso($e->getMessage(), 'error');
    }
    redirigir('horarios');
}

$lista = horarios(db());
$h = $lista[(int)($_GET['h'] ?? 0)] ?? ['id' => '', 'nombre' => '', 'flexible' => 0, 'margen_min' => 10, 'activo' => 1, 'dias' => []];
$anio = (int)($_GET['anio'] ?? local(ahora(), 'Y'));
$festivos = consulta(db(), 'SELECT f.*, c.nombre AS centro FROM festivos f LEFT JOIN centros c ON c.id = f.centro_id WHERE YEAR(f.fecha) = ? ORDER BY f.fecha', [$anio])->fetchAll();
$centros = db()->query('SELECT id, nombre FROM centros WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
$motivos = db()->query('SELECT * FROM motivos_pausa ORDER BY activo DESC, nombre')->fetchAll();
$tipos = tipos_ausencia(db(), false);
$hm = fn(?string $t) => $t ? substr($t, 0, 5) : '';
cabecera('Horarios, festivos y ausencias', $yo);
?>
<h2>Horarios</h2>
<table>
  <tr><th>Nombre</th><th>Semana</th><th>Tipo</th><th>Personas</th><th></th></tr>
  <?php foreach ($lista as $x):
      $n = consulta(db(), 'SELECT COUNT(*) FROM usuarios WHERE horario_id = ? AND activo = 1', [$x['id']])->fetchColumn(); ?>
  <tr class="<?= $x['activo'] ? '' : 'anulado' ?>"><td><?= e($x['nombre']) ?></td>
    <td><?= horas(60 * array_sum(array_column($x['dias'], 'minutos'))) ?> h</td><td><?= $x['flexible'] ? 'Flexible' : 'Fijo' ?></td><td><?= e($n) ?></td>
    <td><a href="?p=horarios&h=<?= e($x['id']) ?>">Editar</a></td></tr>
  <?php endforeach ?>
</table>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($h['id']) ?>">
  <h3><?= $h['id'] ? 'Editar horario' : 'Nuevo horario' ?></h3>
  <label>Nombre <input name="nombre" value="<?= e($h['nombre']) ?>" required placeholder="Oficina L-V 8:00–17:00"></label>
  <label><input type="checkbox" name="flexible" <?= $h['flexible'] ? 'checked' : '' ?>> Flexible (solo cuentan las horas; no hay hora fija de entrada)</label>
  <label>Margen de cortesía (minutos) <input type="number" name="margen_min" min="0" value="<?= e($h['margen_min']) ?>"></label>
  <table>
    <tr><th>Día</th><th>Entrada</th><th>Salida</th><th>Horas a trabajar</th></tr>
    <?php foreach (DIAS as $n => $dia): $x = $h['dias'][$n] ?? null; ?>
    <tr><td><?= $dia ?></td>
      <td><input type="time" name="e<?= $n ?>" value="<?= $hm($x['entrada'] ?? null) ?>"></td>
      <td><input type="time" name="s<?= $n ?>" value="<?= $hm($x['salida'] ?? null) ?>"></td>
      <td><input name="h<?= $n ?>" value="<?= $x ? horas($x['minutos'] * 60) : '' ?>" placeholder="8:00" size="5"></td></tr>
    <?php endforeach ?>
  </table>
  <p class="nota">Si dejas «Horas» vacío se calcula de entrada a salida. Para descontar la comida, escribe las horas efectivas (p. ej. 8:00). Día vacío = libre.</p>
  <label><input type="checkbox" name="activo" <?= $h['activo'] ? 'checked' : '' ?>> Activo</label>
  <button name="que" value="horario">Guardar horario</button> <?php if ($h['id']): ?><a href="?p=horarios">Nuevo</a><?php endif ?>
</form>
<p class="nota">Asigna el horario a cada persona en <a href="?p=usuarios">Usuarios</a>. Cambios por fechas y turnos: <a href="?p=planificacion">Planificación</a>.</p>

<h2>Festivos <?= $anio ?></h2>
<form class="filtros"><input type="hidden" name="p" value="horarios"><label>Año <input type="number" name="anio" value="<?= $anio ?>"></label><button>Ver</button></form>
<table>
  <tr><th>Fecha</th><th>Nombre</th><th>Ámbito</th><th></th></tr>
  <?php foreach ($festivos as $f): ?>
  <tr><td><?= date('d/m/Y', strtotime($f['fecha'])) ?></td><td><?= e($f['nombre']) ?></td><td><?= e($f['centro'] ?? 'Toda la empresa') ?></td>
    <td><form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>"><button name="que" value="borrar_festivo" class="secundario">Quitar</button></form></td></tr>
  <?php endforeach ?>
</table>
<form method="post" class="filtros">
  <?= csrf_campo() ?>
  <label>Fecha <input type="date" name="fecha" required></label>
  <label>Nombre <input name="nombre" required placeholder="Fiesta local"></label>
  <label>Ámbito <select name="centro_id"><?= opciones(['' => 'Toda la empresa'] + $centros, '') ?></select></label>
  <button name="que" value="festivo">Añadir</button>
</form>
<?php if ($festivos): ?>
<form method="post"><?= csrf_campo() ?><input type="hidden" name="anio" value="<?= $anio ?>"><button name="que" value="copiar_festivos" class="secundario">Copiar estos festivos a <?= $anio + 1 ?></button></form>
<?php endif ?>

<h2>Motivos de pausa</h2>
<p class="nota">Retribuida = cuenta como tiempo trabajado. Si no hay ninguno activo, se ficha la pausa sin motivo.</p>
<?php foreach ([...$motivos, ['id' => '', 'nombre' => '', 'retribuida' => 0, 'activo' => 1]] as $m): ?>
<form method="post" class="filtros">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($m['id']) ?>">
  <label><input name="nombre" value="<?= e($m['nombre']) ?>" placeholder="Nuevo motivo" required></label>
  <label><input type="checkbox" name="retribuida" <?= $m['retribuida'] ? 'checked' : '' ?>> Retribuida</label>
  <?php if ($m['id']): ?><label><input type="checkbox" name="activo" <?= $m['activo'] ? 'checked' : '' ?>> Activo</label><?php endif ?>
  <button name="que" value="motivo" class="<?= $m['id'] ? 'secundario' : '' ?>"><?= $m['id'] ? 'Guardar' : 'Añadir' ?></button>
</form>
<?php endforeach ?>

<h2>Tipos de ausencia</h2>
<p class="nota">Días al año vacío = sin límite. Los días se cuentan laborables según el horario de cada persona.</p>
<?php foreach ([...array_values($tipos), ['id' => '', 'nombre' => '', 'por_horas' => 0, 'retribuida' => 1, 'dias_anuales' => null, 'justificante' => 0, 'color' => '#2563eb', 'activo' => 1]] as $t): ?>
<form method="post" class="filtros tarjeta">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($t['id']) ?>">
  <label><input name="nombre" value="<?= e($t['nombre']) ?>" placeholder="Nuevo tipo" required></label>
  <label>Días/año <input name="dias_anuales" value="<?= e($t['dias_anuales'] === null ? '' : (float)$t['dias_anuales']) ?>" size="4"></label>
  <label><input type="color" name="color" value="<?= e($t['color']) ?>"></label>
  <label><input type="checkbox" name="por_horas" <?= $t['por_horas'] ? 'checked' : '' ?>> Por horas</label>
  <label><input type="checkbox" name="retribuida" <?= $t['retribuida'] ? 'checked' : '' ?>> Retribuida</label>
  <label><input type="checkbox" name="justificante" <?= $t['justificante'] ? 'checked' : '' ?>> Pide justificante</label>
  <?php if ($t['id']): ?><label><input type="checkbox" name="activo" <?= $t['activo'] ? 'checked' : '' ?>> Activo</label><?php endif ?>
  <button name="que" value="tipo" class="<?= $t['id'] ? 'secundario' : '' ?>"><?= $t['id'] ? 'Guardar' : 'Añadir' ?></button>
</form>
<?php endforeach;
pie();
