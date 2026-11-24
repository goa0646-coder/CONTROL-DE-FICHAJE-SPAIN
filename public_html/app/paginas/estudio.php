<?php
// Estudio de plantilla (interno, solo administración): mínimo de personas para cubrir los ratios de un año
// con el convenio, y calendario anual con la plantilla real usando las menos personas posibles.
$yo = requiere('admin');
@set_time_limit(300);
$anio = max(2024, min(2100, (int)($_REQUEST['anio'] ?? ((int)local(ahora(), 'Y') + 1))));
$desde = "$anio-01-01";
$hasta = "$anio-12-31";
$grupos = grupos_estudio(db());
$sel = array_values(array_intersect(array_keys($grupos), (array)($_REQUEST['g'] ?? array_keys($grupos))));
$elegidos = array_intersect_key($grupos, array_flip($sel));
$modo = ($_REQUEST['modo'] ?? 'minimo') === 'justo' ? 'justo' : 'minimo';
$vac = max(0, min(60, (int)($_REQUEST['vac'] ?? 30)));
$nombres_h = array_map(fn($h) => $h['nombre'], horarios(db()));

if (es_post() && ($_POST['que'] ?? '') === 'publicar') {
    // Se vuelve a calcular con los mismos datos (el motor siempre da el mismo resultado) y se guarda si no incumple el convenio.
    $res = plan_anual(db(), $elegidos, $desde, $hasta, $modo, $vac);
    db()->beginTransaction();
    guardar_cuadrante(db(), $res['plan']);
    $bloqueos = [];
    foreach ($res['personas'] as $u) {
        if (!isset($res['plan'][$u['id']])) continue;
        foreach (comprobar_plan(db(), $u, $desde, $hasta) as $m) if ($m[3] === 'bloqueo') $bloqueos[] = nombre($u) . ' ' . date('d/m', strtotime($m[0])) . ': ' . $m[2];
    }
    if ($bloqueos) {
        db()->rollBack();
        aviso('No se ha guardado: ' . count($bloqueos) . ' incumplimientos. El primero: ' . $bloqueos[0], 'error');
    } else {
        db()->commit();
        foreach (array_keys($res['plan']) as $uid) notificar(db(), (int)$uid, "cuadrante_anual:$anio:" . ahora() . ":$uid", "Ya tienes tu calendario de $anio en INDA.", 'calendario');
        auditar(db(), (int)$yo['id'], 'calendario_anual_publicado', "anio=$anio modo=$modo personas=" . count($res['plan']));
        aviso("Calendario de $anio publicado para " . count($res['plan']) . ' personas y avisadas.');
    }
    redirigir('estudio', ['anio' => $anio, 'modo' => $modo, 'g' => $sel, 'ver' => 'plan']);
}

$ver = $_GET['ver'] ?? '';
$t0 = microtime(true);
$estudio = $plan = null;
if ($ver === 'minimo' && $elegidos) {
    $nec = necesidades_periodo(db(), $desde, $hasta);
    foreach ($elegidos as $k => $g) $estudio[$k] = minimo_personas(db(), $g, $desde, $hasta, $nec, $vac);
}
if ($ver === 'plan' && $elegidos) $plan = plan_anual(db(), $elegidos, $desde, $hasta, $modo, $vac);
$tiempo = round(microtime(true) - $t0, 1);
$plantilla = [];
foreach ($grupos as $k => $g) $plantilla[$k] = count(personas_de_grupos(db(), [$g]));
$r = reglas(db());
cabecera('Estudio de plantilla', $yo);
?>
<p class="nota">Herramienta interna. Calcula con los <a href="?p=necesidades">ratios mínimos</a>, los <a href="?p=horarios">turnos</a> y las reglas del convenio
  (<?= e($r['horas_anuales'] ?: '—') ?> h al año, <?= e($r['horas_semana_media']) ?> h a la semana, <?= e($r['descanso_entre_jornadas_h']) ?> h entre jornadas, máximo 5 días seguidos<?= $r['domingo_cada_semanas'] ? ', un domingo libre cada ' . e($r['domingo_cada_semanas']) . ' semanas' : '' ?>).
  Las bajas y vacaciones ya aprobadas se respetan. Nada se guarda hasta que pulses «Publicar».</p>
