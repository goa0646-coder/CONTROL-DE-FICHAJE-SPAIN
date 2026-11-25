<?php
// Cambios de turno entre compañeros: pedir, aceptar o rechazar los que me proponen y ver cómo van.
$u = requiere('empleado', 'responsable', 'admin');
if (es_post()) {
    try {
        if ($id = (int)($_POST['responder'] ?? 0)) {
            $acepta = ($_POST['decision'] ?? '') === 'aceptar';
            responder_cambio(db(), $u, $id, $acepta);
            aviso($acepta ? 'Aceptado. Ahora falta que lo apruebe la empresa.' : 'Propuesta rechazada.');
        } elseif ($id = (int)($_POST['cancelar'] ?? 0)) {
            cancelar_cambio(db(), $u, $id);
            aviso('Cambio cancelado.');
        } else {
            pedir_cambio(db(), $u, (int)($_POST['companero'] ?? 0), $_POST['fecha'] ?? '', $_POST['devuelve'] ?? '', $_POST['motivo'] ?? '');
            aviso('Propuesta enviada. Cuando tu compañero la acepte, la aprobará la empresa.');
        }
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('cambios');
}
$comp = companeros_cambio(db(), $u);
$lista = cambios_de(db(), (int)$u['id']);
$hoy = local(ahora(), 'Y-m-d');
$texto_turno = function (array $p, string $f) {
    try { return turno_del_dia(db(), $p, $f)[1]; } catch (DomainException) { return 'Ausente'; }
};
cabecera('Cambios de turno', $u);
?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Proponer un cambio</h2>
  <p class="nota">Elige el compañero y el día que quieres cambiar: tú haces su turno y él el tuyo. Si te devuelve el favor otro día, ponlo en «Día que me devuelve». Primero lo acepta tu compañero y después lo aprueba la empresa. Se comprueban los descansos y las horas de los dos.</p>
  <?php if (!$comp): ?><p class="aviso error"><?= icono('alerta') ?><span>No hay compañeros de tu mismo puesto con los que cambiar. Pide a la empresa que revise tu puesto en tu ficha.</span></p><?php else: ?>
  <div class="filtros">
    <label>Compañero <select name="companero" required><option value="">Elige…</option><?php foreach ($comp as $c): ?><option value="<?= e($c['id']) ?>"><?= e(nombre($c)) ?></option><?php endforeach ?></select></label>
    <label>Día del cambio <input type="date" name="fecha" min="<?= $hoy ?>" required></label>
    <label>Día que me devuelve (opcional) <input type="date" name="devuelve" min="<?= $hoy ?>"></label>
  </div>
  <label>Motivo <input name="motivo" maxlength="500" placeholder="Opcional"></label>
  <button>Enviar propuesta</button>
  <?php endif ?>
</form>

<h2>Mis cambios</h2>
<?php if (!$lista): ?><p class="nota">Todavía no hay cambios.</p><?php else: ?>
<table>
  <tr><th>Con</th><th>Días</th><th>Qué se cambia</th><th>Estado</th><th></th></tr>
  <?php foreach ($lista as $c): $yo_pido = $c['solicitante_id'] == $u['id']; $otro = usuario(db(), (int)($yo_pido ? $c['companero_id'] : $c['solicitante_id'])); ?>
  <tr>
    <td><?= e(nombre($otro)) ?><br><small><?= $yo_pido ? 'Lo pides tú' : 'Te lo propone' ?></small></td>
    <td><?= implode('<br>', array_map(fn($f) => date('d/m/Y', strtotime($f)), fechas_cambio($c))) ?></td>
    <td><?php foreach (fechas_cambio($c) as $f): ?><small><?= date('d/m', strtotime($f)) ?>: tú <?= e($texto_turno($u, $f)) ?> · <?= e($otro['nombre']) ?> <?= e($texto_turno($otro, $f)) ?></small><br><?php endforeach ?><?= $c['motivo'] !== '' ? '<small>Motivo: ' . e($c['motivo']) . '</small>' : '' ?></td>
    <td><span class="pildora <?= ['aprobado' => 'ok', 'rechazado' => 'mal', 'cancelado' => 'mal'][$c['estado']] ?? 'pendiente' ?>"><?= ESTADOS_CAMBIO[$c['estado']] ?></span><?= $c['comentario'] !== '' ? '<br><small>' . e($c['comentario']) . '</small>' : '' ?></td>
    <td>
      <?php if (!$yo_pido && $c['estado'] === 'companero'): ?>
      <form method="post" class="filtros"><?= csrf_campo() ?><input type="hidden" name="responder" value="<?= e($c['id']) ?>">
        <button name="decision" value="aceptar" class="pequeno">Aceptar</button><button name="decision" value="rechazar" class="secundario pequeno">Rechazar</button></form>
      <?php elseif ($yo_pido && in_array($c['estado'], ['companero', 'aprobacion'], true)): ?>
      <form method="post"><?= csrf_campo() ?><button name="cancelar" value="<?= e($c['id']) ?>" class="secundario pequeno">Cancelar</button></form>
      <?php endif ?>
    </td>
  </tr>
  <?php endforeach ?>
</table>
<?php endif;
pie();
