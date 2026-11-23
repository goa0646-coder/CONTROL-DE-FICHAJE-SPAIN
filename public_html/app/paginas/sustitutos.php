<?php
// Cubrir un hueco: quién puede y cómo (horas extra voluntarias o cambio de turno), siempre dentro del convenio.
$yo = requiere('responsable', 'admin');
if (!puede($yo, 'planificar')) {
    http_response_code(403);
    exit('No tienes permiso para planificar.');
}
$f = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['f'] ?? '') ? $_GET['f'] : redirigir('planificacion');
$hid = (int)($_GET['h'] ?? 0);
$pid = (int)($_GET['pu'] ?? 0) ?: null;
$hs = horarios(db());
if (!isset($hs[$hid])) redirigir('planificacion');
$personas = array_values(array_filter(equipo_de(db(), $yo), fn($p) => $p['activo'] && $p['rol'] !== 'inspeccion'));
$volver = ['f' => $f, 'h' => $hid, 'pu' => $pid];

if (es_post()) {
    try {
        $u = usuario(db(), (int)($_POST['uid'] ?? 0));
        if (!$u || !in_array($u['id'], array_column($personas, 'id'))) throw new DomainException('Persona no válida.');
        if (($_POST['que'] ?? '') === 'mover') {
            $nota = mover_turno(db(), $yo, $u, $f, $hid, $pid);
            auditar(db(), (int)$yo['id'], 'turno_movido', "usuario={$u['id']} fecha=$f horario=$hid");
            aviso(nombre($u) . ' pasa a ' . $hs[$hid]['nombre'] . '. ' . ($nota ?? ''), $nota ? 'error' : 'ok');
        } else {
            $id = ofrecer_turno(db(), $yo, $u, $f, $hid, $pid, isset($_POST['fuerza_mayor']), trim($_POST['motivo'] ?? ''));
            auditar(db(), (int)$yo['id'], 'turno_ofrecido', "oferta=$id usuario={$u['id']}");
            aviso('Ofrecido a ' . nombre($u) . '. Le llega el aviso; cuando acepte, el hueco quedará cubierto.');
        }
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('sustitutos', $volver);
}

$falta = array_sum(array_column(array_filter(huecos(db(), $personas, $f, $f), fn($x) => $x[1] == $hid && $x[2] == $pid), 3));
$cand = candidatos(db(), $personas, $f, $hid, $pid);
$ofertas = consulta(db(), 'SELECT o.*, u.nombre, u.apellidos FROM ofertas_turno o JOIN usuarios u ON u.id = o.usuario_id WHERE o.fecha = ? AND o.horario_id = ? AND o.puesto_id <=> ? ORDER BY o.id DESC', [$f, $hid, $pid])->fetchAll();
$r = reglas(db());
cabecera('Cubrir un turno', $yo);
?>
<p class="tarjeta"><strong><?= e($hs[$hid]['nombre']) ?></strong><?= $pid ? ' · ' . e(puestos(db())[$pid] ?? '') : '' ?> · <?= DIAS[(int)date('N', strtotime($f))] ?> <?= date('d/m/Y', strtotime($f)) ?>
  · <?= $falta ? "<span class=\"error\">faltan $falta</span>" : '<span class="estado dentro">cubierto</span>' ?></p>

<h2>Quién puede cubrirlo</h2>
<table>
  <tr><th>Persona</th><th>Cómo</th><th>Horas extra <?= substr($f, 0, 4) ?></th><th>Convenio</th><th></th></tr>
  <?php foreach ($cand as $c): $u = $c['persona']; ?>
  <tr>
    <td><?= e(nombre($u)) ?><br><small class="nota">alta <?= local($u['alta_utc'], 'm/Y') ?></small></td>
    <td><?= $c['tipo'] === 'extra' ? 'Su día libre → horas extra voluntarias' : 'Cambiar de ' . e($hs[$c['actual']]['nombre'] ?? '') . ' (le sobra ese día)' ?></td>
    <td><?= horas($c['extra']) ?> de <?= e($r['horas_extra_max']) ?></td>
    <td class="<?= $c['mal'] ? 'error' : '' ?>"><?= $c['mal'] ? '⛔ ' . e($c['mal'][0][2]) : '✔ Cumple' ?></td>
    <td>
      <form method="post" class="filtros"><?= csrf_campo() ?><input type="hidden" name="uid" value="<?= e($u['id']) ?>">
        <?php if ($c['tipo'] === 'extra'): ?>
        <label><input name="motivo" placeholder="Motivo (baja de…)" size="14"></label>
        <label title="Accidente, avería o emergencia: no cuenta en el tope anual"><input type="checkbox" name="fuerza_mayor"> Fuerza mayor</label>
        <button name="que" value="ofrecer"<?= $c['mal'] ? ' class="secundario"' : '' ?>>Ofrecer</button>
        <?php elseif (!$c['mal']): ?><button name="que" value="mover">Mover</button><?php endif ?>
      </form>
    </td>
  </tr>
  <?php endforeach ?>
</table>
<?php if (!$cand): ?><p class="aviso error">Nadie con ese puesto puede cubrirlo sin incumplir el convenio. Opciones: contratar a alguien, formar a más personas en ese puesto o reducir el servicio ese día.</p><?php endif ?>
<p class="nota">Las horas extra son voluntarias: la persona las acepta desde su móvil con un código que le llega al email. Con «Fuerza mayor» (art. 35.3 ET) no cuentan en el tope anual, pero se compensan igual. Los ⛔ no se pueden saltar ni con la firma del trabajador.</p>

<?php if ($ofertas): ?>
<h2>Ofertas de este turno</h2>
<table>
  <?php foreach ($ofertas as $o): ?>
  <tr><td><?= e(trim($o['nombre'] . ' ' . $o['apellidos'])) ?></td><td><?= e($o['estado']) ?><?= $o['fuerza_mayor'] ? ' · fuerza mayor' : '' ?></td>
    <td><?= $o['respondida_utc'] ? local($o['respondida_utc']) . ' · IP ' . e($o['ip']) . ' · ' . e($o['metodo']) : 'enviada ' . local($o['creado_utc']) ?></td></tr>
  <?php endforeach ?>
</table>
<?php endif ?>
<p><a href="?p=planificacion&semana=<?= date('Y-m-d', strtotime('monday this week', strtotime($f))) ?>">← Volver a planificación</a></p>
<?php pie();
