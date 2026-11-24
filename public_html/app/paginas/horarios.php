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
            $d = [$nombre, isset($_POST['flexible']) ? 1 : 0, max(0, (int)($_POST['margen_min'] ?? 10)), isset($_POST['activo']) ? 1 : 0,
                mb_substr(mb_strtoupper(trim($_POST['abreviatura'] ?? '')), 0, 3), preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '', (int)($_POST['departamento_id'] ?? 0) ?: null];
            if ($id) consulta(db(), 'UPDATE horarios SET nombre = ?, flexible = ?, margen_min = ?, activo = ?, abreviatura = ?, color = ?, departamento_id = ? WHERE id = ?', [...$d, $id]);
            else {
                consulta(db(), 'INSERT INTO horarios (nombre, flexible, margen_min, activo, abreviatura, color, departamento_id) VALUES (?, ?, ?, ?, ?, ?, ?)', $d);
                $id = (int)db()->lastInsertId();
            }
            consulta(db(), 'DELETE FROM horario_dias WHERE horario_id = ?', [$id]);
            foreach ($dias as $n => $x) consulta(db(), 'INSERT INTO horario_dias VALUES (?, ?, ?, ?, ?)', [$id, $n, ...$x]);
            db()->commit();
            auditar(db(), (int)$yo['id'], 'horario_guardado', "horario=$id");
        } elseif ($que === 'turnos') {
            $filas = [];
            foreach ((array)($_POST['t'] ?? []) as $t) if (!empty($t['usar'])) $filas[] = [$t['nombre'] ?? '', $t['letra'] ?? '', $t['color'] ?? '', $t['e'] ?? '', $t['s'] ?? '', $t['h'] ?? '', $t['dep'] ?? ''];
            [$c, $a] = guardar_turnos(db(), $filas);
            auditar(db(), (int)$yo['id'], 'turnos_cargados', "nuevos=$c actualizados=$a");
            aviso("Turnos guardados: $c nuevos y $a actualizados. Ahora asígnalos en Usuarios (turno habitual) o en Planificación (día a día).");
            redirigir('horarios');
        } elseif ($que === 'festivo') {
            $f = $_POST['fecha'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) || trim($_POST['nombre'] ?? '') === '') throw new DomainException('Pon fecha y nombre del festivo.');
            consulta(db(), 'INSERT INTO festivos (centro_id, fecha, nombre) VALUES (?, ?, ?)', [(int)$_POST['centro_id'] ?: null, $f, trim($_POST['nombre'])]);
            auditar(db(), (int)$yo['id'], 'festivo_creado', $f);
        } elseif ($que === 'borrar_festivo') {
            consulta(db(), 'DELETE FROM festivos WHERE id = ?', [(int)$_POST['id']]);
            auditar(db(), (int)$yo['id'], 'festivo_borrado', 'festivo=' . (int)$_POST['id']);
        } elseif ($que === 'importar_festivos') {
            $f = $_FILES['archivo'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 15 * 1024 * 1024) throw new DomainException('Elige el archivo del calendario (máximo 15 MB).');
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            // PDF o foto del calendario oficial: lo lee la IA. Los festivos locales van al centro elegido; el resto, a toda la empresa.
            $lista = in_array($ext, ['ics', 'csv', 'txt'], true) ? leer_festivos((string)file_get_contents($f['tmp_name'])) : ia_leer('festivos', $f);
            if (!$lista) throw new DomainException('No he encontrado fechas en el archivo. Usa un .ics o un CSV con «fecha;nombre».');
            $n = 0;
            foreach ($lista as $x) {
                [$fecha, $nombre] = $x;
                $centro = ($x[2] ?? 'local') === 'local' ? ((int)($_POST['centro_id'] ?? 0) ?: null) : null;
                $existe = consulta(db(), 'SELECT COUNT(*) FROM festivos WHERE fecha = ? AND centro_id <=> ?', [$fecha, $centro])->fetchColumn();
                if (!$existe) $n += consulta(db(), 'INSERT INTO festivos (centro_id, fecha, nombre) VALUES (?, ?, ?)', [$centro, $fecha, $nombre])->rowCount();
            }
            auditar(db(), (int)$yo['id'], 'festivos_importados', "nuevos=$n");
            aviso("Importados $n festivos" . ($n < count($lista) ? ' (' . (count($lista) - $n) . ' ya estaban)' : '') . '.');
            redirigir('horarios', ['anio' => substr($lista[0][0], 0, 4)]);
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
        } elseif ($que === 'permisos_et') {
            $n = cargar_tipos_ausencia(db(), PERMISOS_ET);
            auditar(db(), (int)$yo['id'], 'permisos_et_cargados', "nuevos=$n");
            aviso($n ? "Añadidos $n tipos de permiso. Ya se pueden pedir desde Ausencias." : 'Ya estaban todos.');
            redirigir('horarios');
        } elseif ($que === 'tipo') {
            $nombre = trim($_POST['nombre'] ?? '') ?: throw new DomainException('Pon un nombre.');
            $dias = ($_POST['dias_anuales'] ?? '') === '' ? null : max(0, (float)str_replace(',', '.', $_POST['dias_anuales']));
            $color = preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '#2563eb';
            $d = [$nombre, isset($_POST['por_horas']) ? 1 : 0, isset($_POST['retribuida']) ? 1 : 0, $dias, isset($_POST['justificante']) ? 1 : 0, $color,
                isset($_POST['activo']) || empty($_POST['id']) ? 1 : 0, ($_POST['computo'] ?? '') === 'naturales' ? 'naturales' : 'laborables'];
            if ($id = (int)($_POST['id'] ?? 0)) consulta(db(), 'UPDATE tipos_ausencia SET nombre=?, por_horas=?, retribuida=?, dias_anuales=?, justificante=?, color=?, activo=?, computo=? WHERE id=?', [...$d, $id]);
            else consulta(db(), 'INSERT INTO tipos_ausencia (nombre, por_horas, retribuida, dias_anuales, justificante, color, activo, computo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $d);
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
$h = $lista[(int)($_GET['h'] ?? 0)] ?? ['id' => '', 'nombre' => '', 'flexible' => 0, 'margen_min' => 10, 'activo' => 1, 'dias' => [], 'abreviatura' => '', 'color' => '', 'departamento_id' => null];
$deps = db()->query('SELECT id, nombre FROM departamentos WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
$anio = (int)($_GET['anio'] ?? local(ahora(), 'Y'));
$festivos = consulta(db(), 'SELECT f.*, c.nombre AS centro FROM festivos f LEFT JOIN centros c ON c.id = f.centro_id WHERE YEAR(f.fecha) = ? ORDER BY f.fecha', [$anio])->fetchAll();
$centros = db()->query('SELECT id, nombre FROM centros WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
$motivos = db()->query('SELECT * FROM motivos_pausa ORDER BY activo DESC, nombre')->fetchAll();
$tipos = tipos_ausencia(db(), false);
$hm = fn(?string $t) => $t ? substr($t, 0, 5) : '';
cabecera('Horarios, festivos y ausencias', $yo);
?>
<h2>Horarios</h2>
<?php $existentes = [];
foreach ($lista as $x) $existentes[normalizar($x['nombre'])] = $x; ?>
<details class="tarjeta" <?= $lista ? '' : 'open' ?>>
  <summary>Cargar los turnos de la residencia (mañana, tarde, noche, partido, limpieza)</summary>
  <form method="post">
    <?= csrf_campo() ?>
    <p class="nota">Revisa las horas antes de guardar: mañana y limpieza salen de los fichajes que me enviaste; tarde, noche y partido son las habituales en residencias. Como horario habitual valen de lunes a viernes; los fines de semana, rotaciones y descansos («D») se ponen en Planificación, donde cada turno sirve para cualquier día. Si ya existe un turno con ese nombre, se actualiza.</p>
    <table class="turnos-plantilla">
      <tr><th></th><th>Nombre</th><th>Letra</th><th>Color</th><th>Entrada</th><th>Salida</th><th>Horas</th><th>Departamento</th></tr>
      <?php foreach ([...TURNOS_RESIDENCIA, ['', '', '#64748b', '', '', '', ''], ['', '', '#64748b', '', '', '', '']] as $i => [$n, $l, $col, $en, $sa, $ho, $de]):
          if ($n !== '' && ($x = $existentes[normalizar($n)] ?? null)) { // valores actuales si ya existe
              $d1 = reset($x['dias']) ?: null;
              [$l, $col, $en, $sa, $ho, $de] = [$x['abreviatura'] ?: $l, $x['color'] ?: $col, $d1 ? $hm($d1['entrada']) : $en, $d1 ? $hm($d1['salida']) : $sa, $d1 ? horas($d1['minutos'] * 60) : $ho, $deps[$x['departamento_id']] ?? $de];
          } ?>
      <tr><td><input type="checkbox" name="t[<?= $i ?>][usar]" value="1" <?= $n !== '' ? 'checked' : '' ?>></td>
        <td><input name="t[<?= $i ?>][nombre]" value="<?= e($n) ?>" placeholder="Otro turno"></td>
        <td><input name="t[<?= $i ?>][letra]" value="<?= e($l) ?>" maxlength="3" size="2"></td>
        <td><input type="color" name="t[<?= $i ?>][color]" value="<?= e($col) ?>"></td>
        <td><input type="time" name="t[<?= $i ?>][e]" value="<?= e($en) ?>"></td>
        <td><input type="time" name="t[<?= $i ?>][s]" value="<?= e($sa) ?>"></td>
        <td><input name="t[<?= $i ?>][h]" value="<?= e($ho) ?>" size="4"></td>
        <td><input name="t[<?= $i ?>][dep]" value="<?= e($de) ?>" list="lista-deps"></td></tr>
      <?php endforeach ?>
    </table>
    <datalist id="lista-deps"><?php foreach ($deps as $dn): ?><option value="<?= e($dn) ?>"><?php endforeach ?></datalist>
    <button name="que" value="turnos">Guardar turnos marcados</button>
  </form>
</details>
<table>
  <tr><th>Nombre</th><th>Letra</th><th>Departamento</th><th>Semana</th><th>Tipo</th><th>Personas</th><th></th></tr>
  <?php foreach ($lista as $x):
      $n = consulta(db(), 'SELECT COUNT(*) FROM usuarios WHERE horario_id = ? AND activo = 1', [$x['id']])->fetchColumn(); ?>
  <tr class="<?= $x['activo'] ? '' : 'anulado' ?>"><td><?= e($x['nombre']) ?></td>
    <td><?= $x['abreviatura'] !== '' ? '<span class="letra-turno" style="background:' . e($x['color'] ?: '#6b7280') . '">' . e($x['abreviatura']) . '</span>' : '' ?></td>
    <td><?= e($deps[$x['departamento_id']] ?? 'Todos') ?></td>
    <td><?= horas(60 * array_sum(array_column($x['dias'], 'minutos'))) ?> h</td><td><?= $x['flexible'] ? 'Flexible' : 'Fijo' ?></td><td><?= e($n) ?></td>
    <td><a href="?p=horarios&h=<?= e($x['id']) ?>">Editar</a></td></tr>
  <?php endforeach ?>
</table>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($h['id']) ?>">
  <h3><?= $h['id'] ? 'Editar horario' : 'Nuevo horario' ?></h3>
  <label>Nombre <input name="nombre" value="<?= e($h['nombre']) ?>" required placeholder="Oficina L-V 8:00–17:00"></label>
  <label><input type="checkbox" name="flexible" <?= $h['flexible'] ? 'checked' : '' ?>> Flexible (solo cuentan las horas; no hay hora fija de entrada)</label>
  <div class="filtros">
    <label>Letra en el calendario <input name="abreviatura" maxlength="3" value="<?= e($h['abreviatura']) ?>" placeholder="M, T, N, P…"></label>
    <label>Color <input type="color" name="color" value="<?= e($h['color'] ?: '#2563eb') ?>"></label>
    <label>Departamento <select name="departamento_id"><?= opciones(['' => 'Todos'] + $deps, $h['departamento_id']) ?></select></label>
  </div>
  <label>Margen de cortesía (minutos): pasado este tiempo sin fichar, se envía el aviso <input type="number" name="margen_min" min="0" value="<?= e($h['margen_min']) ?>"></label>
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
<form method="post" enctype="multipart/form-data" class="filtros tarjeta">
  <?= csrf_campo() ?>
  <label>Subir calendario (.ics de Google/Outlook o CSV «fecha;nombre»<?= ia_activa() ? '; o el PDF/foto del calendario oficial, que lee la IA' : '' ?>)
    <input type="file" name="archivo" accept=".ics,.csv,.txt<?= ia_activa() ? ',application/pdf,image/*' : '' ?>" required></label>
  <label>Ámbito (festivos locales) <select name="centro_id"><?= opciones(['' => 'Toda la empresa'] + $centros, '') ?></select></label>
  <button name="que" value="importar_festivos">Importar</button>
</form>
<p class="nota">Calendario laboral oficial: búscalo como «festivos [tu comunidad] [año] ics» o exporta el de Google Calendar «Festivos en España».</p>
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
<form method="post" class="filtros"><?= csrf_campo() ?>
  <span class="nota">Vacaciones, bajas, libre disposición, permisos por horas o por días: todo se pide desde «Ausencias» y lo aprueba el responsable.</span>
  <button name="que" value="permisos_et" class="secundario">Añadir los permisos del Estatuto (matrimonio, hospitalización, fallecimiento, mudanza…)</button>
</form>
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
  <label>Días <select name="computo"><?= opciones(['laborables' => 'laborables', 'naturales' => 'naturales'], $t['computo'] ?? 'laborables') ?></select></label>
  <?php if ($t['id']): ?><label><input type="checkbox" name="activo" <?= $t['activo'] ? 'checked' : '' ?>> Activo</label><?php endif ?>
  <button name="que" value="tipo" class="<?= $t['id'] ? 'secundario' : '' ?>"><?= $t['id'] ? 'Guardar' : 'Añadir' ?></button>
</form>
<?php endforeach;
pie();
