<?php
// Cartel imprimible con el QR fijo de un centro. Quien lo escanea (con sesión iniciada) ficha en ese centro.
$yo = requiere('admin');
$c = centro(db(), (int)($_GET['c'] ?? 0)) ?? redirigir('admin');
$url = rtrim(config()['url'] ?? '', '/') . '/?p=fichar&q=' . codigo_centro(db(), (int)$c['id']);
cabecera('Cartel QR', $yo);
?>
<div class="centro">
  <p class="marca-grande">INDA</p>
  <h2>Ficha aquí: <?= e($c['nombre']) ?></h2>
  <div class="qr" data-qr="<?= e($url) ?>"></div>
  <p>1. Abre la cámara del móvil y apunta al código.<br>2. Entra en INDA si te lo pide.<br>3. Pulsa Entrar, Pausa o Salir.</p>
  <p class="no-imprimir"><button type="button" data-imprimir>Imprimir</button></p>
  <p class="nota no-imprimir">Un cartel fijo se puede fotografiar. Para más seguridad usa el QR del quiosco, que cambia cada 30 segundos.</p>
</div>
<script src="assets/qrcode.js"></script>
<?php pie();
