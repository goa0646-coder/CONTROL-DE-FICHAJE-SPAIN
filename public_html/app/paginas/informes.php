<?php
$yo = requiere(...ROLES);
if (!puede($yo, 'informes')) {
    http_response_code(403);
    exit('No tienes permiso para ver informes.');
}
$fecha = fn($k, $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : $def;
$desde = $fecha('desde', local(ahora(), 'Y-m') . '-01');
$hasta = max($desde, $fecha('hasta', local(ahora(), 'Y-m-d')));
// Semana, mes o año (con ‹ › para moverse): fija el periodo a partir de la fecha de referencia.
$periodo = in_array($_GET['periodo'] ?? '', ['semana', 'mes', 'anio'], true) ? $_GET['periodo'] : null;
if ($periodo) {
    $ref = strtotime($fecha('ref', local(ahora(), 'Y-m-d')));
    [$desde, $hasta] = match ($periodo) {
        'semana' => [date('Y-m-d', strtotime('monday this week', $ref)), date('Y-m-d', strtotime('sunday this week', $ref))],
        'mes' => [date('Y-m-01', $ref), date('Y-m-t', $ref)],
        'anio' => [date('Y-01-01', $ref), date('Y-12-31', $ref)],
    };
}
$mover = fn(int $n) => date('Y-m-d', strtotime(($n > 0 ? '+' : '') . $n . ' ' . ['semana' => 'week', 'mes' => 'month', 'anio' => 'year'][$periodo ?? 'mes'], strtotime($desde)));
if ((strtotime($hasta) - strtotime($desde)) > 400 * 86400) $hasta = date('Y-m-d', strtotime("$desde +400 days"));
$dep = (int)($_GET['dep'] ?? 0);
$cen = (int)($_GET['centro'] ?? 0);
$personas = array_values(array_filter(personas_visibles(db(), $yo), fn($p) => (!$dep || $p['departamento_id'] == $dep) && (!$cen || $p['centro_id'] == $cen)
    && local($p['alta_utc'], 'Y-m-d') <= $hasta && (!$p['baja_utc'] || local($p['baja_utc'], 'Y-m-d') >= $desde)));
// Equidad: reparto de turnos, noches, fines de semana y festivos frente a la media de su puesto, y preferencias cumplidas.
if (($_GET['vista'] ?? '') === 'equidad') {
    [$filas, $medias] = equidad(db(), array_values(array_filter($personas, fn($p) => $p['activo'])), $desde, $hasta);
    $plan = [];
    foreach ($filas as $x) foreach (calendario_teorico(db(), $x['persona'], $desde, $hasta) as $f => $c) $plan[$x['persona']['id']][$f] = $c['turno'] ? ['horario_id' => $c['horario_id']] : null;
    $cumple = cumplimiento_preferencias($plan, preferencias_activas(db()));
    $ev = [];
    foreach (consulta(db(), "SELECT v.sobre_id, AVG(r.valor) m FROM encuesta_respuestas r JOIN encuesta_envios v ON v.id = r.envio_id JOIN encuesta_preguntas q ON q.id = r.pregunta_id
        WHERE v.sobre_id IS NOT NULL AND q.tipo = 'escala' GROUP BY v.sobre_id") as $r) $ev[$r['sobre_id']] = (float)$r['m'];
    usort($filas, fn($a, $b) => [$a['grupo'], nombre($a['persona'])] <=> [$b['grupo'], nombre($b['persona'])]);
    $marca = function (array $x, string $k) use ($medias) {
        $m = $medias[$x['grupo']][$k];
        $d = $m ? ($x[$k] - $m) / $m : 0;
        return '<td class="' . (abs($d) > .2 && abs($x[$k] - $m) >= 2 ? ($d > 0 ? 'error' : 'nota') : '') . '" title="Media del puesto: ' . round($k === 'min' ? $m / 60 : $m, 1) . '">' . ($k === 'min' ? horas($x[$k] * 60) : $x[$k]) . '</td>';
    };
    cabecera('Informes', $yo); ?>
<nav class="segmentos no-imprimir"><a href="?p=informes">Resumen del periodo</a><a href="?p=informes&vista=diario">Presencia diaria</a><a href="?p=informes&vista=equidad" class="activo">Equidad de turnos</a></nav>
<form class="filtros no-imprimir"><input type="hidden" name="p" value="informes"><input type="hidden" name="vista" value="equidad">
  <label>Desde <input type="date" name="desde" value="<?= e($desde) ?>"></label><label>Hasta <input type="date" name="hasta" value="<?= e($hasta) ?>"></label><button>Ver</button></form>
<p class="nota">Turnos del cuadrante (lo planificado). En <span class="error">rojo</span>, más de un 20 % por encima de la media de su puesto; en gris, por debajo. Pasa el ratón para ver la media. Las preferencias son sugerencias del trabajador; la valoración, la media de sus evaluaciones (1 a 5), solo informativa.</p>
<table>
  <tr><th>Persona</th><th>Puesto</th><th>Turnos</th><th>Horas</th><th>Noches</th><th>Fines de semana</th><th>Festivos</th><th>Preferencias</th><th>Valoración</th></tr>
  <?php foreach ($filas as $x): $c = $cumple[$x['persona']['id']] ?? null; ?>
  <tr><td><?= e(nombre($x['persona'])) ?></td><td><small><?= e($x['grupo']) ?></small></td><?= $marca($x, 'turnos') . $marca($x, 'min') . $marca($x, 'noches') . $marca($x, 'findes') . $marca($x, 'festivos') ?>
    <td><?= $c ? $c[0] . ' de ' . $c[1] . ' (' . round(100 * $c[0] / $c[1]) . ' %)' : '' ?></td><td><?= isset($ev[$x['persona']['id']]) ? number_format($ev[$x['persona']['id']], 1, ',', '') : '' ?></td></tr>
  <?php endforeach ?>
</table>
<?php pie();
    return;
}
// Presencia diaria: un día, persona a persona.
if (($_GET['vista'] ?? '') === 'diario') {
    $dia = $fecha('dia', local(ahora(), 'Y-m-d'));
    $todas = array_values(array_filter(personas_visibles(db(), $yo), fn($p) => $p['activo'] && (!$dep || $p['departamento_id'] == $dep) && (!$cen || $p['centro_id'] == $cen)));
    $filas = presencia_dia(db(), $todas, $dia);
    auditar(db(), (int)$yo['id'], isset($_GET['csv']) ? 'informe_descargado' : 'informe_visto', "presencia=$dia personas=" . count($filas));
    $hm = fn($utc) => $utc ? local($utc, 'H:i') : '';
    if (isset($_GET['csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"presencia-$dia.csv\"");
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Fecha', 'Persona', 'DNI', 'Jornada', 'Entrada', 'Salida', 'Pausas', 'Teórico', 'Trabajado', 'Diferencia'], ';');
        foreach ($filas as $f) fputcsv($out, [date('d/m/Y', strtotime($dia)), nombre($f['persona']), $f['persona']['dni'], $f['jornada'], $hm($f['entrada']), $hm($f['salida']),
            horas($f['pausas']), horas($f['teorico']), horas($f['trabajado']), horas_signo($f['diferencia'])], ';');
        exit;
    }
    $deps = db()->query('SELECT id, nombre FROM departamentos ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
    $dentro = count(array_filter($filas, fn($f) => $f['abierta']));
    $sin = count(array_filter($filas, fn($f) => $f['teorico'] && !$f['entrada']));
    cabecera('Presencia diaria', $yo); ?>
<nav class="segmentos no-imprimir"><a href="?p=informes">Resumen del periodo</a><a href="?p=informes&vista=diario" class="activo">Presencia diaria</a></nav>
<form class="filtros no-imprimir">
  <input type="hidden" name="p" value="informes"><input type="hidden" name="vista" value="diario">
  <a class="boton secundario" href="?p=informes&vista=diario&dia=<?= date('Y-m-d', strtotime("$dia -1 day")) ?>&dep=<?= $dep ?>">‹</a>
  <label><input type="date" name="dia" value="<?= e($dia) ?>"></label>
  <a class="boton secundario" href="?p=informes&vista=diario&dia=<?= date('Y-m-d', strtotime("$dia +1 day")) ?>&dep=<?= $dep ?>">›</a>
  <?php if ($deps): ?><label><select name="dep"><?= opciones(['' => 'Todos los departamentos'] + $deps, $dep) ?></select></label><?php endif ?>
  <button>Ver</button>
  <a class="boton secundario" href="?<?= e(http_build_query(['csv' => 1] + $_GET + ['vista' => 'diario', 'dia' => $dia])) ?>">Excel (CSV)</a>
  <button type="button" data-imprimir class="secundario">Imprimir / PDF</button>
</form>
<div class="cifras">
  <div><span>Personas</span><strong><?= count($filas) ?></strong></div>
  <div><span>Trabajando ahora</span><strong><?= $dentro ?></strong></div>
  <div><span>Sin fichar (con horario)</span><strong class="<?= $sin ? 'error' : '' ?>"><?= $sin ?></strong></div>
  <div><span>Ausentes</span><strong><?= count(array_filter($filas, fn($f) => $f['ausencia'])) ?></strong></div>
</div>
<table>
  <tr><th>Persona</th><th>Jornada</th><th>Entrada</th><th>Salida</th><th>Pausas</th><th>Teórico</th><th>Total</th><th>Diferencia</th></tr>
  <?php foreach ($filas as $f): $tarde = $f['entrada'] && $f['horario_entrada'] && local($f['entrada'], 'H:i:s') > $f['horario_entrada']; ?>
  <tr><td><a class="con-avatar" href="?p=historial&u=<?= e($f['persona']['id']) ?>&mes=<?= substr($dia, 0, 7) ?>"><?= avatar($f['persona'], 'mini') ?> <?= e(nombre($f['persona'])) ?></a></td>
    <td><?= e($f['jornada']) ?></td>
    <td class="<?= $tarde ? 'error' : '' ?>"><?= $hm($f['entrada']) ?: ($f['teorico'] ? '<span class="error">Sin fichar</span>' : '') ?></td>
    <td><?= $f['abierta'] ? '<span class="estado dentro">Dentro</span>' : $hm($f['salida']) ?></td>
    <td class="<?= $f['pausa_obligatoria'] && $f['entrada'] && $f['pausas'] < $f['pausa_obligatoria'] ? 'error' : '' ?>" title="<?= $f['pausa_obligatoria'] ? 'Pausa obligatoria del turno: ' . horas($f['pausa_obligatoria']) . ' h' : '' ?>"><?= $f['pausas'] ? horas($f['pausas']) : '' ?></td><td><?= horas($f['teorico']) ?></td><td><?= horas($f['trabajado']) ?></td>
    <td class="<?= $f['diferencia'] < 0 ? 'error' : '' ?>"><?= horas_signo($f['diferencia']) ?></td></tr>
  <?php endforeach ?>
</table>
<p class="nota">Hora de Madrid. En rojo: entrada después de la hora del horario, o sin fichar teniendo horario. Pulsa en una persona para ver su registro.</p>
<?php pie();
    exit;
}
$filas = informe(db(), $personas, $desde, $hasta);
// Si el periodo es un mes completo, se muestra quién ha firmado su registro.
$mes_completo = substr($desde, 8) === '01' && $hasta === date('Y-m-t', strtotime($desde)) ? substr($desde, 0, 7) : null;
$tipos = array_column(tipos_ausencia(db(), false), 'nombre');
auditar(db(), (int)$yo['id'], isset($_GET['csv']) ? 'informe_descargado' : 'informe_visto', "desde=$desde hasta=$hasta personas=" . count($personas));
$empresa = db()->query('SELECT * FROM empresa')->fetch() ?: ['nombre' => '', 'cif' => '', 'horas_anuales' => null];

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"informe-$desde-$hasta.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $dec = fn($s) => number_format($s / 3600, 2, ',', '');
    fputcsv($out, ['Nº empleado', 'Nombre', 'DNI', 'Días trabajados', 'Horas teóricas', 'Horas trabajadas', 'Horas extra', 'Horas nocturnas', 'Horas festivo/libre', 'Diferencia', 'Jornadas abiertas', ...$tipos], ';');
    foreach ($filas as $f) {
        fputcsv($out, [$f['persona']['num_empleado'], nombre($f['persona']), $f['persona']['dni'], $f['dias_trabajados'], $dec($f['teorico']), $dec($f['trabajado']),
            $dec($f['extra']), $dec($f['nocturno']), $dec($f['festivo']), $dec($f['diferencia']), $f['abiertas'], ...array_map(fn($t) => $f['ausencias'][$t] ?? 0, $tipos)], ';');
    }
    exit;
}

$deps = db()->query('SELECT id, nombre FROM departamentos ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
$centros = db()->query('SELECT id, nombre FROM centros ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
$tot = ['teorico' => 0, 'trabajado' => 0, 'extra' => 0, 'nocturno' => 0, 'festivo' => 0, 'diferencia' => 0];
foreach ($filas as $f) foreach ($tot as $k => $_) $tot[$k] += $f[$k];
cabecera('Informes', $yo);
?>
<div class="solo-imprimir"><p><strong><?= e($empresa['nombre']) ?></strong> · CIF <?= e($empresa['cif']) ?> · Informe de jornada del <?= date('d/m/Y', strtotime($desde)) ?> al <?= date('d/m/Y', strtotime($hasta)) ?></p></div>
<nav class="segmentos no-imprimir"><a href="?p=informes" class="activo">Resumen del periodo</a><a href="?p=informes&vista=diario">Presencia diaria</a><a href="?p=informes&vista=equidad&desde=<?= e($desde) ?>&hasta=<?= e($hasta) ?>">Equidad de turnos</a></nav>
<?php $q = fn(array $x) => '?' . http_build_query(array_merge(array_diff_key($_GET, ['desde' => 1, 'hasta' => 1, 'csv' => 1]), ['p' => 'informes'], $x)); ?>
<nav class="segmentos no-imprimir">
  <?php foreach (['semana' => 'Semana', 'mes' => 'Mes', 'anio' => 'Año'] as $k => $t): ?><a href="<?= e($q(['periodo' => $k, 'ref' => $desde])) ?>" class="<?= $periodo === $k ? 'activo' : '' ?>"><?= $t ?></a><?php endforeach ?>
  <?php if ($periodo): ?><a href="<?= e($q(['ref' => $mover(-1)])) ?>" aria-label="Anterior">‹</a><a href="<?= e($q(['ref' => $mover(1)])) ?>" aria-label="Siguiente">›</a><?php endif ?>
</nav>
<p class="no-imprimir"><strong><?= $periodo === 'semana' ? 'Semana del ' . date('d/m', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)) : ($periodo === 'mes' ? ucfirst(MESES[(int)substr($desde, 5, 2)]) . ' ' . substr($desde, 0, 4) : ($periodo === 'anio' ? 'Año ' . substr($desde, 0, 4) : 'Del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)))) ?></strong> · horas trabajadas (fichadas) frente a las de su cuadrante</p>
<form class="filtros no-imprimir">
  <input type="hidden" name="p" value="informes">
  <label>Desde <input type="date" name="desde" value="<?= e($desde) ?>"></label>
  <label>Hasta <input type="date" name="hasta" value="<?= e($hasta) ?>"></label>
  <?php if ($deps): ?><label>Departamento <select name="dep"><?= opciones(['' => 'Todos'] + $deps, $dep) ?></select></label><?php endif ?>
  <?php if (count($centros) > 1): ?><label>Centro <select name="centro"><?= opciones(['' => 'Todos'] + $centros, $cen) ?></select></label><?php endif ?>
  <button>Ver</button>
  <a class="boton secundario" href="?<?= e(http_build_query(['csv' => 1] + $_GET)) ?>">Excel (CSV) / gestoría</a>
  <button type="button" data-imprimir class="secundario">Imprimir / PDF</button>
</form>
<p class="nota no-imprimir">Accesos directos:
  <a href="?p=informes&desde=<?= local(ahora(), 'Y-m-d') ?>&hasta=<?= local(ahora(), 'Y-m-d') ?>">Hoy</a> ·
  <a href="?p=informes&desde=<?= date('Y-m-01', strtotime('first day of last month')) ?>&hasta=<?= date('Y-m-t', strtotime('last day of last month')) ?>">Mes pasado</a> ·
  <a href="?p=informes&desde=<?= local(ahora(), 'Y') ?>-01-01&hasta=<?= local(ahora(), 'Y-m-d') ?>">Este año</a></p>

<div class="cifras">
  <div><span>Personas</span><strong><?= count($filas) ?></strong></div>
  <div><span>Trabajado</span><strong><?= horas($tot['trabajado']) ?></strong></div>
  <div><span>Teórico</span><strong><?= horas($tot['teorico']) ?></strong></div>
  <div><span>Extra</span><strong><?= horas($tot['extra']) ?></strong></div>
  <div><span>Nocturno</span><strong><?= horas($tot['nocturno']) ?></strong></div>
</div>
<table>
  <tr><th>Persona</th><th>Días</th><th>Teórico</th><th>Trabajado</th><th>Extra</th><th>Noct.</th><th>Fest./libre</th><th>Dif.</th><th>Ausencias</th><th>Abiertas</th><?= $mes_completo ? '<th>Firma del mes</th>' : '' ?></tr>
  <?php foreach ($filas as $f): ?>
  <tr><td><a href="?p=historial&u=<?= e($f['persona']['id']) ?>&mes=<?= substr($desde, 0, 7) ?>"><?= e(nombre($f['persona'])) ?></a></td>
    <td><?= $f['dias_trabajados'] ?></td><td><?= horas($f['teorico']) ?></td><td><?= horas($f['trabajado']) ?></td><td><?= horas($f['extra']) ?></td>
    <td><?= horas($f['nocturno']) ?></td><td><?= horas($f['festivo']) ?></td><td class="<?= $f['diferencia'] < 0 ? 'error' : '' ?>"><?= horas_signo($f['diferencia']) ?></td>
    <td><?= e(implode(', ', array_map(fn($t, $n) => "$t: $n", array_keys($f['ausencias']), $f['ausencias']))) ?></td>
    <td class="<?= $f['abiertas'] ? 'error' : '' ?>"><?= $f['abiertas'] ?: '' ?></td>
    <?php if ($mes_completo): $fm = firma_mes(db(), $f['persona'], $mes_completo);
        $ok = $fm && $fm['hash'] === hash('sha256', contenido_mes(db(), $f['persona'], $mes_completo)); ?>
    <td class="<?= $ok ? '' : 'error' ?>"><?= $fm ? local($fm['firmado_utc']) . ' · IP ' . e($fm['ip']) . ($ok ? '' : ' (hay que volver a firmar)') : 'Sin firmar' ?></td>
    <?php endif ?></tr>
  <?php endforeach ?>
</table>
<h2>Convenio: puntualidad, festivos y domingos</h2>
<?php $r = reglas(db()); $hay = false; ?>
<table>
  <tr><th>Persona</th><th>Retrasos sin justificar</th><th>Festivos trabajados</th><th>Domingos</th><th>Turnos 24-25/12 y 31/12-1/1</th></tr>
  <?php foreach ($filas as $f): $nivel = nivel_retrasos($f['retrasos'], $r); ?>
  <tr><td><?= e(nombre($f['persona'])) ?></td>
    <td class="<?= $nivel ? 'error' : '' ?>"><?= array_sum($f['retrasos']) ?><?= $nivel ? " · peor mes: " . max($f['retrasos']) . " (falta $nivel según el convenio)" : '' ?></td>
    <td><?= $f['festivos_trabajados'] ?><?= $f['festivos_trabajados'] && $r['festivo_compensa_dias'] ? ' → ' . str_replace('.', ',', (string)round($f['festivos_trabajados'] * $r['festivo_compensa_dias'], 2)) . ' días de descanso' : '' ?></td>
    <td><?= $f['domingos'] ?></td><td><?= $f['turnos_especiales'] ?: '' ?></td></tr>
  <?php endforeach ?>
</table>
<p class="nota">Los retrasos son información para la empresa: una sanción exige el procedimiento del convenio y escuchar a la persona. Los retrasos justificados (corrección o ausencia aprobada) no cuentan.</p>

<h2>Cumplimiento del convenio</h2>
<table>
  <?php foreach ($filas as $f): foreach (comprobar_real(db(), $f['persona'], $desde, $hasta, $r) as [$dia, , $txt]): $hay = true; ?>
  <tr><td><?= e(nombre($f['persona'])) ?></td><td><?= date('d/m/Y', strtotime($dia)) ?></td><td class="error"><?= e($txt) ?></td></tr>
  <?php endforeach; endforeach ?>
</table>
<?php if (!$hay): ?><p class="aviso ok">Sin incumplimientos en el periodo: descansos entre jornadas, pausas y horas extra dentro de las reglas.</p><?php endif ?>
<?php if ($empresa['horas_anuales']): ?>
<p class="nota">Convenio: <?= e($empresa['horas_anuales']) ?> h/año. Para comparar, elige el año completo.</p>
<?php endif;
pie();
