<?php
$yo = requiere('responsable', 'admin');
if (!puede($yo, 'planificar')) {
    http_response_code(403);
    exit('No tienes permiso para planificar.');
}
$personas = array_values(array_filter(equipo_de(db(), $yo), fn($p) => $p['activo']));
$ids = array_column($personas, 'id');
$hors = db()->query('SELECT id, nombre FROM horarios WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
$lunes = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['semana'] ?? '') ? date('Y-m-d', strtotime('monday this week', strtotime($_GET['semana']))) : date('Y-m-d', strtotime('monday this week', strtotime(local(ahora(), 'Y-m-d'))));

// Valor de celda: '' = su horario habitual, 'L' = libre, número = horario.
$guardar = function (int $uid, string $fecha, string $v) use ($hors) {
    if ($v === '') return consulta(db(), 'DELETE FROM planificacion WHERE usuario_id = ? AND fecha = ?', [$uid, $fecha]);
    if ($v !== 'L' && !isset($hors[(int)$v])) throw new DomainException('Horario no válido.');
    consulta(db(), 'REPLACE INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, ?, ?)', [$uid, $fecha, $v === 'L' ? null : (int)$v]);
};

if (es_post()) {
    try {
        db()->beginTransaction();
        $afectados = [];
        if (($_POST['que'] ?? '') === 'cuadrante') {
            foreach ($_POST['c'] ?? [] as $uid => $dias) {
                if (!in_array((int)$uid, $ids)) continue;
                foreach ($dias as $f => $v) {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) continue;
                    $antes = consulta(db(), 'SELECT COALESCE(horario_id, "L") FROM planificacion WHERE usuario_id = ? AND fecha = ?', [$uid, $f])->fetchColumn();
                    if ((string)($antes === false ? '' : $antes) === $v) continue;
                    $guardar((int)$uid, $f, $v);
                    $afectados[$uid][] = $f;
                }
            }
        } else {
            // Rotación: la secuencia se repite día a día (una sola = cambio masivo de horario).
            $sel = array_values(array_intersect(array_map('intval', $_POST['personas'] ?? []), $ids));
            $sec = array_values(array_filter($_POST['secuencia'] ?? [], fn($v) => $v !== '-'));
            $d = $_POST['desde'] ?? '';
            $h = $_POST['hasta'] ?? '';
            if (!$sel || !$sec || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $h) || $h < $d) throw new DomainException('Elige personas, fechas y al menos un horario.');
            if ((strtotime($h) - strtotime($d)) > 366 * 86400) throw new DomainException('Como mucho un año de una vez.');
            $desfase = isset($_POST['escalonar']);
            foreach ($sel as $i => $uid) {
                foreach (rango_fechas($d, $h) as $n => $f) $guardar($uid, $f, $sec[($n + ($desfase ? $i : 0)) % count($sec)]);
                $afectados[$uid] = [$d, $h];
            }
        }
        // Reglas del convenio sobre lo que queda planificado: lo que bloquea no se guarda.
        $mal = [];
        foreach ($afectados as $uid => $fs) {
            foreach (comprobar_plan(db(), usuario(db(), (int)$uid), min($fs), max($fs)) as $m) $mal[] = [nombre(usuario(db(), (int)$uid))] + array_combine([1, 2, 3, 4], $m);
        }
        $_SESSION['incumplimientos'] = $mal;
        if (array_filter($mal, fn($m) => $m[4] === 'bloqueo')) {
            db()->rollBack();
            aviso('No se ha guardado: incumple el convenio. Revisa la lista de abajo.', 'error');
            redirigir('planificacion', ['semana' => $lunes]);
        }
        db()->commit();
        foreach (array_keys($afectados) as $uid) notificar(db(), (int)$uid, 'horario:' . ahora() . ":$uid", 'Tu horario ha cambiado. Revísalo en el calendario.', 'calendario');
        auditar(db(), (int)$yo['id'], 'planificacion', 'personas=' . implode(',', array_keys($afectados)));
        aviso('Planificación guardada' . ($afectados ? ' y avisadas ' . count($afectados) . ' personas.' : '.'));
    } catch (DomainException $e) {
        if (db()->inTransaction()) db()->rollBack();
        aviso($e->getMessage(), 'error');
    }
    redirigir('planificacion', ['semana' => $lunes]);
}

