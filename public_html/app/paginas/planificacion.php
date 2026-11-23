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

// Cuadrante automático: «generar» prueba el borrador (se escribe y se deshace) y «publicar» lo guarda de verdad.
$probar = function (array $plan, bool $publicar) use ($personas) {
    db()->beginTransaction();
    guardar_cuadrante(db(), $plan);
    $fechas = array_merge(...array_map('array_keys', array_values($plan)) ?: [[]]);
    $mal = [];
    foreach ($personas as $u) {
        if (!isset($plan[$u['id']])) continue;
        foreach (comprobar_plan(db(), $u, min($fechas), max($fechas)) as $m) $mal[] = [nombre($u)] + array_combine([1, 2, 3, 4], $m);
    }
    $huecos = $fechas ? huecos(db(), $personas, min($fechas), max($fechas)) : [];
    $bloquea = (bool)array_filter($mal, fn($m) => $m[4] === 'bloqueo');
    $publicar && !$bloquea ? db()->commit() : db()->rollBack();
    return [$mal, $huecos, $bloquea];
};
if (es_post() && in_array($_POST['que'] ?? '', ['generar', 'publicar', 'descartar'], true)) {
    $que = $_POST['que'];
    if ($que === 'generar') {
        $d = $_POST['desde'] ?? '';
        $h = $_POST['hasta'] ?? '';
        $sel = array_map('intval', $_POST['personas'] ?? $ids);
        $gente = array_values(array_filter($personas, fn($p) => in_array((int)$p['id'], $sel, true)));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $h) || $h < $d || strtotime($h) - strtotime($d) > 62 * 86400 || !$gente) {
            aviso('Elige personas y un periodo de hasta dos meses.', 'error');
            redirigir('planificacion', ['semana' => $lunes]);
        }
        $plan = generar_cuadrante(db(), $gente, $d, $h);
        [$mal, $huecos] = $probar($plan, false);
        $_SESSION['borrador'] = ['plan' => $plan, 'desde' => $d, 'hasta' => $h, 'mal' => $mal, 'huecos' => $huecos];
        redirigir('planificacion', ['semana' => date('Y-m-d', strtotime('monday this week', strtotime($d)))]);
    }
    $b = $_SESSION['borrador'] ?? null;
    unset($_SESSION['borrador']);
    if ($que === 'publicar' && $b) {
        [$mal, , $bloquea] = $probar($b['plan'], true);
        if ($bloquea) {
            $_SESSION['incumplimientos'] = $mal;
            aviso('No se ha publicado: algo ha cambiado y ahora incumple el convenio.', 'error');
        } else {
            foreach (array_keys($b['plan']) as $uid) notificar(db(), (int)$uid, "cuadrante:{$b['desde']}:" . ahora() . ":$uid", 'Ya tienes tu cuadrante del ' . date('d/m', strtotime($b['desde'])) . ' al ' . date('d/m', strtotime($b['hasta'])) . '.', 'calendario');
            auditar(db(), (int)$yo['id'], 'cuadrante_publicado', "{$b['desde']}..{$b['hasta']} personas=" . count($b['plan']));
            aviso('Cuadrante publicado y avisadas ' . count($b['plan']) . ' personas.');
        }
    }
    redirigir('planificacion', ['semana' => $lunes]);
}

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
<?php if ($b = $_SESSION['borrador'] ?? null):
    $hl = horarios(db());
    $pn = puestos(db());
    $fs = rango_fechas($b['desde'], $b['hasta']); ?>
<div class="tarjeta">
  <h2>Propuesta de cuadrante · <?= date('d/m', strtotime($b['desde'])) ?>–<?= date('d/m', strtotime($b['hasta'])) ?></h2>
  <table class="calendario">
    <tr><th class="persona"></th><?php foreach ($fs as $f): ?><th><?= mb_substr(DIAS[(int)date('N', strtotime($f))], 0, 1) ?><br><?= (int)substr($f, 8) ?></th><?php endforeach ?><th>Horas</th></tr>
    <?php foreach ($personas as $u): if (!isset($b['plan'][$u['id']])) continue; $min = 0; ?>
    <tr><td class="persona"><?= e(nombre($u)) ?></td>
      <?php foreach ($fs as $f): $x = $b['plan'][$u['id']][$f] ?? null; ?>
      <?php if ($x): $min += $hl[$x['horario_id']]['dias'] ? reset($hl[$x['horario_id']]['dias'])['minutos'] : 0; ?>
      <td title="<?= e($hl[$x['horario_id']]['nombre'] . ($x['puesto_id'] ? ' · ' . ($pn[$x['puesto_id']] ?? '') : '')) ?>"><?= e(mb_substr($hl[$x['horario_id']]['nombre'], 0, 1)) ?></td>
      <?php else: ?><td class="libre">·</td><?php endif ?>
      <?php endforeach ?><td><?= horas($min * 60) ?></td></tr>
    <?php endforeach ?>
  </table>
  <p class="nota">Letra = primera letra del turno (pasa el ratón para ver turno y puesto). · = libre. Se respetan bajas, vacaciones, descansos, horas semanales y un máximo de 5 días seguidos.</p>
  <?php if ($b['huecos']): ?>
  <p class="error">Sin cubrir (<?= array_sum(array_column($b['huecos'], 3)) ?>): <?= e(implode(' · ', array_map(fn($x) => date('d/m', strtotime($x[0])) . ' ' . $hl[$x[1]]['nombre'] . ($x[2] ? ' ' . ($pn[$x[2]] ?? '') : '') . ' ×' . $x[3], array_slice($b['huecos'], 0, 30)))) ?></p>
  <p class="nota">Faltan personas con esos puestos o se superarían las horas o los descansos legales. Opciones: más personas polivalentes, contratar, o cubrirlos con horas extra voluntarias desde «Huecos».</p>
  <?php else: ?><p class="aviso ok">Todas las necesidades cubiertas.</p><?php endif ?>
  <form method="post" class="filtros"><?= csrf_campo() ?>
    <button name="que" value="publicar">Publicar y avisar</button>
    <button name="que" value="descartar" class="secundario">Descartar</button></form>
