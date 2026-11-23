<?php
$yo = requiere(...ROLES);
if (!puede($yo, 'informes')) {
    http_response_code(403);
    exit('No tienes permiso para ver informes.');
}
$fecha = fn($k, $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : $def;
$desde = $fecha('desde', local(ahora(), 'Y-m') . '-01');
$hasta = max($desde, $fecha('hasta', local(ahora(), 'Y-m-d')));
if ((strtotime($hasta) - strtotime($desde)) > 400 * 86400) $hasta = date('Y-m-d', strtotime("$desde +400 days"));
$dep = (int)($_GET['dep'] ?? 0);
$cen = (int)($_GET['centro'] ?? 0);
$personas = array_values(array_filter(personas_visibles(db(), $yo), fn($p) => (!$dep || $p['departamento_id'] == $dep) && (!$cen || $p['centro_id'] == $cen)
    && local($p['alta_utc'], 'Y-m-d') <= $hasta && (!$p['baja_utc'] || local($p['baja_utc'], 'Y-m-d') >= $desde)));
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
<form class="filtros no-imprimir">
  <input type="hidden" name="p" value="informes">
  <label>Desde <input type="date" name="desde" value="<?= e($desde) ?>"></label>
  <label>Hasta <input type="date" name="hasta" value="<?= e($hasta) ?>"></label>
  <?php if ($deps): ?><label>Departamento <select name="dep"><?= opciones(['' => 'Todos'] + $deps, $dep) ?></select></label><?php endif ?>
  <?php if (count($centros) > 1): ?><label>Centro <select name="centro"><?= opciones(['' => 'Todos'] + $centros, $cen) ?></select></label><?php endif ?>
  <button>Ver</button>
  <a class="boton" href="?<?= e(http_build_query(['csv' => 1] + $_GET)) ?>">Excel (CSV) / gestoría</a>
  <button type="button" data-imprimir>Imprimir / PDF</button>
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
<h2>Cumplimiento del convenio</h2>
<?php $r = reglas(db()); $hay = false; ?>
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
