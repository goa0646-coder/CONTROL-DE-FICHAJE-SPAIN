<?php
$u = requiere('empleado', 'responsable', 'admin');
if (es_post()) {
    try {
        $d = $_POST;
        $d['momento_utc'] = !empty($d['momento']) ? a_utc($d['momento']) : null;
        $id = pedir_correccion(db(), $u, $d, (int)$u['id']);
        auditar(db(), (int)$u['id'], 'correccion_pedida', "correccion=$id");
        aviso('Solicitud enviada. Tu responsable la revisará.');
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    } catch (Exception) {
        aviso('La fecha y hora no son válidas.', 'error');
    }
    redirigir('correcciones');
}

$recientes = array_reverse(array_filter(fichajes_de(db(), (int)$u['id'], gmdate('Y-m-d', strtotime('-62 days'))), fn($f) => !$f['anulado']));
$lista = [];
foreach ($recientes as $f) $lista[$f['id']] = local($f['momento_utc']) . ' · ' . TIPOS[$f['tipo']];
cabecera('Correcciones', $u);
?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Pedir una corrección</h2>
  <label>¿Qué pasó? <select name="accion" required><?= opciones(['' => 'Elige…'] + ACCIONES, isset($_GET['f']) ? 'cambiar' : '') ?></select></label>
  <label>Fichaje afectado (para anular o cambiar)
    <select name="fichaje_id"><?= opciones(['' => '—'] + $lista, $_GET['f'] ?? '') ?></select></label>
  <label>Tipo correcto (para añadir o cambiar) <select name="tipo"><?= opciones(['' => '—'] + TIPOS, '') ?></select></label>
  <label>Fecha y hora correctas <input type="datetime-local" name="momento" max="<?= local(ahora(), 'Y-m-d\TH:i') ?>"></label>
  <label>Motivo <textarea name="motivo" required maxlength="500"></textarea></label>
  <button>Enviar</button>
</form>

<h2>Mis solicitudes</h2>
<table>
  <tr><th>Pedida</th><th>Qué</th><th>Motivo</th><th>Estado</th></tr>
  <?php foreach (correcciones_de(db(), (int)$u['id']) as $c): ?>
  <tr>
    <td><?= local($c['pedida_utc']) ?></td>
    <td><?= ACCIONES[$c['accion']] ?><?= $c['momento_propuesto_utc'] ? '<br>' . TIPOS[$c['tipo_propuesto']] . ' ' . local($c['momento_propuesto_utc']) : '' ?></td>
    <td><?= e($c['motivo']) ?></td>
    <td><?= e($c['estado']) ?><?= $c['comentario'] !== '' ? '<br><small>' . e($c['comentario']) . '</small>' : '' ?></td>
  </tr>
  <?php endforeach ?>
</table>
<?php pie();