</div>
<?php $mal = array_merge($mal, $b['mal']); endif ?>
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
<?php $hl ??= horarios(db()); $pn ??= puestos(db());
$hz = huecos(db(), $personas, local(ahora(), 'Y-m-d'), date('Y-m-d', strtotime(local(ahora(), 'Y-m-d') . ' +20 days'))); ?>
<?php if ($hz): ?>
<div class="tarjeta">
  <h2>Huecos de las próximas 3 semanas</h2>
  <p class="nota">Turnos sin cubrir según las necesidades (por bajas, vacaciones o falta de personal).</p>
  <table>
    <?php foreach (array_slice($hz, 0, 40) as [$f, $h, $p, $n]): ?>
    <tr><td><?= DIAS[(int)date('N', strtotime($f))] ?> <?= date('d/m', strtotime($f)) ?></td><td><?= e($hl[$h]['nombre']) ?><?= $p ? ' · ' . e($pn[$p] ?? '') : '' ?></td><td class="error">faltan <?= $n ?></td>
      <td><a class="boton" href="?<?= e(http_build_query(['p' => 'sustitutos', 'f' => $f, 'h' => $h, 'pu' => $p])) ?>">Buscar sustituto</a></td></tr>
    <?php endforeach ?>
  </table>
  <?php if (count($hz) > 40): ?><p class="nota">… y <?= count($hz) - 40 ?> más. Genera el cuadrante o revisa las necesidades.</p><?php endif ?>
</div>
<?php endif ?>
<?php // Turnos que se ofrecen a cada persona: los de su departamento y los generales, con su letra delante.
$hinfo = db()->query('SELECT id, nombre, abreviatura, departamento_id FROM horarios WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_UNIQUE);
$turnos_de = function (array $p) use ($hinfo) {
    $out = [];
    foreach ($hinfo as $id => $h) if (!$h['departamento_id'] || !$p['departamento_id'] || $h['departamento_id'] == $p['departamento_id']) $out[$id] = ($h['abreviatura'] !== '' ? $h['abreviatura'] . ' · ' : '') . $h['nombre'];
    return $out;
}; ?>
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
        <select name="c[<?= e($p['id']) ?>][<?= $f ?>]"><?= opciones(['' => $c['festivo'] && !isset($plan[$p['id']][$f]) ? 'Festivo' : 'Habitual', 'L' => 'D · Descanso'] + $turnos_de($p) + (isset($hors[$plan[$p['id']][$f] ?? 0]) ? [$plan[$p['id']][$f] => $hors[$plan[$p['id']][$f]]] : []), $plan[$p['id']][$f] ?? '') ?></select>
      </td>
      <?php endforeach ?></tr>
    <?php endforeach ?>
  </table>
  <button name="que" value="cuadrante">Guardar y avisar</button>
</form>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Generar cuadrante automático</h2>
  <p class="nota">Usa las <a href="?p=necesidades">necesidades por turno y puesto</a>. Reparte de forma justa noches, fines de semana y horas, y cumple las reglas del convenio. Primero verás la propuesta; no se guarda hasta que la publiques.</p>
  <div class="filtros">
    <label>Desde <input type="date" name="desde" required value="<?= e($lunes) ?>"></label>
    <label>Hasta <input type="date" name="hasta" required value="<?= date('Y-m-d', strtotime("$lunes +27 days")) ?>"></label>
  </div>
  <details><summary>Personas (todas por defecto)</summary>
    <?php foreach ($personas as $p): ?><label><input type="checkbox" name="personas[]" value="<?= e($p['id']) ?>" checked> <?= e(nombre($p)) ?></label><?php endforeach ?>
  </details>
  <button name="que" value="generar">Generar propuesta</button>
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
