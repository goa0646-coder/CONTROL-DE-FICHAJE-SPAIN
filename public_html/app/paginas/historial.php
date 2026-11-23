<?php
$yo = requiere(...ROLES);
$u = usuario(db(), (int)($_GET['u'] ?? $yo['id']));
if (!$u || !puede_ver($yo, $u)) {
    http_response_code(403);
    exit('No tienes permiso para ver este registro.');
}
$mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['mes'] ?? '') ? $_GET['mes'] : local(ahora(), 'Y-m');
$siguiente = (new DateTime("$mes-01"))->modify('+1 month')->format('Y-m');
$ultimo = (new DateTime("$siguiente-01"))->modify('-1 day')->format('Y-m-d');
$propio = $yo['id'] == $u['id'];

if (es_post()) {
    try {
        if (isset($_POST['firmar']) && $propio) {
            $h = firmar_mes(db(), $u, $mes, $_POST['clave'] ?? '');
            auditar(db(), (int)$u['id'], 'firma_mensual', "mes=$mes hash=$h");
            aviso('Registro del mes firmado.');
        } elseif (isset($_POST['ajuste']) && $yo['rol'] === 'admin') {
            $signo = ($_POST['signo'] ?? '+') === '-' ? -1 : 1;
            if (!preg_match('/^(\d{1,4}):([0-5]\d)$/', trim($_POST['horas'] ?? ''), $m) || trim($_POST['motivo'] ?? '') === '') {
                throw new DomainException('Indica horas (h:mm) y motivo.');
            }
            consulta(db(), 'INSERT INTO ajustes_saldo (usuario_id, fecha, minutos, motivo, creado_por, creado_utc) VALUES (?, ?, ?, ?, ?, ?)',
                [$u['id'], local(ahora(), 'Y-m-d'), $signo * ($m[1] * 60 + $m[2]), mb_substr(trim($_POST['motivo']), 0, 200), $yo['id'], ahora()]);
            auditar(db(), (int)$yo['id'], 'ajuste_bolsa', "usuario={$u['id']} minutos=" . $signo * ($m[1] * 60 + $m[2]));
            aviso('Ajuste añadido a la bolsa de horas.');
        }
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('historial', ['u' => $u['id'], 'mes' => $mes]);
}

// Un día de margen a cada lado para no cortar jornadas que cruzan el cambio de mes.
$todos = fichajes_de(db(), (int)$u['id'], a_utc("$mes-01 -1 day"), a_utc("$siguiente-01 +1 day"));
$fichajes = array_values(array_filter($todos, fn($f) => local($f['momento_utc'], 'Y-m') === $mes));
$r = resumen(db(), $u, "$mes-01", $ultimo);
$correcciones = array_filter(correcciones_de(db(), (int)$u['id']), fn($c) => local($c['pedida_utc'], 'Y-m') === $mes
    || local($c['momento_propuesto_utc'], 'Y-m') === $mes || local($c['f_momento'], 'Y-m') === $mes);

if (!$propio) auditar(db(), (int)$yo['id'], isset($_GET['csv']) ? 'descarga_registro' : 'ver_registro', "usuario={$u['id']} mes=$mes");

if (isset($_GET['csv'])) {
    if ($propio) auditar(db(), (int)$yo['id'], 'descarga_registro', "mes=$mes");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="registro-' . preg_replace('/\W/', '', $u['num_empleado'] ?: $u['id']) . "-$mes.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel lea los acentos
    fputcsv($out, ['Empleado', 'DNI', 'Fecha', 'Hora', 'Tipo', 'Motivo', 'Origen', 'Zona', 'Estado', 'Corrección nº'], ';');
    foreach ($fichajes as $f) {
        fputcsv($out, [nombre($u), $u['dni'], local($f['momento_utc'], 'd/m/Y'), local($f['momento_utc'], 'H:i:s'), TIPOS[$f['tipo']], $f['motivo'] ?? '',
            $f['origen'], $f['zona'] ?? '', $f['anulado'] ? 'anulado' : 'válido', $f['correccion_id'] ?? ''], ';');
    }
    exit;
}

$t = $r['total'];
$bolsa = $u['rol'] !== 'inspeccion' ? saldo_bolsa(db(), $u) : 0;
$ajustes = consulta(db(), 'SELECT a.*, c.nombre AS por FROM ajustes_saldo a JOIN usuarios c ON c.id = a.creado_por WHERE a.usuario_id = ? ORDER BY a.id DESC LIMIT 20', [$u['id']])->fetchAll();
$firma = firma_mes(db(), $u, $mes);
$vigente = $firma && $firma['hash'] === hash('sha256', contenido_mes(db(), $u, $mes));
$empresa = db()->query('SELECT * FROM empresa')->fetch() ?: ['nombre' => '', 'cif' => ''];
cabecera($propio ? 'Mi registro' : 'Registro de ' . nombre($u), $yo);
?>
<div class="solo-imprimir">
  <p><strong><?= e($empresa['nombre']) ?></strong> · CIF <?= e($empresa['cif']) ?></p>
  <p>Persona: <strong><?= e(nombre($u)) ?></strong> · DNI <?= e($u['dni']) ?> · Mes <?= e($mes) ?> · Registro de jornada (art. 34.9 ET)</p>
</div>
<form class="filtros no-imprimir">
  <input type="hidden" name="p" value="historial"><input type="hidden" name="u" value="<?= e($u['id']) ?>">
  <label>Mes <input type="month" name="mes" value="<?= e($mes) ?>"></label>
  <button>Ver</button>
  <a class="boton secundario" href="?<?= e(http_build_query(['p' => 'historial', 'u' => $u['id'], 'mes' => $mes, 'csv' => 1])) ?>">Excel (CSV)</a>
  <button type="button" data-imprimir class="secundario">Imprimir / PDF</button>
</form>

<div class="cifras">
  <div><span>Trabajado</span><strong><?= horas($t['trabajado']) ?></strong></div>
  <div><span>Teórico</span><strong><?= horas($t['teorico']) ?></strong></div>
  <div><span>Extra</span><strong><?= horas($t['extra']) ?></strong></div>
  <div><span>Nocturno</span><strong><?= horas($t['nocturno']) ?></strong></div>
  <div><span>Festivo/libre</span><strong><?= horas($t['festivo']) ?></strong></div>
  <?php if ($u['rol'] !== 'inspeccion'): ?><div><span>Bolsa de horas</span><strong class="<?= $bolsa < 0 ? 'error' : '' ?>"><?= horas_signo($bolsa) ?></strong></div><?php endif ?>
</div>

<h2>Días</h2>
<table>
  <tr><th>Día</th><th>Previsto</th><th>Entrada</th><th>Salida</th><th>Pausas</th><th>Trabajado</th><th>Dif.</th></tr>
  <?php foreach ($r['dias'] as $f => $d):
      if (!$d['jornadas'] && !$d['minutos'] && !$d['ausencia'] && !$d['festivo']) continue;
      $js = $d['jornadas'] ?: [null]; ?>
  <?php foreach ($js as $i => $j): ?>
  <tr>
    <td><?= $i ? '' : DIAS[(int)date('N', strtotime($f))] . ' ' . date('d/m', strtotime($f)) ?></td>
    <td><?= $i ? '' : ($d['ausencia'] ? '<span class="etiqueta" style="background:' . e($d['ausencia']['color']) . '">' . e($d['ausencia']['tipo']) . '</span> ' : '')
        . ($d['festivo'] ? e($d['festivo']) : ($d['minutos'] ? horas($d['minutos'] * 60) : '')) ?></td>
    <?php if ($j): ?>
    <td><?= local($j['entrada'], 'H:i') ?></td>
    <td><?= $j['salida'] ? local($j['salida'], 'H:i') . (local($j['salida'], 'Y-m-d') !== local($j['entrada'], 'Y-m-d') ? ' (+1)' : '') : '<span class="error">abierta</span>' ?></td>
    <td><?= horas($j['pausa']) ?></td><td><?= horas($j['trabajado']) ?></td>
    <?php else: ?><td></td><td></td><td></td><td></td><?php endif ?>
    <td><?= $i || !$d['diferencia'] ? '' : horas_signo($d['diferencia']) ?></td>
  </tr>
  <?php endforeach; endforeach ?>
  <tr><th colspan="5">Total</th><th><?= horas($t['trabajado']) ?></th><th><?= horas_signo($t['diferencia']) ?></th></tr>
</table>

<h2>Fichajes</h2>
<table>
  <tr><th>Fecha y hora</th><th>Tipo</th><th>Origen</th><th>Zona</th><th class="no-imprimir"></th></tr>
  <?php foreach ($fichajes as $f): ?>
  <tr class="<?= $f['anulado'] ? 'anulado' : '' ?>">
    <td><?= local($f['momento_utc'], 'd/m/Y H:i:s') ?></td><td><?= TIPOS[$f['tipo']] ?><?= $f['motivo'] ? ' · ' . e($f['motivo']) : '' ?></td>
    <td><?= $f['origen'] === 'automatico' ? '<span class="pildora pendiente">Automática</span>' : e($f['origen']) ?><?= $f['correccion_id'] ? ' nº' . e($f['correccion_id']) : '' ?></td>
    <td><?= e($f['zona'] ?? '') ?></td>
    <td class="no-imprimir"><?= $f['anulado'] ? 'anulado' : ($propio && $yo['rol'] !== 'inspeccion' ? '<a href="?p=correcciones&f=' . e($f['id']) . '">Corregir</a>' : '') ?></td>
  </tr>
  <?php endforeach ?>
</table>
<?php if ($propio && $yo['rol'] !== 'inspeccion'): ?><p class="no-imprimir"><a class="boton" href="?p=correcciones">¿Olvidaste fichar? Pide una corrección</a></p><?php endif ?>

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
<?php endif ?>

<h2>Firma del mes</h2>
<?php if ($firma): ?>
<p class="aviso <?= $vigente ? 'ok' : 'error' ?>">Firmado por la persona el <?= local($firma['firmado_utc']) ?> · huella <?= e(substr($firma['hash'], 0, 16)) ?>…
  <?= $vigente ? '' : '<br>El registro ha cambiado después de la firma (por una corrección). Hace falta firmarlo de nuevo.' ?></p>
<?php else: ?><p class="nota">Sin firmar.</p><?php endif ?>
<?php if ($propio && $yo['rol'] !== 'inspeccion' && $mes < local(ahora(), 'Y-m') && !$vigente): ?>
<form method="post" class="tarjeta no-imprimir">
  <?= csrf_campo() ?>
  <p>Confirmo que este es mi registro de jornada de <?= e($mes) ?>.</p>
  <label>Tu contraseña <input type="password" name="clave" required autocomplete="current-password"></label>
  <button name="firmar" value="1">Firmar el mes</button>
</form>
<?php endif ?>
<div class="solo-imprimir firmas"><p>Firma de la persona trabajadora</p><p>Firma de la empresa</p></div>

<?php if ($yo['rol'] === 'admin' && !$propio): ?>
<h2 class="no-imprimir">Bolsa de horas: ajustes</h2>
<form method="post" class="filtros no-imprimir">
  <?= csrf_campo() ?>
  <label><select name="signo"><option value="-">Restar</option><option value="+">Sumar</option></select></label>
  <label>Horas <input name="horas" placeholder="2:30" size="6" required></label>
  <label>Motivo <input name="motivo" placeholder="Horas extra pagadas en nómina" required></label>
  <button name="ajuste" value="1">Añadir</button>
</form>
<?php endif ?>
<?php if ($ajustes): ?>
<table class="no-imprimir">
  <tr><th>Fecha</th><th>Horas</th><th>Motivo</th><th>Por</th></tr>
  <?php foreach ($ajustes as $a): ?><tr><td><?= date('d/m/Y', strtotime($a['fecha'])) ?></td><td><?= horas_signo($a['minutos'] * 60) ?></td><td><?= e($a['motivo']) ?></td><td><?= e($a['por']) ?></td></tr><?php endforeach ?>
</table>
<?php endif;
pie();
