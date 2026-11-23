<?php
$yo = requiere('empleado', 'responsable', 'admin');
$puede = puede($yo, 'comunicados');
if (es_post()) {
    if (isset($_POST['leido'])) {
        consulta(db(), 'INSERT IGNORE INTO comunicados_leidos VALUES (?, ?, ?)', [(int)$_POST['leido'], $yo['id'], ahora()]);
    } elseif ($puede) {
        $titulo = trim($_POST['titulo'] ?? '');
        $texto = trim($_POST['texto'] ?? '');
        if ($titulo === '' || $texto === '') {
            aviso('Pon título y texto.', 'error');
            redirigir('comunicados');
        }
        $centro = (int)($_POST['centro_id'] ?? 0) ?: null;
        consulta(db(), 'INSERT INTO comunicados (titulo, texto, centro_id, autor_id, creado_utc) VALUES (?, ?, ?, ?, ?)', [mb_substr($titulo, 0, 150), $texto, $centro, $yo['id'], ahora()]);
        $id = (int)db()->lastInsertId();
        foreach (destinatarios(db(), $centro) as $d) if ($d != $yo['id']) notificar(db(), (int)$d, "comunicado:$id", "Comunicado: $titulo", 'comunicados');
        auditar(db(), (int)$yo['id'], 'comunicado_publicado', "comunicado=$id");
        aviso('Publicado y avisado.');
    }
    redirigir('comunicados');
}
$lista = comunicados_para(db(), $yo);
cabecera('Comunicados', $yo);
?>
<?php if ($puede): $centros = db()->query('SELECT id, nombre FROM centros WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR); ?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Nuevo comunicado</h2>
  <label>Título <input name="titulo" required maxlength="150"></label>
  <label>Texto <textarea name="texto" rows="5" required></textarea></label>
  <label>Para <select name="centro_id"><?= opciones(['' => 'Toda la plantilla'] + $centros, '') ?></select></label>
  <button>Publicar</button>
</form>
<?php endif ?>
<?php if (!$lista): ?><p class="nota">No hay comunicados.</p><?php endif ?>
<?php foreach ($lista as $c): ?>
<article class="tarjeta<?= $c['leido_utc'] ? '' : ' nuevo' ?>" id="c<?= e($c['id']) ?>">
  <h3><?= e($c['titulo']) ?></h3>
  <p class="nota"><?= local($c['creado_utc']) ?> · <?= e($c['autor']) ?></p>
  <p><?= nl2br(e($c['texto'])) ?></p>
  <?php if (!$c['leido_utc']): ?><form method="post"><?= csrf_campo() ?><button name="leido" value="<?= e($c['id']) ?>" class="secundario">Marcar como leído</button></form><?php endif ?>
</article>
<?php endforeach;
if ($puede && $lista): ?>
<h2>Lecturas</h2>
<table>
  <?php foreach ($lista as $c):
      $n = consulta(db(), 'SELECT COUNT(*) FROM comunicados_leidos WHERE comunicado_id = ?', [$c['id']])->fetchColumn(); ?>
  <tr><td><?= e($c['titulo']) ?></td><td><?= e($n) ?> de <?= count(destinatarios(db(), $c['centro_id'] ? (int)$c['centro_id'] : null)) ?> lo han leído</td></tr>
  <?php endforeach ?>
</table>
<?php endif;
pie();
