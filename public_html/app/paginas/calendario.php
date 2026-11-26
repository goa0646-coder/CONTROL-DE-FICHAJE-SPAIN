<?php
$yo = requiere('empleado', 'responsable', 'admin');
$mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['mes'] ?? '') ? $_GET['mes'] : local(ahora(), 'Y-m');
$desde = "$mes-01";
$hasta = (new DateTime($desde))->modify('last day of this month')->format('Y-m-d');
$dep = (int)($_GET['dep'] ?? 0);
$jefe = $yo['rol'] !== 'empleado';
$editar = $jefe && puede($yo, 'planificar');

// Pulsar un día de una persona y elegir turno: se guarda al momento (con las reglas del convenio).
if (es_post() && ($_POST['que'] ?? '') === 'celda') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $u = usuario(db(), (int)($_POST['u'] ?? 0));
        if (!$editar || !$u || !$u['activo'] || !puede_ver($yo, $u) || $u['rol'] === 'inspeccion') throw new DomainException('No puedes cambiar el turno de esta persona.');
        $f = (string)($_POST['f'] ?? '');
        $avisos = cambiar_dia(db(), $yo, $u, $f, (string)($_POST['v'] ?? ''));
        [$txt, $fondo, $titulo] = celda_turno(calendario_teorico(db(), $u, $f, $f)[$f], horarios(db()), true);
        exit(json_encode(['txt' => $txt, 'fondo' => $fondo, 'titulo' => $titulo, 'avisos' => $avisos], JSON_UNESCAPED_UNICODE));
    } catch (DomainException $e) {
        http_response_code(422);
        exit(json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
    }
}

