<?php
// Inicio de administración y responsables: lo que pasa ahora y lo que requiere atención.
$yo = requiere('responsable', 'admin');
$hoy = local(ahora(), 'Y-m-d');
$hora = local(ahora(), 'H:i:s');
$hors = horarios(db());
$gente = array_values(array_filter(equipo_de(db(), $yo), fn($p) => $p['activo'] && $p['rol'] !== 'inspeccion'));
$dentro = $sin_fichar = $ausentes = [];
$con_turno = 0;
foreach ($gente as $p) {
    $c = calendario_teorico(db(), $p, $hoy, $hoy)[$hoy];
    $est = estado_actual(db(), (int)$p['id']);
    if ($c['ausencia']) $ausentes[] = [$p, $c];
    if ($c['turno'] > 0 && !$c['ausencia']) $con_turno++;
    if ($est !== 'fuera') {
        $desde = consulta(db(), "SELECT MAX(momento_utc) FROM fichajes f WHERE usuario_id = ? AND tipo = 'entrada' AND " . NO_ANULADO, [$p['id']])->fetchColumn();
        $dentro[] = [$p, $c, $est, $desde];
    } elseif ($c['turno'] > 0 && !$c['ausencia'] && !$c['flexible'] && $c['entrada'] && $c['entrada'] < $c['salida'] && $hora > date('H:i:s', strtotime("2000-01-01 {$c['entrada']}") + 60 * $c['margen']) && $hora < $c['salida']
        && !consulta(db(), "SELECT 1 FROM fichajes f WHERE usuario_id = ? AND tipo = 'entrada' AND momento_utc >= ? AND " . NO_ANULADO, [$p['id'], a_utc("$hoy 00:00:00")])->fetchColumn()) {
        $sin_fichar[] = [$p, $c];
    }
}
$pend_aus = ausencias_pendientes(db(), $yo);
$pend_cor = correcciones_pendientes(db(), $yo);
$recibidos = envios_recibidos(db(), $yo, true);
// Cobertura de los próximos 7 días: personas con turno frente al mínimo que piden los ratios.
$hasta = date('Y-m-d', strtotime("$hoy +6 days"));
$todos = db()->query("SELECT * FROM usuarios WHERE activo = 1 AND rol <> 'inspeccion'")->fetchAll();
$faltan = [];
$sobran = [];
foreach (desvios(db(), $todos, $hoy, $hasta) as [$f, , , $n]) if ($n > 0) $faltan[$f] = ($faltan[$f] ?? 0) + $n; else $sobran[$f] = ($sobran[$f] ?? 0) - $n;
$dias = [];
foreach (rango_fechas($hoy, $hasta) as $f) $dias[$f] = ['minimo' => array_sum(array_column(necesidades_dia(db(), $f), 2)), 'hay' => 0];
foreach ($todos as $p) foreach (calendario_teorico(db(), $p, $hoy, $hasta) as $f => $c) if ($c['turno'] > 0 && !$c['ausencia']) $dias[$f]['hay']++;
$max = max(1, ...array_values(array_map(fn($d) => max($d['minimo'], $d['hay']), $dias)));
$anuncio = anuncios_enviados(db(), $yo)[0] ?? null;
$hm = fn($utc) => $utc ? local($utc, 'H:i') : '';
cabecera('Inicio', $yo);
?>
<p class="saludo"><?= e((int)local(ahora(), 'G') < 14 ? 'Buenos días' : ((int)local(ahora(), 'G') < 21 ? 'Buenas tardes' : 'Buenas noches')) ?>, <?= e($yo['nombre']) ?> <span><?= e(ucfirst(fecha_larga())) ?></span></p>
<div class="cifras panel-cifras">
  <a href="?p=equipo"><span>Trabajando ahora</span><strong><?= count($dentro) ?> <small>de <?= $con_turno ?> con turno</small></strong></a>
  <a href="#sin-fichar" class="<?= $sin_fichar ? 'alerta' : '' ?>"><span>Sin fichar la entrada</span><strong><?= count($sin_fichar) ?></strong></a>
  <a href="?p=calendario"><span>Ausentes hoy</span><strong><?= count($ausentes) ?></strong></a>
  <a href="?p=equipo" class="<?= $pend_aus || $pend_cor ? 'aviso-suave' : '' ?>"><span>Pendiente de aprobar</span><strong><?= count($pend_aus) + count($pend_cor) ?></strong></a>
  <a href="?p=recibidos"><span>Recibidos sin revisar</span><strong><?= count($recibidos) ?></strong></a>
  <a href="?p=planificacion" class="<?= $faltan ? 'alerta' : '' ?>"><span>Turnos sin cubrir · 7 días</span><strong><?= array_sum($faltan) ?></strong></a>
</div>