<?php if (!$grupos): ?><p class="aviso error">Primero crea los turnos en <a href="?p=horarios">Horarios</a> y los ratios en <a href="?p=necesidades">Necesidades</a>.</p><?php pie(); return; endif ?>

<form class="tarjeta">
  <input type="hidden" name="p" value="estudio">
  <div class="filtros">
    <label>Año <input type="number" name="anio" value="<?= e($anio) ?>" min="2024" max="2100"></label>
    <label>Vacaciones por persona (días naturales, se dejan libres) <input type="number" name="vac" value="<?= e($vac) ?>" min="0" max="60"></label>
    <label>Reparto <select name="modo"><?= opciones(['minimo' => 'Mínimo de personas', 'justo' => 'Reparto justo entre todos'], $modo) ?></select></label>
  </div>
  <fieldset><legend>Departamentos</legend>
    <?php foreach ($grupos as $k => $g): ?><label><input type="checkbox" name="g[]" value="<?= e($k) ?>" <?= in_array($k, $sel, true) ? 'checked' : '' ?>> <?= e($g['nombre']) ?>&nbsp;<small>(<?= $plantilla[$k] ?> personas)</small></label><?php endforeach ?>
  </fieldset>
  <button name="ver" value="minimo">1. ¿Cuántas personas necesito?</button>
  <button name="ver" value="plan" class="secundario">2. Generar el calendario de <?= e($anio) ?> con mi plantilla</button>
  <p class="nota">Puede tardar unos segundos: prueba el año entero día a día.</p>
</form>

<?php if ($estudio): ?>
<h2>Mínimo de personas para <?= e($anio) ?></h2>
<table>
  <tr><th>Departamento</th><th>Horas de turno al año</th><th>Mínimo de personas</th><th>Tienes</th><th>Diferencia</th><th>Por persona</th></tr>
  <?php foreach ($estudio as $k => $e): $tiene = $plantilla[$k]; ?>
  <tr><td><strong><?= e($grupos[$k]['nombre']) ?></strong><br><small class="nota"><?= e(implode(', ', array_map(fn($h) => $nombres_h[$h] ?? '', $grupos[$k]['horarios']))) ?></small></td>
    <td><?= number_format($e['total'] / 60, 0, ',', '.') ?> h<br><small class="nota">hasta <?= $e['pico'] ?> a la vez</small></td>
    <?php if ($e['minimo']): $dif = $tiene - $e['minimo']; ?>
    <td><strong style="font-size:1.4rem"><?= $e['minimo'] ?></strong><br><small class="nota">cota teórica <?= $e['cota'] ?></small></td><td><?= $tiene ?></td>
    <td class="<?= $dif < 0 ? 'error' : '' ?>"><?= $dif > 0 ? "Te sobran $dif" : ($dif < 0 ? 'Te faltan ' . -$dif : 'Justo') ?></td>
    <td><?= number_format($e['media'] / 60, 0, ',', '.') ?> h<br><small class="nota"><?= round($e['noches']) ?> noches · <?= round($e['findes']) ?> findes/festivos</small></td>
    <?php else: ?>
    <td colspan="4" class="error">No se puede cubrir: <?= e(implode('; ', array_map(fn($m, $n) => "$m ($n turnos)", array_keys($e['motivos']), $e['motivos']))) ?>.</td>
    <?php endif ?></tr>
  <?php endforeach ?>
</table>
<p class="nota">Supone personas que pueden hacer cualquier turno y puesto de su departamento, con <?= e($vac) ?> días de vacaciones repartidos a lo largo del año, sin bajas imprevistas. Para cubrir bajas, suma un margen (en residencias suele ser un 10–15 %). Calculado en <?= e($tiempo) ?> s.</p>
<?php endif ?>

<?php if ($plan):
    $usados = array_filter($plan['min']);
    $sin = array_filter($plan['personas'], fn($u) => empty($plan['min'][$u['id']]));
    $faltan = array_sum(array_column($plan['faltan'], 3)); ?>
