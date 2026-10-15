<?php
$yo = requiere(...ROLES);
$u = usuario(db(), (int)($_GET['u'] ?? $yo['id']));
if (!$u || !puede_ver($yo, $u)) {
    http_response_code(403);
    exit('No tienes permiso para ver este registro.');
}
$mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['mes'] ?? '') ? $_GET['mes'] : local(ahora(), 'Y-m');
$siguiente = (new DateTime("$mes-01"))->modify('+1 month')->format('Y-m');
// Un día de margen a cada lado para no cortar jornadas que cruzan el cambio de mes.
$todos = fichajes_de(db(), (int)$u['id'], a_utc("$mes-01 -1 day"), a_utc("$siguiente-01 +1 day"));
$fichajes = array_values(array_filter($todos, fn($f) => local($f['momento_utc'], 'Y-m') === $mes));
$jornadas = array_values(array_filter(jornadas($todos), fn($j) => local($j['entrada'], 'Y-m') === $mes));
$correcciones = array_filter(correcciones_de(db(), (int)$u['id']), fn($c) => local($c['pedida_utc'], 'Y-m') === $mes
    || local($c['momento_propuesto_utc'], 'Y-m') === $mes || local($c['f_momento'], 'Y-m') === $mes);
$propio = $yo['id'] == $u['id'];

if (!$propio) auditar(db(), (int)$yo['id'], isset($_GET['csv']) ? 'descarga_registro' : 'ver_registro', "usuario={$u['id']} mes=$mes");

if (isset($_GET['csv'])) {
    if ($propio) auditar(db(), (int)$yo['id'], 'descarga_registro', "mes=$mes");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="registro-' . preg_replace('/\W/', '', $u['num_empleado'] ?: $u['id']) . "-$mes.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel lea los acentos
    fputcsv($out, ['Empleado', 'DNI', 'Fecha', 'Hora', 'Tipo', 'Origen', 'Zona', 'Estado', 'Corrección nº'], ';');
    foreach ($fichajes as $f) {
        fputcsv($out, [nombre($u), $u['dni'], local($f['momento_utc'], 'd/m/Y'), local($f['momento_utc'], 'H:i:s'), TIPOS[$f['tipo']],
            $f['origen'], $f['zona'] ?? '', $f['anulado'] ? 'anulado' : 'válido', $f['correccion_id'] ?? ''], ';');
    }
    exit;
}

$total = array_sum(array_column($jornadas, 'trabajado'));
cabecera($propio ? 'Mi registro' : 'Registro de ' . nombre($u), $yo);
?>
<form class="filtros">
  <input type="hidden" name="p" value="historial"><input type="hidden" name="u" value="<?= e($u['id']) ?>">
  <label>Mes <input type="month" name="mes" value="<?= e($mes) ?>"></label>
  <button>Ver</button>
  <a class="boton" href="?<?= e(http_build_query(['p' => 'historial', 'u' => $u['id'], 'mes' => $mes, 'csv' => 1])) ?>">Descargar CSV</a>
</form>

<h2>Jornadas · total <?= horas($total) ?> h</h2>
<table>
  <tr><th>Día</th><th>Entrada</th><th>Salida</th><th>Pausas</th><th>Trabajado</th></tr>
  <?php foreach ($jornadas as $j): ?>
  <tr>
    <td><?= local($j['entrada'], 'd/m/Y') ?></td><td><?= local($j['entrada'], 'H:i') ?></td>
    <td><?= $j['salida'] ? local($j['salida'], 'H:i') . (local($j['salida'], 'Y-m-d') !== local($j['entrada'], 'Y-m-d') ? ' (+1)' : '') : '<span class="error">abierta</span>' ?></td>
    <td><?= horas($j['pausa']) ?></td><td><?= horas($j['trabajado']) ?></td>
  </tr>
  <?php endforeach ?>
</table>

<h2>Fichajes</h2>
<table>
  <tr><th>Fecha y hora</th><th>Tipo</th><th>Origen</th><th>Zona</th><th></th></tr>
  <?php foreach ($fichajes as $f): ?>
  <tr class="<?= $f['anulado'] ? 'anulado' : '' ?>">
    <td><?= local($f['momento_utc'], 'd/m/Y H:i:s') ?></td><td><?= TIPOS[$f['tipo']] ?></td>
    <td><?= e($f['origen']) ?><?= $f['correccion_id'] ? ' nº' . e($f['correccion_id']) : '' ?></td>
    <td><?= e($f['zona'] ?? '') ?></td>
    <td><?= $f['anulado'] ? 'anulado' : ($propio && $yo['rol'] !== 'inspeccion' ? '<a href="?p=correcciones&f=' . e($f['id']) . '">Corregir</a>' : '') ?></td>
  </tr>
  <?php endforeach ?>
</table>

<?php if ($correcciones): ?>
<h2>Correcciones</h2>
<table>
  <tr><th>Nº</th><th>Qué</th><th>Motivo</th><th>Pedida</th><th>Estado</th><th>Resuelta por</th></tr>
  <?php foreach ($correcciones as $c): ?>
  <tr>
    <td><?= e($c['id']) ?></td>
    <td><?= ACCIONES[$c['accion']] ?>
      <?= $c['f_momento'] ? '<br>Original: ' . TIPOS[$c['f_tipo']] . ' ' . local($c['f_momento']) : '' ?>
      <?= $c['momento_propuesto_utc'] ? '<br>Nuevo: ' . TIPOS[$c['tipo_propuesto']] . ' ' . local($c['momento_propuesto_utc']) : '' ?></td>
    <td><?= e($c['motivo']) ?></td><td><?= local($c['pedida_utc']) ?></td>
    <td><?= e($c['estado']) ?><?= $c['comentario'] !== '' ? '<br><small>' . e($c['comentario']) . '</small>' : '' ?></td>
    <td><?= $c['resuelta_utc'] ? e(trim($c['r_nombre'] . ' ' . $c['r_apellidos'])) . '<br>' . local($c['resuelta_utc']) : '' ?></td>
  </tr>
  <?php endforeach ?>
</table>
<?php endif;
pie();
