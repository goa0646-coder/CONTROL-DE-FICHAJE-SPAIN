<?php
$s = db()->prepare('SELECT * FROM quioscos WHERE token_hash = ? AND activo = 1');
$s->execute([hash('sha256', $_COOKIE['quiosco'] ?? '')]);
$q = $s->fetch();
if (!$q) {
    cabecera('Quiosco');
    echo '<p class="aviso error">Este dispositivo no está activado como quiosco. Un administrador debe activarlo desde «Quioscos».</p>';
    pie();
    return;
}

// Tras identificarse, la persona tiene 30 s para pulsar el botón.
$uid = ($_SESSION['quiosco_hasta'] ?? 0) > time() ? ($_SESSION['quiosco_uid'] ?? null) : null;

if (es_post()) {
    if (isset($_POST['pin'])) {
        $r = login_pin(db(), trim($_POST['num'] ?? ''), $_POST['pin']);
        if (is_string($r)) {
            aviso($r, 'error');
        } else {
            $_SESSION['quiosco_uid'] = $r['id'];
            $_SESSION['quiosco_hasta'] = time() + 30;
        }
    } elseif ($uid && isset($_POST['tipo'])) {
        try {
            registrar_fichaje(db(), usuario(db(), (int)$uid), $_POST['tipo'], 'quiosco', ['quiosco' => $q, 'ip' => ip()]);
            auditar(db(), (int)$uid, 'fichaje_quiosco', "quiosco={$q['id']} tipo={$_POST['tipo']}");
            aviso('Fichaje registrado: ' . TIPOS[$_POST['tipo']] . ' a las ' . local(ahora(), 'H:i') . '.');
        } catch (DomainException $e) {
            aviso($e->getMessage(), 'error');
        }
        unset($_SESSION['quiosco_uid'], $_SESSION['quiosco_hasta']);
    } else {
        unset($_SESSION['quiosco_uid'], $_SESSION['quiosco_hasta']);
    }
    redirigir('quiosco');
}

cabecera($q['nombre']);
$u = $uid ? usuario(db(), (int)$uid) : null;
if ($u):
    $estado = estado_actual(db(), (int)$u['id']); ?>
<meta http-equiv="refresh" content="30">
<form method="post" class="tarjeta centro">
  <?= csrf_campo() ?>
  <p class="reloj" data-reloj><?= local(ahora(), 'H:i') ?></p>
  <h2>Hola, <?= e($u['nombre']) ?></h2>
  <p class="estado <?= $estado ?>"><?= ['fuera' => 'Fuera', 'dentro' => 'Trabajando', 'pausa' => 'En pausa'][$estado] ?></p>
  <?php foreach (PERMITIDOS[$estado] as $t): ?>
    <button class="grande <?= $t ?>" name="tipo" value="<?= $t ?>"><?= ['entrada' => 'Entrar', 'pausa_inicio' => 'Empezar pausa', 'pausa_fin' => 'Volver de la pausa', 'salida' => 'Salir'][$t] ?></button>
  <?php endforeach ?>
  <button name="cancelar" value="1" class="secundario">No soy yo</button>
</form>
<?php else: ?>
<?php if (isset($_SESSION['aviso'])): ?><meta http-equiv="refresh" content="5"><?php endif ?>
<form method="post" class="tarjeta centro">
  <?= csrf_campo() ?>
  <p class="reloj" data-reloj><?= local(ahora(), 'H:i') ?></p>
  <label>Nº de empleado <input name="num" inputmode="numeric" autocomplete="off" required autofocus></label>
  <label>PIN <input type="password" name="pin" inputmode="numeric" autocomplete="off" required></label>
  <button class="grande">Continuar</button>
</form>
<?php endif;
pie();