<h2>Calendario de <?= e($anio) ?> · <?= $modo === 'minimo' ? 'mínimo de personas' : 'reparto justo' ?></h2>
<div class="cifras">
  <div><span>Personas con turnos</span><strong><?= count($usados) ?></strong></div>
  <div><span>Sin turnos en <?= e($anio) ?></span><strong><?= count($sin) ?></strong></div>
  <div><span>Turnos sin cubrir</span><strong class="<?= $faltan ? 'error' : '' ?>"><?= $faltan ?></strong></div>
  <div><span>Calculado en</span><strong><?= e($tiempo) ?> s</strong></div>
</div>
<?php if ($faltan): $por_turno = [];
    foreach ($plan['faltan'] as [$f, $h, , $n, $m]) { $k = ($nombres_h[$h] ?? '') . ' — ' . $m; $por_turno[$k] = ($por_turno[$k] ?? 0) + $n; } ?>
<p class="aviso error">No llega en <?= count(array_unique(array_column($plan['faltan'], 0))) ?> días. <?= e(implode(' · ', array_map(fn($k, $n) => "$k: $n", array_keys($por_turno), $por_turno))) ?>.
  Primeros días: <?= e(implode(', ', array_map(fn($f) => date('d/m', strtotime($f)), array_slice(array_values(array_unique(array_column($plan['faltan'], 0))), 0, 12)))) ?>.</p>
<?php endif ?>
<table>
  <tr><th>Persona</th><th>Horas</th><th>Noches</th><th>Findes/festivos</th><th>Días de trabajo</th><th>Vacaciones previstas</th></tr>
  <?php foreach ($plan['personas'] as $u): $uid = $u['id']; $m = $plan['min'][$uid] ?? 0; ?>
  <tr class="<?= $m ? '' : 'anulado' ?>"><td><span class="con-avatar"><?= avatar($u, 'mini') ?> <?= e(nombre($u)) ?></span></td>
    <td><?= horas($m * 60) ?> h<?= $r['horas_anuales'] ? ' <small class="nota">de ' . e($r['horas_anuales']) . '</small>' : '' ?></td>
    <td><?= $plan['noches'][$uid] ?? 0 ?></td><td><?= $plan['findes'][$uid] ?? 0 ?></td>
    <td><?= count(array_filter($plan['plan'][$uid] ?? [])) ?></td>
    <td><?= isset($plan['vacaciones'][$uid]) ? date('d/m', strtotime($plan['vacaciones'][$uid][0])) . ' – ' . date('d/m', strtotime($plan['vacaciones'][$uid][1])) : ($m ? '<small class="nota">ya aprobadas</small>' : '') ?></td></tr>
  <?php endforeach ?>
</table>
<?php if ($plan['vacaciones']): ?><p class="nota">Las vacaciones previstas quedan libres en el calendario (sin turno). Para que cuenten como vacaciones, cada persona las pide en Ausencias con esas fechas, o las cambia con quien le cuadre.</p><?php endif ?>
<?php if ($sin): ?><p class="nota">Las personas sin turnos quedarían en descanso todo <?= e($anio) ?> si publicas (por ejemplo, para cubrir bajas o como bolsa). Si no lo quieres, quita su departamento o usa «Reparto justo».</p><?php endif ?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?><input type="hidden" name="anio" value="<?= e($anio) ?>"><input type="hidden" name="modo" value="<?= e($modo) ?>"><input type="hidden" name="vac" value="<?= e($vac) ?>">
  <?php foreach ($sel as $k): ?><input type="hidden" name="g[]" value="<?= e($k) ?>"><?php endforeach ?>
  <p>Al publicar se escribe el turno de cada día de <?= e($anio) ?> de estas <?= count($plan['personas']) ?> personas (sustituye lo planificado antes en ese año) y se les avisa. Después puedes retocar cualquier día en el Calendario.</p>
  <button name="que" value="publicar" data-confirmar="¿Publicar el calendario de <?= e($anio) ?> para <?= count($plan['personas']) ?> personas?">Publicar el calendario de <?= e($anio) ?></button>
</form>
<?php endif;
pie();
