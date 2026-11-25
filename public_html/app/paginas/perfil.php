<?php
$u = requiere(...ROLES);
$token_nuevo = null;
if (es_post()) {
    try {
        $que = $_POST['que'] ?? '';
        if ($que === 'valoraciones') {
            activar_valoraciones(db(), $u, isset($_POST['activar']));
            aviso(isset($_POST['activar']) ? 'Valoración de calidad activada: solo tú la verás en la ficha de cada persona.' : 'Valoración de calidad desactivada (los datos se conservan).');
        } elseif ($que === 'foto') {
            guardar_foto(db(), $u, $_FILES['foto'] ?? null);
            auditar(db(), (int)$u['id'], 'foto_cambiada');
            aviso('Foto actualizada.');
        } elseif ($que === 'quitar_foto') {
            consulta(db(), 'UPDATE usuarios SET foto = NULL WHERE id = ?', [$u['id']]);
            aviso('Foto quitada.');
        } elseif ($que === 'clave') {
            if (!password_verify($_POST['actual'] ?? '', $u['password_hash'])) throw new DomainException('La contraseña actual no es correcta.');
            if (strlen($_POST['nueva'] ?? '') < 8) throw new DomainException('La nueva contraseña debe tener al menos 8 caracteres.');
            consulta(db(), 'UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($_POST['nueva'], PASSWORD_DEFAULT), $u['id']]);
            cerrar_dispositivos(db(), (int)$u['id'], $_COOKIE[DISPOSITIVO_COOKIE] ?? '');
            auditar(db(), (int)$u['id'], 'cambio_clave');
            aviso('Contraseña cambiada. Se han cerrado las sesiones de tus otros dispositivos.');
        } elseif ($que === 'push') {
            suscribir_push(db(), (int)$u['id'], (string)($_POST['endpoint'] ?? ''));
            exit('ok');
        } elseif ($que === 'token' && $u['rol'] !== 'inspeccion') {
            $alcance = $u['rol'] === 'admin' && ($_POST['alcance'] ?? '') === 'lectura' ? 'lectura' : 'personal';
            $_SESSION['token_nuevo'] = crear_token(db(), (int)$u['id'], trim($_POST['nombre'] ?? ''), $alcance);
            auditar(db(), (int)$u['id'], 'token_creado', $alcance);
        } elseif ($que === 'cerrar_dispositivo') {
            consulta(db(), 'UPDATE dispositivos SET activo = 0 WHERE id = ? AND usuario_id = ?', [(int)$_POST['id'], $u['id']]);
            auditar(db(), (int)$u['id'], 'dispositivo_cerrado', 'dispositivo=' . (int)$_POST['id']);
            aviso('Sesión cerrada en ese dispositivo.');
        } elseif ($que === 'revocar') {
            consulta(db(), 'UPDATE tokens_api SET activo = 0 WHERE id = ? AND usuario_id = ?', [(int)$_POST['id'], $u['id']]);
            auditar(db(), (int)$u['id'], 'token_revocado', 'token=' . (int)$_POST['id']);
            aviso('Acceso revocado.');
        }
    } catch (DomainException $e) {
        if (($_POST['que'] ?? '') === 'push') exit($e->getMessage());
        aviso($e->getMessage(), 'error');
    }
    redirigir('perfil');
}
$token_nuevo = $_SESSION['token_nuevo'] ?? null;
unset($_SESSION['token_nuevo']);
$actual = hash('sha256', $_COOKIE[DISPOSITIVO_COOKIE] ?? '');
$dispositivos = consulta(db(), 'SELECT * FROM dispositivos WHERE usuario_id = ? AND activo = 1 ORDER BY usado_utc DESC', [$u['id']])->fetchAll();
$tokens = consulta(db(), 'SELECT * FROM tokens_api WHERE usuario_id = ? AND activo = 1 ORDER BY id DESC', [$u['id']])->fetchAll();
$c = $u['centro_id'] ? centro(db(), (int)$u['centro_id']) : null;
$jefe = $u['responsable_id'] ? usuario(db(), (int)$u['responsable_id']) : null;
$dep = $u['departamento_id'] ? consulta(db(), 'SELECT nombre FROM departamentos WHERE id = ?', [$u['departamento_id']])->fetchColumn() : null;
$hor = $u['horario_id'] ? (horarios(db())[$u['horario_id']]['nombre'] ?? null) : null;
cabecera('Mi perfil', $u);
?>
<?php $saldos = $u['rol'] !== 'inspeccion' ? saldos_ausencia(db(), $u, (int)local(ahora(), 'Y')) : [];
$pts = array_intersect_key(puestos(db()), array_flip(puestos_por_persona(db())[$u['id']] ?? [])); ?>
<div class="tarjeta ficha">
  <div class="ficha-cab">
    <form method="post" enctype="multipart/form-data" class="foto-perfil" data-foto>
      <?= csrf_campo() ?>
      <?= avatar($u, 'grande') ?>
      <label class="cambiar-foto" title="Cambiar foto"><input type="file" name="foto" accept="image/jpeg,image/png,image/webp" data-subir-foto><span>Cambiar</span></label>
      <input type="hidden" name="que" value="foto">
    </form>
    <div>
      <h2><?= e(nombre($u)) ?></h2>
      <p class="nota"><?= e($pts ? implode(' · ', $pts) : ['empleado' => 'Empleado', 'responsable' => 'Responsable', 'admin' => 'Administración', 'inspeccion' => 'Inspección'][$u['rol']]) ?><?= $c ? ' · ' . e($c['nombre']) : '' ?></p>
      <?php if ($u['foto']): ?><form method="post" class="en-linea"><?= csrf_campo() ?><button name="que" value="quitar_foto" class="enlace">Quitar foto</button></form><?php endif ?>
    </div>
  </div>
  <?php foreach ($saldos as $sa): ?>
  <div class="cifras">
    <div><span><?= e($sa['tipo']) ?>: disponibles</span><strong><?= e(+$sa['disponible']) ?> <small>días</small></strong></div>
    <div><span>Disfrutados</span><strong><?= e(+$sa['consumido']) ?></strong></div>
    <div><span>Pendientes de aprobar</span><strong><?= e(+$sa['comprometido']) ?></strong></div>
    <div><span>Asignados <?= local(ahora(), 'Y') ?></span><strong><?= e(+$sa['asignado']) ?></strong></div>
  </div>
  <?php endforeach ?>
  <dl class="datos">
    <div><dt>Email</dt><dd><?= e($u['email']) ?></dd></div>
    <div><dt>DNI</dt><dd><?= e($u['dni'] ?: '—') ?></dd></div>
    <div><dt>Teléfono</dt><dd><?= e($u['telefono'] ?: '—') ?></dd></div>
    <div><dt>Nº Seguridad Social</dt><dd><?= e($u['nss'] ?: '—') ?></dd></div>
    <div><dt>Fecha de nacimiento</dt><dd><?= $u['fecha_nacimiento'] ? date('d/m/Y', strtotime($u['fecha_nacimiento'])) : '—' ?></dd></div>
    <?php if ($u['num_empleado']): ?><div><dt>Nº empleado</dt><dd><?= e($u['num_empleado']) ?></dd></div><?php endif ?>
    <div><dt>Centro</dt><dd><?= e($c['nombre'] ?? '—') ?></dd></div>
    <div><dt>Departamento</dt><dd><?= e($dep ?? '—') ?></dd></div>
    <div><dt>Responsable</dt><dd><?= e($jefe ? nombre($jefe) : '—') ?></dd></div>
    <div><dt>Horario</dt><dd><?= e($hor ?? 'Sin horario') ?></dd></div>
    <div><dt>Alta</dt><dd><?= local($u['alta_utc'], 'd/m/Y') ?></dd></div>
  </dl>
  <p class="nota">Si algún dato no es correcto, díselo a administración desde <a href="?p=enviar">Enviar a la empresa</a>.</p>
</div>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Cambiar contraseña</h2>
  <label>Contraseña actual <input type="password" name="actual" required autocomplete="current-password"></label>
  <label>Nueva (mínimo 8) <input type="password" name="nueva" required minlength="8" autocomplete="new-password"></label>
  <button name="que" value="clave">Guardar</button>
</form>

<?php if ($dispositivos): ?>
<div class="tarjeta">
  <h2>Dispositivos con la sesión abierta</h2>
  <table>
    <?php foreach ($dispositivos as $d): ?>
    <tr><td><?= e($d['nombre'] ?: 'Dispositivo') ?><?= hash_equals($d['token_hash'], $actual) ? ' <strong>(este)</strong>' : '' ?></td><td>Último uso <?= local($d['usado_utc']) ?></td>
      <td><form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($d['id']) ?>"><button name="que" value="cerrar_dispositivo" class="secundario">Cerrar sesión</button></form></td></tr>
    <?php endforeach ?>
  </table>
  <p class="nota">Si pierdes el móvil, cierra aquí su sesión (o cambia la contraseña).</p>
</div>
<?php endif ?>

<div class="tarjeta">
  <h2>Avisos en este dispositivo</h2>
  <p>Recibe en el móvil o el ordenador los recordatorios de fichar y las respuestas a tus solicitudes.</p>
  <?php if ($vapid = claves_vapid(db())[1]): ?>
  <button type="button" data-push="<?= e($vapid) ?>" data-csrf="<?= e($_SESSION['csrf']) ?>">Activar avisos</button>
  <?php else: ?><p class="nota">Este servidor no permite avisos push. Te llegarán por email.</p><?php endif ?>
  <p class="nota" data-push-estado></p>
</div>

<?php if ($u['rol'] !== 'inspeccion'): ?>
<div class="tarjeta">
  <h2>Accesos para la extensión y el chat</h2>
  <?php if ($token_nuevo): ?>
  <p class="aviso ok">Copia este código ahora; no se volverá a mostrar:<br><code><?= e($token_nuevo) ?></code></p>
  <?php endif ?>
  <p class="nota">Con un código puedes fichar desde la extensión del navegador o desde Slack/Teams (<code>/inda vincular CÓDIGO</code>).</p>
  <table>
    <?php foreach ($tokens as $t): ?>
    <tr><td><?= e($t['nombre']) ?></td><td><?= $t['alcance'] === 'lectura' ? 'Exportación (lectura)' : 'Personal' ?></td><td><?= $t['usado_utc'] ? 'Usado ' . local($t['usado_utc']) : 'Sin usar' ?></td>
      <td><form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($t['id']) ?>"><button name="que" value="revocar" class="secundario">Revocar</button></form></td></tr>
    <?php endforeach ?>
  </table>
  <form method="post" class="filtros">
    <?= csrf_campo() ?>
    <label>Nombre <input name="nombre" placeholder="Portátil / Slack" required></label>
    <?php if ($u['rol'] === 'admin'): ?><label>Tipo <select name="alcance"><option value="personal">Personal (fichar)</option><option value="lectura">Exportación para gestoría (lectura)</option></select></label><?php endif ?>
    <button name="que" value="token">Crear código</button>
  </form>
</div>
<?php endif;
if ($u['rol'] === 'admin' && (!valoraciones_de(db()) || ve_valoraciones(db(), $u))): ?>
<details class="tarjeta">
  <summary>Funciones privadas</summary>
  <form method="post">
    <?= csrf_campo() ?>
    <label><input type="checkbox" name="activar" <?= ve_valoraciones(db(), $u) ? 'checked' : '' ?>> Valoración de calidad (1–5 estrellas) en la ficha de cada persona</label>
    <p class="nota">Solo la verás tú: ni otros administradores, ni responsables, ni los trabajadores, ni Inspección. Al activarla, también la lectura con IA y el asistente quedan solo para ti.</p>
    <button name="que" value="valoraciones" class="secundario">Guardar</button>
  </form>
</details>
<?php endif;
pie();
