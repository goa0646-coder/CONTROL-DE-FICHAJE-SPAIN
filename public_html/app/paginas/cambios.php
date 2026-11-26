<?php
// Cambios de turno entre compañeros: pedir, aceptar o rechazar los que me proponen y ver cómo van.
$u = requiere('empleado', 'responsable', 'admin');
$mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['mes'] ?? '') ? $_GET['mes'] : local(ahora(), 'Y-m');
// Calendario de un compañero (solo sus turnos; sus ausencias salen como «No disponible», sin el motivo).
if (isset($_GET['cal'])) {
    $c = array_values(array_filter(companeros_cambio(db(), $u), fn($x) => $x['id'] == (int)$_GET['cal']))[0] ?? null;
    exit($c ? '<h3>Turnos de ' . e(nombre($c)) . '</h3>' . mes_trabajador(db(), $c, $mes, '', false, false) : '');
}
// Vista previa del cambio (mañana por tarde o noche, o un turno por un libre).
if (isset($_GET['previa'])) {
    try {
        $filas = previa_cambio(db(), $u, (int)($_GET['companero'] ?? 0), [(string)($_GET['fecha'] ?? ''), (string)($_GET['devuelve'] ?? '')]);
        $h = '';
        foreach ($filas as [$f, $mio, $suyo, $o]) {
            $h .= '<li><b>' . date('d/m', strtotime($f)) . '</b>: ' . ($mio === $suyo ? 'los dos tenéis ' . e($mio) . ' (nada que cambiar)'
                : 'tú ' . e($mio) . ' → <b>' . e($suyo) . '</b> · ' . e($o['nombre']) . ' ' . e($suyo) . ' → <b>' . e($mio) . '</b>') . '</li>';
        }
        exit($h ? '<p class="nota">Así quedaría:</p><ul>' . $h . '</ul>' : '');
    } catch (DomainException $e) {
        exit('<p class="nota error">' . e($e->getMessage()) . '</p>');
    }
}
if (es_post()) {
    try {
        if ($id = (int)($_POST['responder'] ?? 0)) {
            $acepta = ($_POST['decision'] ?? '') === 'aceptar';
            responder_cambio(db(), $u, $id, $acepta, isset($_POST['voluntario']));
            aviso($acepta ? 'Aceptado. Ahora falta que lo apruebe la empresa.' : 'Propuesta rechazada.');
        } elseif ($id = (int)($_POST['cancelar'] ?? 0)) {
            cancelar_cambio(db(), $u, $id);
            aviso('Cambio cancelado.');
        } else {
            $lim = limite_vision(db(), $u);
            if ($lim && max((string)($_POST['fecha'] ?? ''), (string)($_POST['devuelve'] ?? '')) > $lim) throw new DomainException('Solo puedes proponer cambios hasta el ' . date('d/m/Y', strtotime($lim)) . '.');
            pedir_cambio(db(), $u, (int)($_POST['companero'] ?? 0), $_POST['fecha'] ?? '', $_POST['devuelve'] ?? '', $_POST['motivo'] ?? '', isset($_POST['voluntario']));
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
<div class="tarjeta">
  <h2>Mi calendario</h2>
  <p class="nota">Toca un día para ponerlo en la propuesta.</p>
  <?= mes_trabajador(db(), $u, $mes, 'cambios', true) ?>
</div>
<form method="post" class="tarjeta" data-cambio data-mes="<?= e($mes) ?>">
  <?= csrf_campo() ?>
  <h2>Proponer un cambio</h2>
  <p class="filtros"><span class="nota">Al tocar un día del calendario se rellena:</span>
    <label><input type="radio" name="rellenar" value="fecha" checked> Día del cambio</label>
    <label><input type="radio" name="rellenar" value="devuelve"> Día que me devuelve</label></p>
  <p class="nota">Elige el compañero y el día: tú haces su turno y él el tuyo (una mañana por una tarde o una noche, o al revés; o tu turno por su libre). Si te devuelve el favor otro día, ponlo en «Día que me devuelve». Primero lo acepta tu compañero y después lo aprueba la empresa. Se comprueban los descansos y las horas de los dos.</p>
  <?php if (!$comp): ?><p class="aviso error"><?= icono('alerta') ?><span>No hay compañeros de tu mismo puesto con los que cambiar. Pide a la empresa que revise tu puesto en tu ficha.</span></p><?php else: ?>
  <div class="filtros">
    <label>Compañero <select name="companero" required><option value="">Elige…</option><?php foreach ($comp as $c): ?><option value="<?= e($c['id']) ?>"><?= e(nombre($c)) ?></option><?php endforeach ?></select></label>
    <?php $lim = limite_vision(db(), $u); ?>
    <label>Día del cambio <input type="date" name="fecha" min="<?= $hoy ?>"<?= $lim ? ' max="' . $lim . '"' : '' ?> required></label>
    <label>Día que me devuelve (opcional) <input type="date" name="devuelve" min="<?= $hoy ?>"<?= $lim ? ' max="' . $lim . '"' : '' ?>></label>
  </div>
  <div data-previa-cambio></div>
  <div data-cal-companero></div>
  <label>Motivo <input name="motivo" maxlength="500" placeholder="Opcional"></label>
  <label><input type="checkbox" name="voluntario"> Lo acepto por mi propia voluntad aunque incumpla el convenio (descansos, horas…)</label>
  <button>Enviar propuesta</button>
  <?php endif ?>
</form>

<?php $grupos = ['pendientes' => ['Pendientes', ['companero', 'aprobacion']], 'aprobados' => ['Aprobados', ['aprobado']], 'cerrados' => ['Rechazados y cancelados', ['rechazado', 'cancelado']]];
$g = isset($grupos[$_GET['ver'] ?? '']) ? $_GET['ver'] : 'pendientes';
$cuenta = fn($k) => count(array_filter($lista, fn($c) => in_array($c['estado'], $grupos[$k][1], true)));
$lista = array_values(array_filter($lista, fn($c) => in_array($c['estado'], $grupos[$g][1], true))); ?>
<h2>Mis cambios</h2>
<nav class="segmentos"><?php foreach ($grupos as $k => [$t]): ?><a href="?p=cambios&ver=<?= $k ?>&mes=<?= e($mes) ?>" class="<?= $g === $k ? 'activo' : '' ?>"><?= $t ?> (<?= $cuenta($k) ?>)</a><?php endforeach ?></nav>
<?php if (!$lista): ?><p class="nota">Todavía no hay cambios.</p><?php else: ?>
<table>
  <tr><th>Con</th><th>Días</th><th>Qué se cambia</th><th>Estado</th><th></th></tr>
  <?php foreach ($lista as $c): $yo_pido = $c['solicitante_id'] == $u['id']; $otro = usuario(db(), (int)($yo_pido ? $c['companero_id'] : $c['solicitante_id'])); ?>
  <tr>
    <td><?= e(nombre($otro)) ?><br><small><?= $yo_pido ? 'Lo pides tú' : 'Te lo propone' ?></small></td>
    <td><?= implode('<br>', array_map(fn($f) => date('d/m/Y', strtotime($f)), fechas_cambio($c))) ?></td>
    <td><?php foreach (fechas_cambio($c) as $f): ?><small><?= date('d/m', strtotime($f)) ?>: tú <?= e($texto_turno($u, $f)) ?> · <?= e($otro['nombre']) ?> <?= e($texto_turno($otro, $f)) ?></small><br><?php endforeach ?><?= $c['motivo'] !== '' ? '<small>Motivo: ' . e($c['motivo']) . '</small>' : '' ?><?= $c['incumple'] !== '' ? '<br><small class="error">Voluntario, incumple: ' . e($c['incumple']) . '</small>' : '' ?></td>
    <td><span class="pildora <?= ['aprobado' => 'ok', 'rechazado' => 'mal', 'cancelado' => 'mal'][$c['estado']] ?? 'pendiente' ?>"><?= ESTADOS_CAMBIO[$c['estado']] ?></span><?= $c['comentario'] !== '' ? '<br><small>' . e($c['comentario']) . '</small>' : '' ?></td>
    <td>
      <?php if (!$yo_pido && $c['estado'] === 'companero'): ?>
      <form method="post" class="filtros"><?= csrf_campo() ?><input type="hidden" name="responder" value="<?= e($c['id']) ?>">
        <?php if ($c['incumple'] !== ''): ?><small class="error">Incumple el convenio: <?= e($c['incumple']) ?>. Quien lo pide lo acepta por su voluntad.</small><?php endif ?>
        <label><small><input type="checkbox" name="voluntario"<?= $c['incumple'] !== '' ? ' required' : '' ?>> Lo acepto por mi propia voluntad aunque incumpla el convenio</small></label>
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
