<?php
$u = requiere(...ROLES);
// El service worker pide aquí el último aviso para mostrarlo como notificación.
if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    $a = consulta(db(), 'SELECT texto, enlace FROM avisos WHERE usuario_id = ? ORDER BY id DESC LIMIT 1', [$u['id']])->fetch();
    exit(json_encode($a ? ['texto' => $a['texto'], 'url' => '?p=' . ($a['enlace'] ?: 'avisos')] : null));
}
$lista = consulta(db(), 'SELECT * FROM avisos WHERE usuario_id = ? ORDER BY id DESC LIMIT 100', [$u['id']])->fetchAll();
consulta(db(), 'UPDATE avisos SET leido_utc = ? WHERE usuario_id = ? AND leido_utc IS NULL', [ahora(), $u['id']]);
cabecera('Avisos', $u);
?>
<?php if (!$lista): ?><p class="nota">No tienes avisos.</p><?php endif ?>
<?php foreach ($lista as $a): ?>
<p class="tarjeta<?= $a['leido_utc'] ? '' : ' nuevo' ?>">
  <small class="nota"><?= local($a['creado_utc']) ?></small><br>
  <?= $a['enlace'] !== '' ? '<a href="?p=' . e($a['enlace']) . '">' . e($a['texto']) . '</a>' : e($a['texto']) ?>
</p>
<?php endforeach;
pie();
