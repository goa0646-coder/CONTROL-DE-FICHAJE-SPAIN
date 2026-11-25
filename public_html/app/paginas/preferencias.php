<?php
// Mis preferencias: turnos, días o fechas que prefiero o quiero evitar. Son sugerencias: se tienen en cuenta si caben.
$u = requiere('empleado', 'responsable', 'admin');
if (es_post()) {
    try {
        if ($id = (int)($_POST['quitar'] ?? 0)) {
            consulta(db(), 'UPDATE preferencias SET activa = 0 WHERE id = ? AND usuario_id = ?', [$id, $u['id']]);
            aviso('Preferencia quitada.');
        } else {
            guardar_preferencia(db(), $u, $_POST);
            auditar(db(), (int)$u['id'], 'preferencia_guardada');
            aviso('Guardada. Se tendrá en cuenta al hacer el cuadrante siempre que se puedan cumplir los ratios y el convenio.');
        }
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('preferencias');
}
$hors = horarios(db());
$mias = preferencias_activas(db(), [(int)$u['id']])[$u['id']] ?? [];
$opciones = opciones_turno($hors, $u);
cabecera('Mis preferencias', $u);
?>
<p class="nota">Dinos qué turnos, días o fechas prefieres o te vienen mal. <b>No son normas</b>: al hacer el cuadrante se intentan cumplir, pero siempre por detrás de los ratios, el convenio y un reparto justo entre todos.</p>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Añadir una preferencia</h2>
  <div class="filtros">
    <label>Quiero <select name="prefiere"><option value="1">Preferir</option><option value="0">Evitar</option></select></label>
    <label>Qué <select name="tipo" data-tipo-pref><option value="turno">Un turno (siempre)</option><option value="dia">Un día de la semana</option><option value="fecha">Una fecha concreta</option></select></label>
    <label>Turno <select name="horario_id"><option value="">— trabajar (cualquier turno) —</option><?php foreach ($opciones as $g => $lista) foreach ($lista as $hid => $txt): ?><option value="<?= e($hid) ?>"><?= e($txt) ?></option><?php endforeach ?></select></label>
    <label>Día <select name="dia"><?php foreach (DIAS as $n => $d): ?><option value="<?= $n ?>"><?= $d ?></option><?php endforeach ?></select></label>
    <label>Fecha <input type="date" name="fecha" min="<?= local(ahora(), 'Y-m-d') ?>"></label>
  </div>
  <label>Comentario (opcional) <input name="comentario" maxlength="300" placeholder="p. ej. llevo a mis hijos al colegio"></label>
  <button>Guardar</button>
</form>
<h2>Mis preferencias</h2>
<?php if (!$mias): ?><p class="nota">Todavía ninguna.</p><?php else: ?>
<table><?php foreach ($mias as $p): ?><tr><td><span class="pildora <?= $p['prefiere'] ? 'ok' : 'pendiente' ?>"><?= $p['prefiere'] ? 'Prefiere' : 'Evitar' ?></span> <?= e(texto_preferencia($p, $hors)) ?><?= $p['comentario'] !== '' ? '<br><small>' . e($p['comentario']) . '</small>' : '' ?></td>
  <td><form method="post"><?= csrf_campo() ?><button name="quitar" value="<?= e($p['id']) ?>" class="secundario pequeno">Quitar</button></form></td></tr><?php endforeach ?></table>
<?php endif;
pie();
