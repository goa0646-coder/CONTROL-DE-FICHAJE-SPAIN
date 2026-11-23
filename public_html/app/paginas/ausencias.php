<?php
$u = requiere('empleado', 'responsable', 'admin');
if (es_post()) {
    try {
        if ($id = (int)($_POST['cancelar'] ?? 0)) {
            $a = cancelar_ausencia(db(), $id, $u);
            auditar(db(), (int)$u['id'], 'ausencia_cancelada', "ausencia=$id");
            if ($a['estado'] === 'aprobada') avisar_aprobadores(db(), $u, "ausencia_cancelada:$id", nombre($u) . " ha cancelado su ausencia ({$a['tipo']}) del " . date('d/m', strtotime($a['desde'])) . '.', 'calendario');
            aviso('Ausencia cancelada.');
        } elseif ($id = (int)($_POST['justificar'] ?? 0)) {
            $a = ausencia(db(), $id);
            if (!$a || $a['usuario_id'] != $u['id']) throw new DomainException('Ausencia no encontrada.');
            $archivo = guardar_archivo($_FILES['justificante'] ?? null) ?? throw new DomainException('Elige un archivo.');
            consulta(db(), 'UPDATE ausencias SET justificante = ? WHERE id = ?', [$archivo, $id]);
            auditar(db(), (int)$u['id'], 'justificante_subido', "ausencia=$id");
            aviso('Justificante añadido.');
        } else {
            $archivo = guardar_archivo($_FILES['justificante'] ?? null);
            $id = pedir_ausencia(db(), $u, $_POST, (int)$u['id'], $archivo);
            $a = ausencia(db(), $id);
            auditar(db(), (int)$u['id'], 'ausencia_pedida', "ausencia=$id");
            avisar_aprobadores(db(), $u, "ausencia:$id", nombre($u) . " pide {$a['tipo']}: " . date('d/m', strtotime($a['desde']))
                . ($a['hasta'] !== $a['desde'] ? ' – ' . date('d/m', strtotime($a['hasta'])) : '') . '.');
            aviso('Solicitud enviada. Te avisaremos cuando se resuelva.');
        }
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('ausencias');
}

$anio = (int)($_GET['anio'] ?? local(ahora(), 'Y'));
$tipos = tipos_ausencia(db());
$hoy = local(ahora(), 'Y-m-d');
cabecera('Ausencias y vacaciones', $u);
?>
<div class="cifras">
  <?php foreach (saldos_ausencia(db(), $u, $anio) as $s): ?>
  <div><span><?= e($s['tipo']) ?> <?= $anio ?></span><strong><?= e((float)$s['disponible']) ?></strong>
    <span>de <?= e((float)$s['asignado']) ?> · <?= e($s['consumido']) ?> disfrutados<?= $s['comprometido'] ? ' · ' . e($s['comprometido']) . ' pendientes' : '' ?></span></div>
  <?php endforeach ?>
</div>

<form method="post" enctype="multipart/form-data" class="tarjeta" data-ausencia>
  <?= csrf_campo() ?>
  <h2>Pedir</h2>
  <label>Tipo <select name="tipo_id" required>
    <option value="">Elige…</option>
    <?php foreach ($tipos as $id => $t): ?><option value="<?= e($id) ?>" data-horas="<?= e($t['por_horas']) ?>" data-justificante="<?= e($t['justificante']) ?>"><?= e($t['nombre']) ?></option><?php endforeach ?>
  </select></label>
  <div class="filtros">
    <label>Desde <input type="date" name="desde" required></label>
    <label data-dias>Hasta <input type="date" name="hasta"></label>
    <label data-horas hidden>De <input type="time" name="hora_desde"></label>
    <label data-horas hidden>A <input type="time" name="hora_hasta"></label>
  </div>
  <label>Comentario <input name="motivo" maxlength="500"></label>
  <label>Justificante <span class="nota" data-pide hidden>(este tipo lo pide; puedes subirlo después)</span> <input type="file" name="justificante" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"></label>
  <button>Enviar solicitud</button>
</form>

<h2>Mis ausencias <?= $anio ?> <a class="nota" href="?p=ausencias&anio=<?= $anio - 1 ?>">‹ <?= $anio - 1 ?></a> <a class="nota" href="?p=ausencias&anio=<?= $anio + 1 ?>"><?= $anio + 1 ?> ›</a></h2>
<table>
  <tr><th>Tipo</th><th>Fechas</th><th>Estado</th><th>Justificante</th><th></th></tr>
  <?php foreach (ausencias_de(db(), (int)$u['id'], $anio) as $a): ?>
  <tr class="<?= in_array($a['estado'], ['rechazada', 'cancelada'], true) ? 'anulado' : '' ?>">
    <td><span class="etiqueta" style="background:<?= e($a['color']) ?>"><?= e($a['tipo']) ?></span></td>
    <td><?= date('d/m/Y', strtotime($a['desde'])) ?><?= $a['hasta'] !== $a['desde'] ? ' – ' . date('d/m/Y', strtotime($a['hasta'])) : '' ?>
      <?= $a['hora_desde'] ? ' · ' . substr($a['hora_desde'], 0, 5) . '–' . substr($a['hora_hasta'], 0, 5) : '' ?></td>
    <td><?= ESTADOS_AUSENCIA[$a['estado']] ?><?= $a['comentario'] !== '' ? '<br><small>' . e($a['comentario']) . '</small>' : '' ?></td>
    <td><?php if ($a['justificante']): ?><a href="?p=archivo&a=<?= e($a['id']) ?>">Ver</a>
      <?php elseif ($a['pide_justificante'] && $a['estado'] !== 'cancelada'): ?>
      <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="file" name="justificante" required><button name="justificar" value="<?= e($a['id']) ?>" class="secundario">Subir</button></form>
      <?php endif ?></td>
    <td><?php if ($a['estado'] === 'pendiente' || ($a['estado'] === 'aprobada' && $a['desde'] > $hoy)): ?>
      <form method="post"><?= csrf_campo() ?><button name="cancelar" value="<?= e($a['id']) ?>" class="secundario">Cancelar</button></form><?php endif ?></td>
  </tr>
  <?php endforeach ?>
</table>
<p><a href="?p=calendario">Ver calendario</a></p>
<?php pie();
