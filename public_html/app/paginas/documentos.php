<?php
$yo = requiere('empleado', 'responsable', 'admin');
$gestor = puede($yo, 'documentos');
if (es_post()) {
    try {
        $que = $_POST['que'] ?? '';
        if ($que === 'firmar') {
            $h = firmar_documento(db(), $yo, (int)$_POST['id'], $_POST['clave'] ?? '');
            auditar(db(), (int)$yo['id'], 'documento_firmado', 'documento=' . (int)$_POST['id'] . " hash=$h");
            aviso('Documento firmado.');
        } elseif ($que === 'subir' && $gestor) {
            $titulo = trim($_POST['titulo'] ?? '') ?: throw new DomainException('Pon un título.');
            $para = $_POST['para'] ?? '';
            $uids = $para === 'todos' && $yo['rol'] === 'admin' ? [null] : array_map('intval', (array)($_POST['personas'] ?? []));
            if (!$uids) throw new DomainException('Elige a quién va.');
            $f = $_FILES['archivo'] ?? null;
            $archivo = guardar_archivo($f) ?? throw new DomainException('Elige el archivo.');
            foreach ($uids as $uid) {
                if ($uid && (!($p = usuario(db(), $uid)) || !puede_ver($yo, $p))) continue;
                consulta(db(), 'INSERT INTO documentos (usuario_id, titulo, archivo, nombre_original, requiere_firma, subido_por, subido_utc) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$uid, mb_substr($titulo, 0, 150), $archivo, mb_substr(basename($f['name']), 0, 200), isset($_POST['firma']) ? 1 : 0, $yo['id'], ahora()]);
                $id = (int)db()->lastInsertId();
                foreach ($uid ? [$uid] : destinatarios(db(), null) as $d) notificar(db(), (int)$d, "documento:$id", "Tienes un documento nuevo: $titulo" . (isset($_POST['firma']) ? ' (pendiente de firmar)' : '') . '.', 'documentos');
            }
            auditar(db(), (int)$yo['id'], 'documento_subido', $titulo);
            aviso('Documento enviado.');
        } elseif ($que === 'retirar' && $gestor) {
            consulta(db(), 'UPDATE documentos SET activo = 0 WHERE id = ?' . ($yo['rol'] === 'admin' ? '' : ' AND subido_por = ' . (int)$yo['id']), [(int)$_POST['id']]);
            auditar(db(), (int)$yo['id'], 'documento_retirado', 'documento=' . (int)$_POST['id']);
            aviso('Documento retirado.');
        }
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('documentos');
}
$mios = documentos_de(db(), (int)$yo['id']);
cabecera('Documentos', $yo);
?>
<h2>Mis documentos</h2>
<?php if (!$mios): ?><p class="nota">No tienes documentos.</p><?php endif ?>
<table>
  <?php foreach ($mios as $d): ?>
  <tr><td><a href="?p=archivo&d=<?= e($d['id']) ?>"><?= e($d['titulo']) ?></a><br><small class="nota"><?= local($d['subido_utc'], 'd/m/Y') ?><?= $d['usuario_id'] ? '' : ' · para toda la plantilla' ?></small></td>
    <td><?php if ($d['firmado_utc']): ?>Firmado el <?= local($d['firmado_utc']) ?>
      <?php elseif ($d['requiere_firma']): ?>
      <form method="post" class="filtros"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($d['id']) ?>">
        <label><input type="password" name="clave" placeholder="Tu contraseña" required autocomplete="current-password"></label>
        <button name="que" value="firmar">Leído y firmado</button></form>
      <?php endif ?></td></tr>
  <?php endforeach ?>
</table>

<?php if ($gestor):
    $gente = array_values(array_filter(equipo_de(db(), $yo), fn($p) => $p['activo']));
    $enviados = db()->query('SELECT d.*, u.nombre, u.apellidos, (SELECT COUNT(*) FROM documentos_firmas f WHERE f.documento_id = d.id) AS firmas
        FROM documentos d LEFT JOIN usuarios u ON u.id = d.usuario_id WHERE d.activo = 1' . ($yo['rol'] === 'admin' ? '' : ' AND d.subido_por = ' . (int)$yo['id']) . ' ORDER BY d.id DESC LIMIT 100')->fetchAll(); ?>
<form method="post" enctype="multipart/form-data" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Enviar documento</h2>
  <label>Título <input name="titulo" required placeholder="Nómina octubre 2026"></label>
  <label>Archivo <input type="file" name="archivo" required></label>
  <label><input type="radio" name="para" value="personas" checked> A estas personas (una copia para cada una):</label>
  <select name="personas[]" multiple size="6"><?php foreach ($gente as $p): ?><option value="<?= e($p['id']) ?>"><?= e(nombre($p)) ?></option><?php endforeach ?></select>
  <?php if ($yo['rol'] === 'admin'): ?><label><input type="radio" name="para" value="todos"> A toda la plantilla</label><?php endif ?>
  <label><input type="checkbox" name="firma"> Pedir firma (leído y conforme)</label>
  <button name="que" value="subir">Enviar</button>
</form>
<h2>Enviados</h2>
<table>
  <tr><th>Documento</th><th>Para</th><th>Firma</th><th></th></tr>
  <?php foreach ($enviados as $d): ?>
  <tr><td><a href="?p=archivo&d=<?= e($d['id']) ?>"><?= e($d['titulo']) ?></a> <small class="nota"><?= local($d['subido_utc'], 'd/m/Y') ?></small></td>
    <td><?= $d['usuario_id'] ? e(trim($d['nombre'] . ' ' . $d['apellidos'])) : 'Todos' ?></td>
    <td><?php if (!$d['requiere_firma']): ?>—<?php else:
        $fs = consulta(db(), 'SELECT f.*, u.nombre, u.apellidos FROM documentos_firmas f JOIN usuarios u ON u.id = f.usuario_id WHERE f.documento_id = ? ORDER BY f.firmado_utc', [$d['id']])->fetchAll();
        $total = $d['usuario_id'] ? 1 : count(destinatarios(db(), null)); ?>
      <details><summary><?= count($fs) ?> de <?= $total ?> firmado</summary>
        <?php foreach ($fs as $f): ?><small><?= e(trim($f['nombre'] . ' ' . $f['apellidos'])) ?> · <?= local($f['firmado_utc']) ?> · IP <?= e($f['ip']) ?></small><br><?php endforeach ?>
      </details><?php endif ?></td>
    <td><form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($d['id']) ?>"><button name="que" value="retirar" class="secundario">Retirar</button></form></td></tr>
  <?php endforeach ?>
</table>
<?php endif;
pie();
