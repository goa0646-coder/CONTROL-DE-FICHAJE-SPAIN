<?php
$yo = requiere('responsable', 'admin', 'inspeccion');
if (es_post() && $yo['rol'] !== 'inspeccion') {
    try {
        $aprobar = ($_POST['decision'] ?? '') === 'aprobar';
        resolver_correccion(db(), (int)($_POST['id'] ?? 0), $yo, $aprobar, $_POST['comentario'] ?? '');
        auditar(db(), (int)$yo['id'], $aprobar ? 'correccion_aprobada' : 'correccion_rechazada', 'correccion=' . (int)$_POST['id']);
        aviso($aprobar ? 'Corrección aprobada.' : 'Corrección rechazada.');
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('equipo');
}
$pendientes = $yo['rol'] === 'inspeccion' ? [] : correcciones_pendientes(db(), $yo);
cabecera($yo['rol'] === 'responsable' ? 'Mi equipo' : 'Personas', $yo);
?>
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
      $estado = estado_actual(db(), (int)$p['id']); ?>
  <tr class="<?= $p['activo'] ? '' : 'anulado' ?>">
    <td><?= e(nombre($p)) ?><?= $p['activo'] ? '' : ' (baja)' ?></td>
    <td><span class="estado <?= $estado ?>"><?= ['fuera' => 'Fuera', 'dentro' => 'Trabajando', 'pausa' => 'En pausa'][$estado] ?></span></td>
    <td><?= $f ? TIPOS[$f['tipo']] . ' ' . local($f['momento_utc']) : '' ?></td>
    <td><a href="?p=historial&u=<?= e($p['id']) ?>">Registro</a></td>
  </tr>
  <?php endforeach ?>
</table>
<?php pie();
