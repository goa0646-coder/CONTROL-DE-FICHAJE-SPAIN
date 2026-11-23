<?php
// Turnos que me ofrecen (horas extra voluntarias). Se aceptan con un código de un solo uso al email,
// o con la contraseña si el servidor no puede enviar correos.
$u = requiere('empleado', 'responsable', 'admin');
if (es_post()) {
    $id = (int)($_POST['id'] ?? 0);
    try {
        $que = $_POST['que'] ?? '';
        if ($que === 'rechazar') {
            consulta(db(), "UPDATE ofertas_turno SET estado = 'rechazada', respondida_utc = ?, ip = ? WHERE id = ? AND usuario_id = ? AND estado = 'pendiente'", [ahora(), ip(), $id, $u['id']]);
            auditar(db(), (int)$u['id'], 'oferta_rechazada', "oferta=$id");
            aviso('Rechazada. Gracias por responder.');
        } elseif ($que === 'pedir_codigo') {
            $_SESSION['codigo_enviado'][$id] = enviar_codigo(db(), $u, "oferta:$id");
            aviso($_SESSION['codigo_enviado'][$id] ? 'Te hemos enviado un código a ' . $u['email'] . '.' : 'No se puede enviar el correo: confirma con tu contraseña.');
        } elseif ($que === 'aceptar') {
            $metodo = !empty($_SESSION['codigo_enviado'][$id]) ? 'codigo-email' : 'contraseña';
            $ok = $metodo === 'codigo-email' ? comprobar_codigo(db(), $u, "oferta:$id", $_POST['codigo'] ?? '') : password_verify($_POST['clave'] ?? '', $u['password_hash']);
            if (!$ok) throw new DomainException($metodo === 'codigo-email' ? 'Código incorrecto o caducado.' : 'La contraseña no es correcta.');
            aceptar_oferta(db(), $u, $id, $metodo);
            unset($_SESSION['codigo_enviado'][$id]);
            auditar(db(), (int)$u['id'], 'oferta_aceptada', "oferta=$id metodo=$metodo");
            aviso('Aceptado. Ya lo tienes en tu calendario.');
        }
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('ofertas');
}
$lista = consulta(db(), 'SELECT * FROM ofertas_turno WHERE usuario_id = ? ORDER BY estado = "pendiente" DESC, fecha DESC LIMIT 50', [$u['id']])->fetchAll();
cabecera('Turnos ofrecidos', $u);
?>
<?php if (!$lista): ?><p class="nota">No tienes turnos ofrecidos.</p><?php endif ?>
<?php foreach ($lista as $o): ?>
<div class="tarjeta">
  <p><?= e(texto_oferta(db(), $o)) ?></p>
  <?php if ($o['motivo'] !== ''): ?><p class="nota">Motivo: <?= e($o['motivo']) ?></p><?php endif ?>
  <?php if ($o['estado'] === 'pendiente' && $o['fecha'] >= local(ahora(), 'Y-m-d')): ?>
    <?php if (isset($_SESSION['codigo_enviado'][$o['id']])): ?>
    <form method="post" class="filtros"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($o['id']) ?>">
      <?php if ($_SESSION['codigo_enviado'][$o['id']]): ?>
      <label>Código del email <input name="codigo" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></label>
      <?php else: ?>
      <label>Tu contraseña <input type="password" name="clave" required autocomplete="current-password"></label>
      <?php endif ?>
      <button name="que" value="aceptar">Confirmar y aceptar</button>
    </form>
    <?php else: ?>
    <form method="post" class="filtros"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($o['id']) ?>">
      <button name="que" value="pedir_codigo">Aceptar</button>
      <button name="que" value="rechazar" class="secundario">No puedo</button>
    </form>
    <?php endif ?>
    <p class="nota">Es voluntario. Si aceptas, quedará registrado con la fecha, tu IP y el texto exacto que aceptas.</p>
  <?php else: ?>
  <p class="nota">Estado: <?= e($o['estado']) ?><?= $o['respondida_utc'] ? ' · ' . local($o['respondida_utc']) : '' ?></p>
  <?php endif ?>
</div>
<?php endforeach;
pie();
