<?php
$u = requiere('empleado', 'responsable', 'admin');
$c = $u['centro_id'] ? centro(db(), (int)$u['centro_id']) : null;
$modo = $u['gps_modo'] ?? $c['gps_modo'] ?? 'no';
if (isset($_GET['q'])) {
    $qr = validar_qr(db(), (string)$_GET['q']);
    if ($qr) {
        $_SESSION['qr'] = $qr + ['hasta' => time() + 120];
        aviso('Código QR correcto: ficha ahora.');
    } else {
        aviso('El código QR no vale o ha caducado. Escanéalo de nuevo.', 'error');
    }
    redirigir('fichar');
}
$qr = ($_SESSION['qr']['hasta'] ?? 0) > time() ? $_SESSION['qr'] : null;
if ($qr) $modo = 'no'; // El QR ya prueba dónde estás: no hace falta GPS.

if (es_post()) {
    if (isset($_POST['aceptar_aviso'])) {
        db()->prepare('UPDATE usuarios SET aviso_gps_utc = ? WHERE id = ?')->execute([ahora(), $u['id']]);
        auditar(db(), (int)$u['id'], 'aviso_gps_leido');
        redirigir('fichar');
    }
    try {
        $origen = $qr ? 'qr' : (preg_match('/Mobi|Android|iPhone|iPad/', $_SERVER['HTTP_USER_AGENT'] ?? '') ? 'movil' : 'web');
        [$tipo, $motivo] = explode('|', ($_POST['tipo'] ?? '') . '|');
        registrar_fichaje(db(), $u, $tipo, $origen, ['ip' => ip(), 'motivo_id' => $motivo, 'quiosco' => $qr] + $_POST);
        unset($_SESSION['qr']);
        aviso('Fichaje registrado: ' . TIPOS[$tipo] . ' a las ' . local(ahora(), 'H:i') . '.');
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
$hoy = local(ahora(), 'Y-m-d');
$dia = calendario_teorico(db(), $u, $hoy, $hoy)[$hoy];
$trabajado = 0;
foreach (jornadas(fichajes_de(db(), (int)$u['id'], a_utc("$hoy -1 day"))) as $j) {
    if (local($j['entrada'], 'Y-m-d') === $hoy || $j['salida'] === null) $trabajado += $j['trabajado'] + (!empty($j['en_curso']) ? time() - $j['en_curso'] : 0);
}
$sin_leer = consulta(db(), 'SELECT c.id, c.titulo FROM comunicados c WHERE (c.centro_id IS NULL OR c.centro_id = ?)
    AND NOT EXISTS (SELECT 1 FROM comunicados_leidos l WHERE l.comunicado_id = c.id AND l.usuario_id = ?) ORDER BY c.id DESC LIMIT 3', [$u['centro_id'], $u['id']])->fetchAll();
?>
<div class="tarjeta centro">
  <p class="reloj" data-reloj><?= local(ahora(), 'H:i') ?></p>
  <?php if ($qr): ?><p class="aviso ok">Fichando con QR en <?= e(centro(db(), (int)$qr['centro_id'])['nombre']) ?></p><?php endif ?>
  <p class="estado <?= $estado ?>"><?= ['fuera' => 'Fuera', 'dentro' => 'Trabajando', 'pausa' => 'En pausa'][$estado] ?></p>
  <form method="post" data-gps="<?= e($modo) ?>">
    <?= csrf_campo() ?>
    <input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="precision">
    <?= botones_fichaje($estado) ?>
  </form>
  <p class="nota">Hoy: <?= $dia['ausencia'] ? e($dia['ausencia']['tipo']) : ($dia['festivo'] ? 'Festivo (' . e($dia['festivo']) . ')' : ($dia['minutos'] ? e($dia['horario']) . (!$dia['flexible'] && $dia['entrada'] ? ' · ' . substr($dia['entrada'], 0, 5) . '–' . substr((string)$dia['salida'], 0, 5) : '') . ' · ' . horas($dia['minutos'] * 60) . ' h' : 'Sin horario')) ?>
    · Llevas <?= horas($trabajado) ?> h</p>
  <?php if ($modo !== 'no'): ?><p class="nota">Se guardará tu ubicación solo al fichar<?= $modo === 'opcional' ? ' (si la permites)' : '' ?>.</p><?php endif ?>
</div>
<div class="tarjeta centro" data-instalar hidden>
  <p>Instala INDA en tu teléfono y ficha con un toque, como una app.</p>
  <button type="button" data-instalar-boton>Instalar INDA</button>
  <p class="nota" data-ios hidden>En iPhone: pulsa <strong>Compartir</strong> y después <strong>«Añadir a pantalla de inicio»</strong>.</p>
</div>
<?php foreach ($sin_leer as $c): ?>
<p class="aviso ok"><a href="?p=comunicados#c<?= e($c['id']) ?>">📣 <?= e($c['titulo']) ?></a></p>
<?php endforeach;
pie();
