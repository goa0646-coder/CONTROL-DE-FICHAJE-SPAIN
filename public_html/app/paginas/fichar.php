<?php
$u = requiere('empleado', 'responsable', 'admin');
$c = $u['centro_id'] ? centro(db(), (int)$u['centro_id']) : null;
$modo = $u['gps_modo'] ?? $c['gps_modo'] ?? 'no';

if (es_post()) {
    if (isset($_POST['aceptar_aviso'])) {
        db()->prepare('UPDATE usuarios SET aviso_gps_utc = ? WHERE id = ?')->execute([ahora(), $u['id']]);
        auditar(db(), (int)$u['id'], 'aviso_gps_leido');
        redirigir('fichar');
    }
    try {
        $origen = preg_match('/Mobi|Android|iPhone|iPad/', $_SERVER['HTTP_USER_AGENT'] ?? '') ? 'movil' : 'web';
        registrar_fichaje(db(), $u, $_POST['tipo'] ?? '', $origen, ['ip' => ip()] + $_POST);
        aviso('Fichaje registrado: ' . TIPOS[$_POST['tipo']] . ' a las ' . local(ahora(), 'H:i') . '.');
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('fichar');
}

cabecera('Fichar', $u);
if ($modo !== 'no' && !$u['aviso_gps_utc']): ?>
<div class="tarjeta">
  <h2>Aviso sobre tu ubicación</h2>
  <p>Al pulsar un botón de fichaje, el sistema guarda tu ubicación <strong>solo en ese momento</strong> para comprobar si estás en tu centro de trabajo.
     Nunca se sigue tu posición. Las coordenadas se borran a los 12 meses; después solo queda si fichaste dentro o fuera del centro.</p>
  <p>Responsable: <?= e(db()->query('SELECT nombre FROM empresa')->fetchColumn() ?: 'la empresa') ?>. Finalidad: registro de jornada (art. 34.9 ET).
     Puedes ejercer tus derechos de acceso, rectificación y oposición ante la empresa.</p>
  <?php if ($modo === 'opcional'): ?><p>En tu caso la ubicación es <strong>opcional</strong>: si no das permiso, puedes fichar igual.</p><?php endif ?>
  <form method="post"><?= csrf_campo() ?><button name="aceptar_aviso" value="1">He leído el aviso</button></form>
</div>
<?php pie(); return; endif;

$estado = estado_actual(db(), (int)$u['id']);
$botones = ['entrada' => 'Entrar', 'pausa_inicio' => 'Empezar pausa', 'pausa_fin' => 'Volver de la pausa', 'salida' => 'Salir'];
?>
<div class="tarjeta centro">
  <p class="reloj" data-reloj><?= local(ahora(), 'H:i') ?></p>
  <p class="estado <?= $estado ?>"><?= ['fuera' => 'Fuera', 'dentro' => 'Trabajando', 'pausa' => 'En pausa'][$estado] ?></p>
  <form method="post" data-gps="<?= e($modo) ?>">
    <?= csrf_campo() ?>
    <input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="precision">
    <?php foreach (PERMITIDOS[$estado] as $t): ?>
      <button class="grande <?= $t ?>" name="tipo" value="<?= $t ?>"><?= $botones[$t] ?></button>
    <?php endforeach ?>
  </form>
  <?php if ($modo !== 'no'): ?><p class="nota">Se guardará tu ubicación solo al fichar<?= $modo === 'opcional' ? ' (si la permites)' : '' ?>.</p><?php endif ?>
</div>
<?php pie();
