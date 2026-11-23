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

// Código QR que cambia cada 30 s: la gente lo escanea con su móvil (con sesión iniciada) para fichar aquí.
if (isset($_GET['codigo'])) {
    header('Content-Type: application/json');
    exit(json_encode(['url' => rtrim(config()['url'] ?? '', '/') . '/?p=fichar&q=' . codigo_quiosco($q)]));
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
            [$tipo, $motivo] = explode('|', $_POST['tipo'] . '|');
            registrar_fichaje(db(), usuario(db(), (int)$uid), $tipo, 'quiosco', ['quiosco' => $q, 'ip' => ip(), 'motivo_id' => $motivo]);
            auditar(db(), (int)$uid, 'fichaje_quiosco', "quiosco={$q['id']} tipo=$tipo");
            aviso('Fichaje registrado: ' . TIPOS[$tipo] . ' a las ' . local(ahora(), 'H:i') . '.');
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
  <?= botones_fichaje($estado) ?>
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
<div class="tarjeta centro">
  <p>O escanea con tu móvil:</p>
  <div class="qr" data-qr-quiosco></div>
</div>
<script src="assets/qrcode.js"></script>
<?php endif;
pie();
