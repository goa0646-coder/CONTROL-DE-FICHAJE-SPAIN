<?php
// Protocolo ante un requerimiento o visita de la Inspección de Trabajo: pasos, cuentas de Inspección y lo que han consultado.
$yo = requiere('admin');
if (es_post() && ($_POST['que'] ?? '') === 'cerrar') {
    $n = consulta(db(), "UPDATE usuarios SET activo = 0, baja_utc = ? WHERE rol = 'inspeccion' AND activo = 1", [ahora()])->rowCount();
    auditar(db(), (int)$yo['id'], 'inspeccion_cerrada', "cuentas=$n");
    aviso("Acceso de Inspección cerrado ($n cuentas). Todo lo que consultaron queda en la auditoría.");
    redirigir('protocolo');
}
$cuentas = db()->query("SELECT * FROM usuarios WHERE rol = 'inspeccion' ORDER BY activo DESC, id DESC")->fetchAll();
$ids = array_column($cuentas, 'id') ?: [0];
$vistos = consulta(db(), 'SELECT * FROM auditoria WHERE usuario_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY momento_utc DESC LIMIT 30')->fetchAll();
cabecera('Protocolo Inspección', $yo);
?>
<div class="tarjeta">
  <h2>Si llega la Inspección de Trabajo</h2>
  <ol>
    <li><strong>Apunta el requerimiento</strong>: fecha, inspector/a, qué piden (registro de jornada, horas extra, cuadrantes…), de qué periodo y el plazo. Si es una visita, atiéndela con calma: tienen derecho a ver el registro en el centro.</li>
    <li><strong>Crea una cuenta de Inspección</strong> en <a href="?p=usuarios&nuevo=1">Usuarios → Nueva persona</a> con rol «Inspección» y «Acceso hasta» la fecha del plazo (por defecto 30 días). Solo ve el registro de jornada y la auditoría: no ve fichas personales privadas, preferencias, encuestas ni valoraciones. Mándale el acceso desde su ficha.</li>
    <li><strong>Prepara lo que piden</strong> en <a href="?p=informes">Informes</a>: registro de jornada del periodo (PDF o Excel por persona o de toda la plantilla), horas extra y nocturnas. Los cuadrantes, en Planificación.</li>
    <li><strong>Comprueba la integridad</strong> en <a href="?p=auditoria">Auditoría</a>: la cadena de fichajes tiene que salir correcta. Las correcciones se ven con el fichaje original (no se borra nada).</li>
    <li><strong>Al terminar</strong>, cierra el acceso con el botón de abajo (o se cierra solo en la fecha «Acceso hasta»). Guarda el requerimiento y lo entregado.</li>
  </ol>
  <p class="nota">El registro se conserva 4 años y está a disposición de la persona trabajadora, de su representación legal y de la Inspección (art. 34.9 ET). Negarse o no tenerlo es infracción grave.</p>
</div>

<div class="tarjeta">
  <h2>Cuentas de Inspección (<?= count($cuentas) ?>)</h2>
  <?php if (!$cuentas): ?><p class="nota">No hay ninguna. Créala solo cuando haya un requerimiento.</p><?php endif ?>
  <table>
    <?php foreach ($cuentas as $c): ?>
    <tr><td><a href="?p=usuarios&u=<?= e($c['id']) ?>"><?= e(nombre($c)) ?></a></td><td><?= e($c['email']) ?></td>
      <td><?= $c['activo'] ? ($c['acceso_hasta'] ? 'Activa hasta el ' . date('d/m/Y', strtotime($c['acceso_hasta'])) : 'Activa') : 'Cerrada' ?></td>
      <td class="nota"><?= $c['ultimo_acceso_utc'] ? 'Último acceso ' . e(local($c['ultimo_acceso_utc'], 'd/m/Y H:i')) : 'No ha entrado' ?></td></tr>
    <?php endforeach ?>
  </table>
  <?php if (array_filter($cuentas, fn($c) => $c['activo'])): ?>
  <form method="post"><?= csrf_campo() ?><button name="que" value="cerrar" class="secundario" data-confirmar="¿Cerrar ya el acceso de Inspección?">Cerrar el acceso de Inspección</button></form>
  <?php endif ?>
</div>

<?php if ($vistos): ?>
<div class="tarjeta">
  <h2>Lo que ha hecho Inspección</h2>
  <table>
    <?php foreach ($vistos as $v): ?><tr><td><?= e(local($v['momento_utc'], 'd/m/Y H:i')) ?></td><td><?= e($v['accion']) ?></td><td class="nota"><?= e($v['detalle']) ?></td></tr><?php endforeach ?>
  </table>
</div>
<?php endif ?>
<?php pie();
