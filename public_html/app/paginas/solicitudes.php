<?php
// Solicitudes: todo lo que piden los trabajadores en un solo sitio. Administración (o su responsable) tiene la última palabra.
// Las decisiones las guarda la página de Personas (misma lógica); aquí solo se listan y se vuelve aquí.
$yo = requiere('responsable', 'admin');
$s = solicitudes_pendientes(db(), $yo);
$boton = fn(string $que, int $id, bool $aprobar = true) => '<form method="post" class="filtros">' . csrf_campo() . '<input type="hidden" name="volver" value="solicitudes"><input type="hidden" name="que" value="' . $que . '"><input type="hidden" name="id" value="' . $id . '">'
    . '<input name="comentario" maxlength="500" placeholder="Comentario (opcional)">' . ($aprobar ? '<button name="decision" value="aprobar" class="pequeno">Aprobar</button>' : '') . '<button name="decision" value="rechazar" class="secundario pequeno">Rechazar</button></form>';
$recientes = consulta(db(), "SELECT * FROM cambios_turno WHERE estado IN ('aprobado','rechazado','cancelado') AND COALESCE(resuelto_utc, respondido_utc, pedido_utc) >= ? ORDER BY id DESC LIMIT 30", [gmdate('Y-m-d H:i:s', strtotime('-30 days'))])->fetchAll();
cabecera('Solicitudes', $yo);
?>
<div class="cifras">
  <div><span>Por decidir</span><strong><?= n_por_decidir($s) ?></strong></div>
  <div><span>Cambios de turno</span><strong><?= count($s['cambios']) ?></strong></div>
  <div><span>Ausencias</span><strong><?= count($s['ausencias']) ?></strong></div>
  <div><span>Correcciones</span><strong><?= count($s['correcciones']) ?></strong></div>
  <div><span>Justificantes sin subir</span><strong><?= count($s['justificantes']) ?></strong></div>
  <div><span>Envíos sin revisar</span><strong><?= count($s['envios']) ?></strong></div>
</div>
<p class="nota">Recibes un correo con cada solicitud y un recordatorio con lo pendiente (cada cuánto, en <a href="?p=admin">Administración → Correo</a>). Si un correo no sale, el motivo aparece en <a href="?p=diagnostico">Diagnóstico</a>.</p>

<h2>Cambios de turno</h2>
<?php if (!$s['cambios']): ?><p class="nota">Ninguno pendiente.</p><?php else: ?>
<table>
  <tr><th>Pide</th><th>Con</th><th>Días y turnos</th><th>Compañero</th><th>Decisión</th></tr>
  <?php foreach ($s['cambios'] as $c): $a = usuario(db(), (int)$c['solicitante_id']); $b = usuario(db(), (int)$c['companero_id']); ?>
  <tr><td><?= e(nombre($a)) ?><br><small><?= local($c['pedido_utc'], 'd/m H:i') ?></small></td><td><?= e(nombre($b)) ?></td>
    <td><?php foreach (fechas_cambio($c) as $f): try { $ta = turno_del_dia(db(), $a, $f)[1]; $tb = turno_del_dia(db(), $b, $f)[1]; } catch (DomainException $ex) { $ta = $tb = '—'; } ?>
      <small><b><?= date('d/m/Y', strtotime($f)) ?></b>: <?= e($a['nombre']) ?> <?= e($ta) ?> ⇄ <?= e($b['nombre']) ?> <?= e($tb) ?></small><br><?php endforeach ?>
      <?= $c['motivo'] !== '' ? '<small>Motivo: ' . e($c['motivo']) . '</small>' : '' ?></td>
    <td><?= $c['estado'] === 'aprobacion' ? '<span class="pildora ok">Aceptado ' . local($c['respondido_utc'], 'd/m H:i') . '</span>' : '<span class="pildora pendiente">Aún no ha respondido</span>' ?></td>
    <td><?= $boton('cambio', (int)$c['id'], $c['estado'] === 'aprobacion') ?></td></tr>
  <?php endforeach ?>
</table>
<?php endif ?>

