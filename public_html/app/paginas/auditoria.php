<?php
$yo = requiere('admin', 'inspeccion');
auditar(db(), (int)$yo['id'], 'ver_auditoria');

// Comprueba la cadena de hashes de cada persona.
$rotos = [];
$porUsuario = [];
foreach (db()->query('SELECT * FROM fichajes ORDER BY usuario_id, id') as $f) $porUsuario[$f['usuario_id']][] = $f;
foreach ($porUsuario as $uid => $filas) {
    if ($id = verificar_cadena($filas)) $rotos[] = "persona $uid, fichaje $id";
}
$eventos = db()->query('SELECT a.*, u.nombre, u.apellidos FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id ORDER BY a.id DESC LIMIT 300')->fetchAll();
cabecera('Auditoría', $yo);
?>
<p class="aviso <?= $rotos ? 'error' : 'ok' ?>">
  <?= $rotos ? 'ATENCIÓN: registros alterados fuera de la aplicación: ' . e(implode('; ', $rotos)) : 'Integridad comprobada: ningún fichaje ha sido alterado (' . array_sum(array_map('count', $porUsuario)) . ' fichajes).' ?>
</p>
<h2>Últimos 300 eventos</h2>
<table>
  <tr><th>Cuándo</th><th>Quién</th><th>Acción</th><th>Detalle</th><th>IP</th></tr>
  <?php foreach ($eventos as $a): ?>
  <tr><td><?= local($a['momento_utc'], 'd/m/Y H:i:s') ?></td><td><?= e(trim($a['nombre'] . ' ' . $a['apellidos'])) ?></td>
    <td><?= e($a['accion']) ?></td><td><?= e($a['detalle']) ?></td><td><?= e($a['ip']) ?></td></tr>
  <?php endforeach ?>
</table>
<?php pie();