// Responsable/admin: su equipo. Empleado: solo su propio calendario (los turnos y ausencias de los compañeros no son cosa suya).
if ($jefe) {
    $personas = array_values(array_filter(equipo_de(db(), $yo), fn($p) => $p['activo'] && (!$dep || $p['departamento_id'] == $dep)));
    if (!in_array($yo['id'], array_column($personas, 'id'))) array_unshift($personas, $yo);
} else {
    $personas = [$yo];
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
// Responsable/admin: personas trabajando cada día, horas del mes y días que no llegan al ratio.
$cals = [];
foreach ($personas as $p) $cals[$p['id']] = calendario_teorico(db(), $p, $desde, $hasta);
$trabajan = array_fill_keys($fechas, 0);
foreach ($cals as $cal) foreach ($cal as $f => $c) if ($c['minutos'] > 0 && !$c['ausencia'] && !$c['festivo']) $trabajan[$f]++;
$cortos = [];
$hors_n = array_column(horarios(db()), 'nombre', 'id');
// El ratio es de toda la plantilla, aunque se esté viendo un equipo o departamento.
// El ratio es exacto: en rojo los días donde falta o sobra gente en algún turno.
$desv = [];
if ($jefe) foreach (desvios(db(), db()->query("SELECT * FROM usuarios WHERE activo = 1 AND rol <> 'inspeccion'")->fetchAll(), $desde, $hasta) as [$f, $hid, , $n]) {
    $cortos[$f] = true;
    $desv[$f][] = ($n > 0 ? 'faltan ' . $n : 'sobran ' . -$n) . ' en ' . ($hors_n[$hid] ?? 'turno');
}
$pts = puestos(db());
$hors = horarios(db());
$pp = puestos_por_persona(db());
cabecera('Calendario', $yo);
?>
<?php if ($jefe): ?>
<form class="filtros navegar-mes">
  <input type="hidden" name="p" value="calendario">
  <span class="mes-flechas"><a class="boton secundario" href="?p=calendario&mes=<?= $ant ?>&dep=<?= $dep ?>" aria-label="Mes anterior">‹</a>
  <strong class="mes-nombre"><?= e(ucfirst(MESES[(int)substr($mes, 5)]) . ' ' . substr($mes, 0, 4)) ?></strong>
  <a class="boton secundario" href="?p=calendario&mes=<?= $sig ?>&dep=<?= $dep ?>" aria-label="Mes siguiente">›</a></span>
  <label class="elegir-mes"><input type="month" name="mes" value="<?= e($mes) ?>" onchange="this.form.submit()" aria-label="Elegir mes"></label>
  <?php if ($jefe && $deps): ?><label><select name="dep"><?= opciones(['' => 'Todos los departamentos'] + $deps, $dep) ?></select></label><?php endif ?>
  <?php if ($jefe && $deps): ?><button>Ver</button><?php endif ?>
</form>
<?php endif ?>
<?php if (!$jefe):
    $mio = $cals[$yo['id']];
    $trabajo = array_filter($mio, fn($c) => $c['turno'] > 0 && !$c['ausencia']);
    $aus = array_filter($mio, fn($c) => $c['ausencia'] && $c['ausencia']['minutos'] === null); ?>
<div class="cifras">
  <div><span>Horas previstas</span><strong><?= horas(array_sum(array_column($trabajo, 'minutos')) * 60) ?> h</strong></div>
  <div><span>Días de trabajo</span><strong><?= count($trabajo) ?></strong></div>
  <div><span>Descansos</span><strong><?= count(array_filter($mio, fn($c) => !$c['laborable'] && !$c['ausencia'])) ?></strong></div>
  <div><span>Vacaciones y permisos</span><strong><?= count($aus) ?></strong></div>
</div>
<?php endif ?>
<?php if (!$jefe): ?>
<?= mes_trabajador(db(), $yo, $mes, 'calendario') ?>
<?php else: ?>
<table class="calendario cuadrante">
  <tr><th class="persona"></th><?php foreach ($fechas as $f): ?><th class="<?= isset($cortos[$f]) ? 'corto' : '' ?>"><?= mb_substr(DIAS[(int)date('N', strtotime($f))], 0, 1) ?><br><?= (int)substr($f, 8) ?></th><?php endforeach ?></tr>
  <?php if ($jefe): ?>
  <tr class="totales"><td class="persona"><small>Trabajan</small></td><?php foreach ($fechas as $f): ?><td class="<?= isset($cortos[$f]) ? 'corto' : '' ?>" title="<?= isset($desv[$f]) ? e(ucfirst(implode(' · ', $desv[$f]))) : '' ?>"><?= $trabajan[$f] ?></td><?php endforeach ?></tr>
  <?php endif ?>
  <?php foreach ($personas as $p):
      $cal = $cals[$p['id']];
      $detalle = $jefe || $p['id'] == $yo['id'];
      $mes_min = array_sum(array_map(fn($c) => $c['ausencia'] || $c['festivo'] ? 0 : $c['minutos'], $cal)); ?>
  <tr><td class="persona"><span class="con-avatar"><?= avatar($p, 'mini') ?> <span class="quien-cal"><?= $yo['rol'] === 'admin' ? '<a href="?p=usuarios&u=' . e($p['id']) . '" title="Abrir su ficha">' . e(nombre($p)) . '</a>' : e(nombre($p)) ?>
    <?php if ($jefe): ?><small><?= horas($mes_min * 60) ?> h<?= ($pp[$p['id']] ?? []) ? ' · ' . e(implode(', ', array_intersect_key($pts, array_flip($pp[$p['id']])))) : '' ?></small><?php endif ?></span></span></td>
    <?php foreach ($cal as $f => $c):
        $clase = ($f === $hoy ? 'hoy ' : '') . (!$c['laborable'] || $c['festivo'] ? 'libre ' : '');
        if (!$c['ausencia'] && isset($pendientes[$p['id']][$f])) {
            echo '<td class="' . $clase . '" title="Ausencia pendiente de aprobar">?</td>';
            continue;
        }
        [$txt, $fondo, $titulo] = celda_turno($c, $hors, $detalle);
        if ($fondo) $clase .= 'turno';
        elseif ($txt === 'D') $clase .= 'descanso';
        $editable = $editar && $p['activo'] && puede_ver($yo, $p);
        echo '<td class="' . $clase . ($editable ? ' editable' : '') . '" title="' . e($titulo) . '"' . ($fondo ? ' style="background:' . e($fondo) . '"' : '')
            . ($editable ? ' data-u="' . e($p['id']) . '" data-f="' . $f . '" data-n="' . e(nombre($p)) . '" data-d="' . e($p['departamento_id']) . '" tabindex="0"' : '') . '>' . e($txt) . '</td>';
    endforeach ?></tr>
  <?php endforeach ?>
</table>
<?php endif ?>
<?php if ($editar):
    $turnos = array_values(array_map(fn($h) => ['id' => (int)$h['id'], 'letra' => $h['abreviatura'], 'color' => $h['color'], 'nombre' => $h['nombre'], 'dep' => $h['departamento_id'] ? (int)$h['departamento_id'] : null,
        'horas' => ($d1 = $h['dias'] ? reset($h['dias']) : null) && $d1['entrada'] && !$h['flexible'] ? substr($d1['entrada'], 0, 5) . '–' . substr($d1['salida'], 0, 5) : ''], array_filter($hors, fn($h) => $h['activo']))); ?>
<dialog class="elegir-turno" data-turnos="<?= e(json_encode($turnos, JSON_UNESCAPED_UNICODE)) ?>" data-csrf="<?= e($_SESSION['csrf']) ?>">
  <form method="dialog">
    <h3 data-titulo></h3>
    <p class="nota" data-actual></p>
    <div class="opciones-turno" data-opciones></div>
    <p class="nota" data-estado></p>
    <button value="cancelar" class="secundario">Cerrar</button>
  </form>
</dialog>
<p class="nota">Pulsa en el día de una persona para cambiar su turno. Se guarda al momento, con las reglas del convenio, y se le avisa.</p>
<?php endif ?>
<?php if ($cortos): ?><p class="aviso error">Hay <?= count($cortos) ?> días que no cumplen el ratio (en rojo): falta o sobra gente en algún turno. Pasa el ratón por el número para ver el detalle. Revísalos en <a href="?p=planificacion">Planificación</a> o busca sustituto.</p><?php endif ?>
<?php $usados = []; foreach ($cals as $cal) foreach ($cal as $c) if (!$c['ausencia'] && !empty($c['horario_id']) && $c['turno']) $usados[$c['horario_id']] = true;
$con_letra = $jefe ? array_filter($hors, fn($h) => $h['abreviatura'] !== '' && isset($usados[$h['id']])) : []; ?>
<?php if ($con_letra): ?><p class="nota leyenda-turnos">Turnos: <?php foreach ($con_letra as $h): ?><span class="letra-turno" style="background:<?= e($h['color'] ?: '#6b7280') ?>"><?= e($h['abreviatura']) ?></span> <?= e($h['nombre']) ?> <?php endforeach ?></p><?php endif ?>
<p class="nota">D = descanso. F = festivo. Números = horas de turnos sin letra. ? = ausencia pendiente. Pasa el ratón (o mantén pulsado) para ver el detalle.</p>
<?php if ($jefe): ?>
<p class="nota">Leyenda: <?php foreach (tipos_ausencia(db()) as $t): ?><span class="etiqueta" style="background:<?= e($t['color']) ?>"><?= e($t['nombre']) ?></span> <?php endforeach ?></p>
<?php endif;
pie();
