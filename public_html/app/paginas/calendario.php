<?php
$yo = requiere('empleado', 'responsable', 'admin');
$mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['mes'] ?? '') ? $_GET['mes'] : local(ahora(), 'Y-m');
$desde = "$mes-01";
$hasta = (new DateTime($desde))->modify('last day of this month')->format('Y-m-d');
$dep = (int)($_GET['dep'] ?? 0);
$jefe = $yo['rol'] !== 'empleado';

// Responsable/admin: su equipo con el tipo de ausencia. Empleado: sus compañeros, solo "ausente" (RGPD: el tipo puede ser dato de salud).
if ($jefe) {
    $personas = array_values(array_filter(equipo_de(db(), $yo), fn($p) => $p['activo'] && (!$dep || $p['departamento_id'] == $dep)));
    if (!in_array($yo['id'], array_column($personas, 'id'))) array_unshift($personas, $yo);
} else {
    $personas = consulta(db(), "SELECT * FROM usuarios WHERE activo = 1 AND rol <> 'inspeccion' AND id <> ? AND
        ((responsable_id IS NOT NULL AND responsable_id = ?) OR (departamento_id IS NOT NULL AND departamento_id = ?)) ORDER BY nombre", [$yo['id'], $yo['responsable_id'], $yo['departamento_id']])->fetchAll();
    array_unshift($personas, $yo);
}
$pendientes = [];
foreach (consulta(db(), "SELECT usuario_id, desde, hasta FROM ausencias WHERE estado = 'pendiente' AND desde <= ? AND hasta >= ?", [$hasta, $desde]) as $a) {
    foreach (rango_fechas(max($desde, $a['desde']), min($hasta, $a['hasta'])) as $f) $pendientes[$a['usuario_id']][$f] = true;
}
$fechas = rango_fechas($desde, $hasta);
$hoy = local(ahora(), 'Y-m-d');
$deps = db()->query('SELECT id, nombre FROM departamentos WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
$ant = (new DateTime($desde))->modify('-1 month')->format('Y-m');
$sig = (new DateTime($desde))->modify('+1 month')->format('Y-m');
cabecera('Calendario', $yo);
?>
<form class="filtros">
  <input type="hidden" name="p" value="calendario">
  <a class="boton secundario" href="?p=calendario&mes=<?= $ant ?>&dep=<?= $dep ?>">‹</a>
  <label><input type="month" name="mes" value="<?= e($mes) ?>"></label>
  <a class="boton secundario" href="?p=calendario&mes=<?= $sig ?>&dep=<?= $dep ?>">›</a>
  <?php if ($jefe && $deps): ?><label><select name="dep"><?= opciones(['' => 'Todos los departamentos'] + $deps, $dep) ?></select></label><?php endif ?>
  <button>Ver</button>
</form>
<table class="calendario">
  <tr><th class="persona"></th><?php foreach ($fechas as $f): ?><th><?= mb_substr(DIAS[(int)date('N', strtotime($f))], 0, 1) ?><br><?= (int)substr($f, 8) ?></th><?php endforeach ?></tr>
  <?php foreach ($personas as $p):
      $cal = calendario_teorico(db(), $p, $desde, $hasta);
      $detalle = $jefe || $p['id'] == $yo['id']; ?>
  <tr><td class="persona"><?= e(nombre($p)) ?></td>
    <?php foreach ($cal as $f => $c):
        $clase = ($f === $hoy ? 'hoy ' : '') . (!$c['laborable'] || $c['festivo'] ? 'libre' : '');
        if ($c['ausencia']) {
            // Iniciales (Vacaciones → V, Visita médica → VM) para no confundir tipos.
            $txt = $detalle ? implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_filter(explode(' ', $c['ausencia']['tipo']), fn($w) => mb_strlen($w) > 3 || $w === 'IT'))) : '·';
            $titulo = $detalle ? $c['ausencia']['tipo'] : 'Ausente';
            echo '<td class="' . $clase . '" title="' . e($titulo) . '" style="background:' . e($detalle ? $c['ausencia']['color'] : '#6b7280') . ';color:#fff">' . e($txt) . '</td>';
        } elseif (isset($pendientes[$p['id']][$f])) {
            echo '<td class="' . $clase . '" title="Pendiente de aprobar">?</td>';
        } else {
            $titulo = $c['festivo'] ?: ($c['horario'] ? $c['horario'] . ($c['entrada'] && !$c['flexible'] ? ' ' . substr($c['entrada'], 0, 5) : '') : '');
            echo '<td class="' . $clase . '" title="' . e($titulo) . '">' . ($c['festivo'] ? 'F' : ($c['minutos'] ? e(rtrim(rtrim(number_format($c['minutos'] / 60, 1, ',', ''), '0'), ',')) : '')) . '</td>';
        }
    endforeach ?></tr>
  <?php endforeach ?>
</table>
<p class="nota">Números = horas previstas. F = festivo. ? = ausencia pendiente. Pasa el ratón (o mantén pulsado) para ver el detalle.</p>
<?php if ($jefe): ?>
<p class="nota">Leyenda: <?php foreach (tipos_ausencia(db()) as $t): ?><span class="etiqueta" style="background:<?= e($t['color']) ?>"><?= e($t['nombre']) ?></span> <?php endforeach ?></p>
<?php endif;
pie();
