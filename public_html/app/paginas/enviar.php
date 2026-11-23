<?php
// El trabajador manda a la empresa justificantes, bajas, documentos o consultas. Avisa a administración y a su responsable (app + email).
$yo = requiere('empleado', 'responsable', 'admin');
if (es_post()) {
    try {
        $f = $_FILES['archivo'] ?? null;
        $archivo = guardar_archivo($f);
        $tipo = trim($_POST['tipo'] ?? '');
        $asunto = trim($_POST['asunto'] ?? '');
        enviar_a_empresa(db(), $yo, $asunto !== '' ? ($tipo && $tipo !== 'Otro' ? "$tipo: $asunto" : $asunto) : $tipo, $_POST['texto'] ?? '', $archivo, $archivo ? basename($f['name']) : null);
        aviso('Enviado. Administración ha recibido un aviso y un email.');
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('enviar');
}
$mios = consulta(db(), 'SELECT * FROM envios WHERE usuario_id = ? ORDER BY id DESC LIMIT 100', [$yo['id']])->fetchAll();
cabecera('Enviar a la empresa', $yo);
?>
<form method="post" enctype="multipart/form-data" class="tarjeta">
  <?= csrf_campo() ?>
  <p class="nota">Manda a administración un justificante, el parte de baja, un documento o una consulta. Les llega al momento por INDA y por email.</p>
  <label>Qué envías <select name="tipo"><?= opciones(array_combine($t = ['Justificante', 'Parte de baja / alta', 'Documentación personal', 'Consulta', 'Otro'], $t), '') ?></select></label>
  <label>Asunto <input name="asunto" maxlength="120" placeholder="Ej.: visita médica del día 12"></label>
  <label>Mensaje <textarea name="texto" rows="4"></textarea></label>
  <label>Archivo o foto (opcional) <input type="file" name="archivo" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.odt,.txt"></label>
  <button>Enviar</button>
</form>
<?php if ($mios): ?>
<h2>Lo que has enviado</h2>
<table>
  <tr><th>Fecha</th><th>Asunto</th><th>Archivo</th></tr>
  <?php foreach ($mios as $m): ?>
  <tr><td><?= local($m['creado_utc']) ?></td><td><?= e($m['asunto']) ?><?= $m['texto'] !== '' ? '<br><small class="nota">' . e(mb_strimwidth($m['texto'], 0, 120, '…')) . '</small>' : '' ?></td>
    <td><?= $m['archivo'] ? '<a href="?p=archivo&e=' . e($m['id']) . '">' . e($m['nombre_original']) . '</a>' : '—' ?></td></tr>
  <?php endforeach ?>
</table>
<?php endif;
pie();
