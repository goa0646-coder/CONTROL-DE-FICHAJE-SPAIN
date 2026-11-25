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
    if ($id = (int)($_POST['justificar_pausa'] ?? 0)) {
        try {
            justificar_pausa(db(), $u, $id, fn() => [guardar_archivo($_FILES['justificante'] ?? null) ?? throw new DomainException('Elige el archivo del justificante.'), $_FILES['justificante']['name'] ?? '']);
            aviso('Justificante subido.');
        } catch (DomainException $e) {
            aviso($e->getMessage(), 'error');
        }
        redirigir('fichar');
    }
    try {
        $origen = $qr ? 'qr' : (preg_match('/Mobi|Android|iPhone|iPad/', $_SERVER['HTTP_USER_AGENT'] ?? '') ? 'movil' : 'web');
        [$tipo, $motivo] = explode('|', ($_POST['tipo'] ?? '') . '|');
        registrar_fichaje(db(), $u, $tipo, $origen, ['ip' => ip(), 'motivo_id' => $motivo, 'quiosco' => $qr] + $_POST);
        unset($_SESSION['qr']);
        $pide = $tipo === 'pausa_inicio' && $motivo && consulta(db(), 'SELECT justificante FROM motivos_pausa WHERE id = ?', [(int)$motivo])->fetchColumn();
        aviso('Fichaje registrado: ' . TIPOS[$tipo] . ' a las ' . local(ahora(), 'H:i') . '.' . ($pide ? ' Esta pausa necesita justificante: súbelo aquí en 1 día como máximo.' : ''));
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
$abierta = null; // jornada sin salida que empezó hace demasiado (se olvidó salir)
foreach (jornadas(fichajes_de(db(), (int)$u['id'], a_utc("$hoy -1 day"))) as $j) {
    if (local($j['entrada'], 'Y-m-d') === $hoy || $j['salida'] === null) $trabajado += $j['trabajado'] + (!empty($j['en_curso']) ? time() - $j['en_curso'] : 0);
    if ($j['salida'] === null && time() - strtotime($j['entrada'] . ' UTC') > (reglas(db())['jornada_max_diaria_h'] + 3) * 3600) $abierta = $j['entrada'];
}
$sin_leer = array_slice(anuncios_pendientes(db(), $u), 0, 3);
$hora = (int)local(ahora(), 'G');
$saludo = $hora < 7 ? 'Buenas noches' : ($hora < 14 ? 'Buenos días' : ($hora < 21 ? 'Buenas tardes' : 'Buenas noches'));
$teorico = $dia['ausencia'] || $dia['festivo'] ? 0 : $dia['minutos'] * 60;
$pct = $teorico ? min(1, $trabajado / $teorico) : 0;
$proximos = calendario_teorico(db(), $u, date('Y-m-d', strtotime("$hoy +1 day")), date('Y-m-d', strtotime("$hoy +6 days")));
$hors = horarios(db());
$vac = array_values(array_filter(saldos_ausencia(db(), $u, (int)local(ahora(), 'Y')), fn($x) => str_contains(normalizar($x['tipo']), 'vacacion')))[0] ?? null;
$hoy_txt = $dia['ausencia'] ? $dia['ausencia']['tipo'] : ($dia['festivo'] ? 'Festivo: ' . $dia['festivo'] : ($dia['minutos'] ? ($dia['horario'] ?: 'Laborable') : (!$dia['horario_id'] && $dia['laborable'] ? 'Sin turno asignado' : 'Descanso')));
[$letra, $color] = celda_turno($dia, $hors, true);
?>
<p class="saludo"><?= e($saludo) ?>, <?= e($u['nombre']) ?> <span><?= e(ucfirst(fecha_larga())) ?></span></p>
<div class="fichar-rejilla">
<section class="tarjeta fichar-principal">
  <div class="anillo" style="--pct:<?= round($pct * 100) ?>">
    <svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="54" class="pista"/><?php if ($pct > 0): ?><circle cx="60" cy="60" r="54" class="progreso" pathLength="100" stroke-dasharray="<?= round($pct * 100, 1) ?> 100"/><?php endif ?></svg>
    <div class="anillo-centro">
      <p class="reloj" data-reloj data-segundos><?= local(ahora(), 'H:i:s') ?></p>
      <p class="estado <?= $estado ?>"><?= ['fuera' => 'Fuera', 'dentro' => 'Trabajando', 'pausa' => 'En pausa'][$estado] ?></p>
    </div>
  </div>
  <p class="llevas"><strong data-contador="<?= (int)$trabajado ?>"<?= $estado === 'dentro' ? ' data-corre' : '' ?>><?= sprintf('%d:%02d:%02d', intdiv((int)$trabajado, 3600), intdiv((int)$trabajado % 3600, 60), (int)$trabajado % 60) ?></strong> trabajadas hoy<?= $teorico ? ' de ' . horas($teorico) . ' h' : '' ?></p>
  <?php if ($abierta): ?><p class="aviso error"><?= icono('alerta') ?><span>Tu jornada sigue abierta desde el <?= local($abierta, 'd/m') ?> a las <?= local($abierta, 'H:i') ?>. Si se te olvidó salir, ficha la salida y pide una <a href="?p=correcciones">corrección</a> con la hora real.</span></p><?php endif ?>
  <?php foreach (pausas_sin_justificante(db(), (int)$u['id']) as $pj): ?>
  <form method="post" enctype="multipart/form-data" class="aviso <?= $pj['vence_utc'] < ahora() ? 'error' : 'info' ?> justificar"><?= csrf_campo() ?>
    <?= icono('alerta') ?><span>Pausa <b><?= e($pj['motivo']) ?></b> del <?= local($pj['momento_utc'], 'd/m H:i') ?>: sube el justificante <?= $pj['vence_utc'] < ahora() ? '<b>(fuera de plazo)</b>' : 'antes del ' . local($pj['vence_utc'], 'd/m H:i') ?>.</span>
    <input type="file" name="justificante" accept="image/*,application/pdf" required><button name="justificar_pausa" value="<?= e($pj['id']) ?>" class="secundario">Subir</button></form>
  <?php endforeach ?>
  <?php if ($qr): ?><p class="aviso ok"><?= icono('ok') ?><span>Fichando con QR en <?= e(centro(db(), (int)$qr['centro_id'])['nombre']) ?></span></p><?php endif ?>
  <form method="post" data-gps="<?= e($modo) ?>">
    <?= csrf_campo() ?>
    <input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="precision">
    <?= botones_fichaje($estado) ?>
  </form>
  <?php if ($modo !== 'no'): ?><p class="nota">Se guardará tu ubicación solo al fichar<?= $modo === 'opcional' ? ' (si la permites)' : '' ?>.</p><?php endif ?>
</section>
<aside class="fichar-lado">
  <div class="tarjeta dato">
    <span class="rotulo">Hoy</span>
    <p class="turno-hoy"><?php if ($letra !== ''): ?><span class="letra-turno" style="background:<?= e($color ?: '#98a2b3') ?>"><?= e($letra) ?></span><?php endif ?>
      <span><strong><?= e($hoy_txt) ?></strong><?php if (!$dia['ausencia'] && !$dia['flexible'] && $dia['entrada'] && $dia['minutos']): ?><small><?= substr($dia['entrada'], 0, 5) ?> – <?= substr((string)$dia['salida'], 0, 5) ?> · <?= horas($dia['minutos'] * 60) ?> h</small><?php endif ?></span></p>
  </div>
  <div class="tarjeta dato">
    <span class="rotulo">Próximos días</span>
    <ol class="semana-mini">
      <?php foreach ($proximos as $f => $c): [$l, $col, $t] = celda_turno($c, $hors, true); ?>
      <li title="<?= e($t) ?>"><small><?= mb_substr(DIAS[(int)date('N', strtotime($f))], 0, 1) ?> <?= (int)substr($f, 8) ?></small><span class="letra-turno<?= $col ? '' : ' vacia' ?>" style="<?= $col ? 'background:' . e($col) : '' ?>"><?= e($l ?: '·') ?></span></li>
      <?php endforeach ?>
    </ol>
    <a href="?p=calendario">Ver calendario</a>
  </div>
  <?php if ($vac): ?>
  <div class="tarjeta dato">
    <span class="rotulo">Vacaciones <?= local(ahora(), 'Y') ?></span>
    <p class="cifra-grande"><?= e(+$vac['disponible']) ?> <small>días disponibles de <?= e(+$vac['asignado']) ?></small></p>
    <a href="?p=ausencias">Pedir vacaciones o permisos</a>
  </div>
  <?php endif ?>
  <?php if ($sin_leer): ?>
  <div class="tarjeta dato">
    <span class="rotulo">Anuncios pendientes de leer</span>
    <?php foreach ($sin_leer as $c): ?><a class="anuncio-mini" href="?p=comunicados&id=<?= e($c['id']) ?>"><?= icono('comunicados') ?><span><?= e($c['titulo']) ?></span></a><?php endforeach ?>
  </div>
  <?php endif ?>
</aside>
</div>
<div class="tarjeta centro" data-instalar hidden>
  <p>Instala la app en tu teléfono y ficha con un toque.</p>
  <button type="button" data-instalar-boton>Instalar en el teléfono</button>
  <p class="nota" data-ios hidden>En iPhone: pulsa <strong>Compartir</strong> y después <strong>«Añadir a pantalla de inicio»</strong>.</p>
</div>
<?php pie();