$dias = rango_fechas($lunes, date('Y-m-d', strtotime("$lunes +6 days")));
$plan = [];
foreach (consulta(db(), 'SELECT usuario_id, fecha, COALESCE(horario_id, "L") AS v FROM planificacion WHERE fecha BETWEEN ? AND ?', [$dias[0], $dias[6]]) as $x) $plan[$x['usuario_id']][$x['fecha']] = (string)$x['v'];
$mal = $_SESSION['incumplimientos'] ?? [];
unset($_SESSION['incumplimientos']);
cabecera('Planificación', $yo);
?>
<?php if ($mal): ?>
<div class="tarjeta">
  <h2>Reglas del convenio</h2>
  <table>
    <?php foreach ($mal as [$quien, $f, , $txt, $tipo]): ?>
    <tr><td class="<?= $tipo === 'bloqueo' ? 'error' : '' ?>"><?= $tipo === 'bloqueo' ? '⛔ No permitido' : '⚠ Aviso' ?></td><td><?= e($quien) ?></td><td><?= date('d/m', strtotime($f)) ?></td><td><?= e($txt) ?></td></tr>
    <?php endforeach ?>
  </table>
  <p class="nota">Lo marcado ⛔ no se puede saltar ni con la firma del trabajador (art. 3.5 ET). Si es una emergencia, cúbrelo como horas extra por fuerza mayor desde «Huecos».</p>
</div>
<?php endif ?>
<h2>Cuadrante semanal</h2>
<form class="filtros">
  <input type="hidden" name="p" value="planificacion">
  <a class="boton secundario" href="?p=planificacion&semana=<?= date('Y-m-d', strtotime("$lunes -7 days")) ?>">‹</a>
  <label><input type="date" name="semana" value="<?= e($lunes) ?>"></label>
  <a class="boton secundario" href="?p=planificacion&semana=<?= date('Y-m-d', strtotime("$lunes +7 days")) ?>">›</a>
  <button>Ir</button>
</form>
<?php if (!$hors): ?><p class="aviso error">Primero crea horarios (turnos) en <a href="?p=horarios">Horarios</a>.</p><?php endif ?>
<form method="post">
  <?= csrf_campo() ?>
  <table class="calendario">
    <tr><th class="persona">Persona</th><?php foreach ($dias as $f): ?><th><?= mb_substr(DIAS[(int)date('N', strtotime($f))], 0, 3) ?> <?= date('d/m', strtotime($f)) ?></th><?php endforeach ?></tr>
    <?php foreach ($personas as $p): $cal = calendario_teorico(db(), $p, $dias[0], $dias[6]); ?>
    <tr><td class="persona"><?= e(nombre($p)) ?></td>
      <?php foreach ($dias as $f): $c = $cal[$f]; ?>
      <td style="<?= $c['ausencia'] ? 'background:' . e($c['ausencia']['color']) : '' ?>" title="<?= e($c['ausencia']['tipo'] ?? $c['festivo'] ?? '') ?>">
        <select name="c[<?= e($p['id']) ?>][<?= $f ?>]"><?= opciones(['' => $c['festivo'] && !isset($plan[$p['id']][$f]) ? 'Festivo' : 'Habitual', 'L' => 'Libre'] + $hors, $plan[$p['id']][$f] ?? '') ?></select>
      </td>
      <?php endforeach ?></tr>
    <?php endforeach ?>
  </table>
  <button name="que" value="cuadrante">Guardar y avisar</button>
</form>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Asignar por fechas o rotar turnos</h2>
  <p class="nota">Elige una secuencia de horarios que se repite día a día. Ejemplo de rotación: Mañana, Mañana, Tarde, Tarde, Libre, Libre. Con un solo horario es un cambio de horario para esas fechas.</p>
  <fieldset><legend>Personas</legend>
    <?php foreach ($personas as $p): ?><label><input type="checkbox" name="personas[]" value="<?= e($p['id']) ?>"> <?= e(nombre($p)) ?></label><?php endforeach ?>
  </fieldset>
  <div class="filtros">
    <label>Desde <input type="date" name="desde" required></label>
    <label>Hasta <input type="date" name="hasta" required></label>
  </div>
  <div class="filtros">
    <?php for ($i = 0; $i < 14; $i++): ?>
    <label>Día <?= $i + 1 ?> <select name="secuencia[]"><?= opciones(['-' => '—', '' => 'Habitual', 'L' => 'Libre'] + $hors, '-') ?></select></label>
    <?php endfor ?>
  </div>
  <label><input type="checkbox" name="escalonar"> Escalonar: cada persona empieza la secuencia un día después que la anterior</label>
  <button name="que" value="rotacion">Aplicar y avisar</button>
</form>
<?php pie();
