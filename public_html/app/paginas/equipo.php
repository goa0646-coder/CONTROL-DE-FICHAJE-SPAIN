<?php
$yo = requiere('responsable', 'admin', 'inspeccion');
if (es_post() && $yo['rol'] !== 'inspeccion') {
    try {
        $aprobar = ($_POST['decision'] ?? '') === 'aprobar';
        $id = (int)($_POST['id'] ?? 0);
        $res = $aprobar ? 'aprobada' : 'rechazada';
        if (($_POST['que'] ?? '') === 'ausencia') {
            $a = resolver_ausencia(db(), $id, $yo, $aprobar, $_POST['comentario'] ?? '');
            auditar(db(), (int)$yo['id'], "ausencia_$res", "ausencia=$id");
            notificar(db(), (int)$a['usuario_id'], "ausencia_res:$id", "Tu solicitud de {$a['tipo']} del " . date('d/m', strtotime($a['desde'])) . " ha sido $res.", 'ausencias');
        } else {
            resolver_correccion(db(), $id, $yo, $aprobar, $_POST['comentario'] ?? '');
            auditar(db(), (int)$yo['id'], "correccion_$res", "correccion=$id");
            $c = consulta(db(), 'SELECT usuario_id FROM correcciones WHERE id = ?', [$id])->fetchColumn();
            notificar(db(), (int)$c, "correccion_res:$id", "Tu corrección nº $id ha sido $res.", 'correcciones');
        }
        aviso($aprobar ? 'Aprobada.' : 'Rechazada.');
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('equipo');
}
$pendientes = $yo['rol'] === 'inspeccion' ? [] : correcciones_pendientes(db(), $yo);
$ausencias = $yo['rol'] === 'inspeccion' ? [] : ausencias_pendientes(db(), $yo);
$hoy = local(ahora(), 'Y-m-d');
cabecera($yo['rol'] === 'responsable' ? 'Mi equipo' : 'Personas', $yo);
?>
<?php if ($ausencias): ?>
<h2>Ausencias pendientes</h2>
<?php foreach ($ausencias as $a): $sol = solapes(db(), $a); ?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($a['id']) ?>"><input type="hidden" name="que" value="ausencia">
  <p><strong><?= e(nombre($a)) ?></strong> · <span class="etiqueta" style="background:<?= e($a['color']) ?>"><?= e($a['tipo']) ?></span>
    <?= date('d/m/Y', strtotime($a['desde'])) ?><?= $a['hasta'] !== $a['desde'] ? ' – ' . date('d/m/Y', strtotime($a['hasta'])) : '' ?>
    <?= $a['hora_desde'] ? substr($a['hora_desde'], 0, 5) . '–' . substr($a['hora_hasta'], 0, 5) : '' ?>
    <?php if (!$a['por_horas']): $t = tipos_ausencia(db(), false)[$a['tipo_id']] ?? []; ?>(<?= dias_computables(db(), usuario(db(), (int)$a['usuario_id']), $t, $a['desde'], $a['hasta']) ?> <?= ($t['computo'] ?? '') === 'naturales' ? 'naturales' : 'laborables' ?>)<?php endif ?></p>
  <?php if ($a['motivo'] !== ''): ?><p>Comentario: <?= e($a['motivo']) ?></p><?php endif ?>
  <?php if ($a['justificante']): ?><p><a href="?p=archivo&a=<?= e($a['id']) ?>">Ver justificante</a></p><?php elseif ($a['pide_justificante']): ?><p class="nota">Sin justificante todavía.</p><?php endif ?>
  <?php if ($pre = aviso_preaviso(db(), $a)): ?><p class="nota">⚠ <?= e($pre) ?></p><?php endif ?>
  <?php if ($sol): ?><p class="error">Coincide con: <?= e(implode('; ', array_map(fn($s) => nombre($s) . ' (' . $s['estado'] . ' ' . date('d/m', strtotime($s['desde'])) . '–' . date('d/m', strtotime($s['hasta'])) . ')', $sol))) ?></p><?php endif ?>
  <label>Comentario <input name="comentario" maxlength="500"></label>
  <button name="decision" value="aprobar">Aprobar</button>
  <button name="decision" value="rechazar" class="secundario">Rechazar</button>
</form>
<?php endforeach; endif ?>

<?php if ($pendientes): ?>
<h2>Correcciones pendientes</h2>
<?php foreach ($pendientes as $c): ?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>">
  <p><strong><?= e(nombre($c)) ?></strong> · <?= ACCIONES[$c['accion']] ?> · pedida <?= local($c['pedida_utc']) ?></p>
  <?php if ($c['f_momento']): ?><p>Original: <?= TIPOS[$c['f_tipo']] ?> <?= local($c['f_momento']) ?></p><?php endif ?>
  <?php if ($c['momento_propuesto_utc']): ?><p>Nuevo: <?= TIPOS[$c['tipo_propuesto']] ?> <?= local($c['momento_propuesto_utc']) ?></p><?php endif ?>
  <p>Motivo: <?= e($c['motivo']) ?></p>
  <label>Comentario <input name="comentario" maxlength="500"></label>
  <button name="decision" value="aprobar">Aprobar</button>
  <button name="decision" value="rechazar" class="secundario">Rechazar</button>
</form>
<?php endforeach; endif ?>

<h2>Ahora mismo</h2>
<table>
  <tr><th>Persona</th><th>Estado</th><th>Último fichaje</th><th></th></tr>
  <?php foreach (equipo_de(db(), $yo) as $p):
      $ultimo = db()->prepare('SELECT momento_utc, tipo FROM fichajes f WHERE usuario_id = ? AND ' . NO_ANULADO . ' ORDER BY momento_utc DESC, id DESC LIMIT 1');
      $ultimo->execute([$p['id']]);
      $f = $ultimo->fetch();
      $estado = estado_actual(db(), (int)$p['id']);
      $aus = $p['activo'] ? (ausencias_por_dia(db(), (int)$p['id'], $hoy, $hoy)[$hoy] ?? null) : null; ?>
  <tr class="<?= $p['activo'] ? '' : 'anulado' ?>">
    <td><?= e(nombre($p)) ?><?= $p['activo'] ? '' : ' (baja)' ?></td>
    <td><span class="estado <?= $estado ?>"><?= ['fuera' => 'Fuera', 'dentro' => 'Trabajando', 'pausa' => 'En pausa'][$estado] ?></span>
      <?= $aus ? '<span class="etiqueta" style="background:' . e($aus['color']) . '">' . e($aus['tipo']) . '</span>' : '' ?></td>
    <td><?= $f ? TIPOS[$f['tipo']] . ' ' . local($f['momento_utc']) : '' ?></td>
    <td><a href="?p=historial&u=<?= e($p['id']) ?>">Registro</a></td>
  </tr>
  <?php endforeach ?>
</table>
<?php pie();