<div class="panel-rejilla">
  <section class="tarjeta">
    <h2>Ahora mismo</h2>
    <?php if (!$dentro): ?><p class="nota">Nadie está trabajando en este momento.</p><?php endif ?>
    <ul class="lista-personas-panel">
      <?php foreach ($dentro as [$p, $c, $est, $desde]): [$l, $col] = celda_turno($c, $hors, true); ?>
      <li><?= avatar($p) ?><span><strong><?= e(nombre($p)) ?></strong><small>Desde las <?= $hm($desde) ?><?= $c['horario'] ? ' · ' . e($c['horario']) : '' ?></small></span>
        <span class="estado <?= $est ?>"><?= $est === 'pausa' ? 'En pausa' : 'Trabajando' ?></span></li>
      <?php endforeach ?>
    </ul>
  </section>

  <section class="tarjeta">
    <h2>Cobertura de la semana</h2>
    <div class="barras">
      <?php foreach ($dias as $f => $d): $corto = isset($faltan[$f]) || isset($sobran[$f]); ?>
      <a class="barra-dia<?= $corto ? ' corto' : '' ?>" href="?p=planificacion&semana=<?= date('Y-m-d', strtotime('monday this week', strtotime($f))) ?>" title="<?= $d['hay'] ?> con turno · ratio <?= $d['minimo'] ?><?= isset($faltan[$f]) ? ' · faltan ' . $faltan[$f] : '' ?><?= isset($sobran[$f]) ? ' · sobran ' . $sobran[$f] : '' ?>">
        <span class="valor"><?= $d['hay'] ?></span>
        <span class="columna"><i style="height:<?= round(100 * $d['hay'] / $max) ?>%"></i><?php if ($d['minimo']): ?><b style="bottom:<?= round(100 * $d['minimo'] / $max) ?>%"></b><?php endif ?></span>
        <small><?= mb_substr(DIAS[(int)date('N', strtotime($f))], 0, 3) ?> <?= (int)substr($f, 8) ?></small>
      </a>
      <?php endforeach ?>
    </div>
    <p class="nota leyenda-barras"><span><i class="muestra"></i> Personas con turno</span><span><b class="muestra-linea"></b> Ratio</span><?= $faltan || $sobran ? '<span><i class="muestra roja"></i> No cumple el ratio</span>' : '' ?></p>
  </section>

  <?php $olv = olvidos_fichaje(db(), $gente); ?>
  <section class="tarjeta" id="olvidos">
    <h2>Se olvidaron de fichar hoy (<?= count($olv) ?>)</h2>
    <?php if (!$olv): ?><p class="nota">Nadie: todas las entradas y salidas están fichadas.</p><?php endif ?>
    <ul class="lista-personas-panel">
      <?php foreach ($olv as [$que, $p, $h]): ?>
      <li><?= avatar($p) ?><span><strong><?= e(nombre($p)) ?></strong><small><?= $que === 'salida' ? "No fichó la salida: se cerró sola a las $h" : "No ha fichado la entrada (turno a las $h)" ?></small></span>
        <a class="boton secundario" href="?p=historial&u=<?= e($p['id']) ?>">Ver registro</a></li>
      <?php endforeach ?>
    </ul>
  </section>
  <section class="tarjeta" id="sin-fichar">
    <h2>Sin fichar la entrada</h2>
    <?php if (!$sin_fichar): ?><p class="nota">Todo en orden: quien tenía que entrar ya ha fichado.</p><?php endif ?>
    <ul class="lista-personas-panel">
      <?php foreach ($sin_fichar as [$p, $c]): ?>
      <li><?= avatar($p) ?><span><strong><?= e(nombre($p)) ?></strong><small><?= e($c['horario']) ?> · entraba a las <?= substr($c['entrada'], 0, 5) ?></small></span>
        <a class="boton secundario" href="?p=historial&u=<?= e($p['id']) ?>">Ver registro</a></li>
      <?php endforeach ?>
    </ul>
  </section>

  <section class="tarjeta">
    <h2>Pendiente de aprobar</h2>
    <?php if (!$pend_aus && !$pend_cor): ?><p class="nota">No hay solicitudes pendientes.</p><?php endif ?>
    <ul class="lista-personas-panel">
      <?php foreach (array_slice($pend_aus, 0, 6) as $a): ?>
      <li><?= avatar(usuario(db(), (int)$a['usuario_id']) ?? ['id' => 0, 'nombre' => '?', 'apellidos' => '']) ?><span><strong><?= e(nombre($a)) ?></strong><small><span class="etiqueta" style="background:<?= e($a['color']) ?>"><?= e($a['tipo']) ?></span> <?= date('d/m', strtotime($a['desde'])) ?><?= $a['hasta'] !== $a['desde'] ? ' – ' . date('d/m', strtotime($a['hasta'])) : '' ?></small></span></li>
      <?php endforeach ?>
      <?php foreach (array_slice($pend_cor, 0, 4) as $c): ?>
      <li><?= icono('correcciones') ?><span><strong><?= e(nombre($c)) ?></strong><small>Corrección de fichaje</small></span></li>
      <?php endforeach ?>
    </ul>
    <?php if ($pend_aus || $pend_cor): ?><a class="boton" href="?p=equipo">Revisar y aprobar</a><?php endif ?>
  </section>

  <?php if ($ausentes): ?>
  <section class="tarjeta">
    <h2>Ausentes hoy</h2>
    <ul class="lista-personas-panel">
      <?php foreach ($ausentes as [$p, $c]): ?><li><?= avatar($p) ?><span><strong><?= e(nombre($p)) ?></strong><small><span class="etiqueta" style="background:<?= e($c['ausencia']['color']) ?>"><?= e($c['ausencia']['tipo']) ?></span></small></span></li><?php endforeach ?>
    </ul>
  </section>
  <?php endif ?>

  <?php if ($anuncio): $l = lecturas(db(), $anuncio); $tot = count($l['leidos']) + count($l['pendientes']); ?>
  <section class="tarjeta">
    <h2>Último anuncio</h2>
    <p><strong><?= e($anuncio['titulo']) ?></strong><br><small class="nota"><?= e(fecha_larga(local($anuncio['creado_utc'], 'Y-m-d'))) ?></small></p>
    <div class="barra"><i style="width:<?= $tot ? round(100 * count($l['leidos']) / $tot) : 0 ?>%"></i></div>
    <p class="nota">Leído por <?= count($l['leidos']) ?> de <?= $tot ?> · <a href="?p=comunicados&ver=enviados&id=<?= e($anuncio['id']) ?>">Ver acuse</a></p>
  </section>
  <?php endif ?>
</div>
<?php pie();