<h2>Ausencias (vacaciones, bajas, permisos)</h2>
<?php if (!$s['ausencias']): ?><p class="nota">Ninguna pendiente.</p><?php else: ?>
<table>
  <tr><th>Persona</th><th>Tipo</th><th>Fechas</th><th>Comentario</th><th>Decisión</th></tr>
  <?php foreach ($s['ausencias'] as $a): ?>
  <tr><td><?= e(nombre($a)) ?><br><small><?= local($a['pedida_utc'], 'd/m H:i') ?></small></td><td><span class="etiqueta" style="background:<?= e($a['color']) ?>"><?= e($a['tipo']) ?></span></td>
    <td><?= date('d/m/Y', strtotime($a['desde'])) ?><?= $a['hasta'] !== $a['desde'] ? ' – ' . date('d/m/Y', strtotime($a['hasta'])) : '' ?><?= $a['hora_desde'] ? ' ' . substr($a['hora_desde'], 0, 5) . '–' . substr($a['hora_hasta'], 0, 5) : '' ?></td>
    <td><?= e($a['motivo']) ?><?= $a['justificante'] ? ' <a href="?p=archivo&a=' . e($a['id']) . '">Justificante</a>' : '' ?></td>
    <td><?= $boton('ausencia', (int)$a['id']) ?></td></tr>
  <?php endforeach ?>
</table>
<?php endif ?>

<h2>Correcciones de fichaje</h2>
<?php if (!$s['correcciones']): ?><p class="nota">Ninguna pendiente.</p><?php else: ?>
<table>
  <tr><th>Persona</th><th>Qué pide</th><th>Motivo</th><th>Decisión</th></tr>
  <?php foreach ($s['correcciones'] as $c): ?>
  <tr><td><?= e(nombre($c)) ?><br><small><?= local($c['pedida_utc'], 'd/m H:i') ?></small></td>
    <td><?= ACCIONES[$c['accion']] ?? e($c['accion']) ?><?= $c['momento_propuesto_utc'] ? ': ' . (TIPOS[$c['tipo_propuesto']] ?? '') . ' ' . local($c['momento_propuesto_utc'], 'd/m H:i') : '' ?><?= $c['f_momento'] ? '<br><small>Fichaje: ' . (TIPOS[$c['f_tipo']] ?? '') . ' ' . local($c['f_momento'], 'd/m H:i') . '</small>' : '' ?></td>
    <td><?= e($c['motivo']) ?></td><td><?= $boton('correccion', (int)$c['id']) ?></td></tr>
  <?php endforeach ?>
</table>
<?php endif ?>

<h2>Justificantes de pausa sin subir</h2>
<?php if (!$s['justificantes']): ?><p class="nota">Ninguno.</p><?php else: ?>
<table><tr><th>Persona</th><th>Pausa</th><th>Fecha</th><th>Plazo</th></tr>
  <?php foreach ($s['justificantes'] as $j): ?><tr><td><?= e(nombre(usuario(db(), (int)$j['usuario_id']))) ?></td><td><?= e($j['motivo']) ?></td><td><?= local($j['momento_utc'], 'd/m H:i') ?></td>
    <td><?= $j['vence_utc'] < ahora() ? '<span class="pildora mal">Fuera de plazo</span>' : 'hasta ' . local($j['vence_utc'], 'd/m H:i') ?></td></tr><?php endforeach ?>
</table>
<?php endif ?>

<h2>Envíos a la empresa sin revisar</h2>
<?php if (!$s['envios']): ?><p class="nota">Ninguno.</p><?php else: ?>
<ul><?php foreach ($s['envios'] as $e): ?><li><a href="?p=recibidos"><?= e(nombre($e)) ?> · <?= e($e['asunto']) ?></a> <small><?= local($e['creado_utc'], 'd/m H:i') ?></small></li><?php endforeach ?></ul>
<?php endif ?>

<?php if ($recientes): ?>
<details><summary>Cambios de turno resueltos (30 días)</summary>
<table><tr><th>Pide</th><th>Con</th><th>Días</th><th>Estado</th></tr>
  <?php foreach ($recientes as $c): ?><tr><td><?= e(nombre(usuario(db(), (int)$c['solicitante_id']))) ?></td><td><?= e(nombre(usuario(db(), (int)$c['companero_id']))) ?></td><td><?= implode(', ', array_map(fn($f) => date('d/m', strtotime($f)), fechas_cambio($c))) ?></td><td><?= ESTADOS_CAMBIO[$c['estado']] ?><?= $c['comentario'] !== '' ? ' · ' . e($c['comentario']) : '' ?></td></tr><?php endforeach ?>
</table></details>
<?php endif;
pie();
