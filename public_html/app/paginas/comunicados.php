<?php
// Anuncios: el que tiene permiso elige destinatarios; cada persona confirma la lectura (acuse con fecha e IP).
$yo = requiere('empleado', 'responsable', 'admin');
$puede = puede($yo, 'comunicados');
if (es_post()) {
    try {
        $que = $_POST['que'] ?? '';
        $c = isset($_POST['id']) ? anuncio(db(), (int)$_POST['id']) : null;
        if ($que === 'leido' && $c) {
            confirmar_lectura(db(), $c, $yo);
            aviso('Lectura confirmada. Gracias.');
            redirigir('comunicados', ['id' => $c['id']]);
        }
        if ($que === 'recordar' && $c && $puede && ($c['autor_id'] == $yo['id'] || $yo['rol'] === 'admin')) {
            $n = recordar_anuncio(db(), $c, $yo);
            aviso($n ? "Recordatorio enviado a $n " . ($n === 1 ? 'persona.' : 'personas.') : 'No hay nadie pendiente.');
            redirigir('comunicados', ['ver' => 'enviados', 'id' => $c['id']]);
        }
        if ($que === 'publicar' && $puede) {
            $uids = resolver_destinatarios(db(), $yo, (array)($_POST['para'] ?? []));
            $id = crear_anuncio(db(), $yo, $_POST['titulo'] ?? '', $_POST['texto'] ?? '', $uids, guardar_varios($_FILES['adjuntos'] ?? null));
            aviso('Anuncio enviado a ' . count($uids) . (count($uids) === 1 ? ' persona' : ' personas') . '. Les llega aviso en INDA y por email.');
            redirigir('comunicados', ['ver' => 'enviados', 'id' => $id]);
        }
        throw new DomainException('Acción no válida.');
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
        redirigir('comunicados', $puede && ($_POST['que'] ?? '') === 'publicar' ? ['nuevo' => 1] : []);
    }
}

$ver = $puede && ($_GET['ver'] ?? '') === 'enviados' ? 'enviados' : 'recibidos';
$nuevo = $puede && isset($_GET['nuevo']);
$lista = $ver === 'enviados' ? anuncios_enviados(db(), $yo) : array_values(array_filter(anuncios_para(db(), $yo), fn($c) => $c['autor_id'] != $yo['id']));
$abierto = isset($_GET['id']) ? anuncio(db(), (int)$_GET['id']) : null;
if ($abierto && !puede_ver_anuncio(db(), $abierto, $yo)) $abierto = null;
if (!$abierto && !$nuevo && $lista && !isset($_GET['id'])) $abierto = anuncio(db(), (int)$lista[0]['id']);
$autor = $abierto && $puede && ($abierto['autor_id'] == $yo['id'] || $yo['rol'] === 'admin');
$cuentas = [];
foreach ($ver === 'enviados' ? $lista : [] as $c) $cuentas[$c['id']] = [
    (int)consulta(db(), 'SELECT COUNT(*) FROM comunicados_leidos WHERE comunicado_id = ?', [$c['id']])->fetchColumn(), count(destinatarios_anuncio(db(), $c))];
$fecha = fn($utc) => local($utc, 'j') . ' ' . MESES[(int)local($utc, 'n')] . ' ' . local($utc, 'Y');
cabecera('Anuncios', $yo);
?>
<?php if ($puede): ?>
<div class="barra-acciones">
  <nav class="segmentos">
    <a href="?p=comunicados" class="<?= $ver === 'recibidos' && !$nuevo ? 'activo' : '' ?>">Recibidos</a>
    <a href="?p=comunicados&ver=enviados" class="<?= $ver === 'enviados' && !$nuevo ? 'activo' : '' ?>">Enviados</a>
  </nav>
  <a class="boton" href="?p=comunicados&nuevo=1">+ Nuevo anuncio</a>
</div>
<?php endif ?>

<?php if ($nuevo):
    $gente = personas_anunciables(db(), $yo);
    usort($gente, fn($a, $b) => strcoll(nombre($a), nombre($b)));
    $centros = db()->query('SELECT id, nombre FROM centros WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
    $deps = db()->query('SELECT id, nombre FROM departamentos WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
    $pts = puestos(db());
    $pp = puestos_por_persona(db()); ?>
<form method="post" enctype="multipart/form-data" class="tarjeta" data-anuncio>
  <?= csrf_campo() ?>
  <h2>Nuevo anuncio</h2>
  <label>Asunto <input name="titulo" required maxlength="150"></label>
  <label>Mensaje <textarea name="texto" rows="6" required></textarea></label>
  <label>Adjuntos (opcional, puedes elegir varios) <input type="file" name="adjuntos[]" multiple></label>
  <fieldset class="destinatarios">
    <legend>Para <b data-total>0</b> personas</legend>
    <div class="grupos-para">
      <label class="chip"><input type="checkbox" name="para[]" value="todos" data-grupo> <?= $yo['rol'] === 'admin' ? 'Toda la plantilla' : 'Todo mi equipo' ?></label>
      <?php foreach ([['centro', $centros, 'Centro'], ['dep', $deps, 'Departamento'], ['puesto', $pts, 'Puesto']] as [$t, $l, $txt]) foreach ($l as $id => $n): ?>
      <label class="chip"><input type="checkbox" name="para[]" value="<?= $t ?>:<?= e($id) ?>" data-grupo> <small><?= $txt ?></small> <?= e($n) ?></label>
      <?php endforeach ?>
    </div>
    <input type="search" placeholder="Buscar persona…" data-buscar-persona>
    <div class="lista-personas">
      <?php foreach ($gente as $p): ?>
      <label data-persona="<?= e(mb_strtolower(nombre($p))) ?>" data-centro="<?= e($p['centro_id']) ?>" data-dep="<?= e($p['departamento_id']) ?>" data-puestos="<?= e(implode(',', $pp[$p['id']] ?? [])) ?>">
        <input type="checkbox" name="para[]" value="persona:<?= e($p['id']) ?>"> <?= avatar($p, 'mini') ?> <?= e(nombre($p)) ?></label>
      <?php endforeach ?>
    </div>
  </fieldset>
  <p class="nota">Cada persona recibe un aviso en INDA y un email, y debe pulsar «Confirmo que lo he leído». Verás quién lo ha leído, cuándo y desde qué IP.</p>
  <button name="que" value="publicar">Enviar anuncio</button> <a class="boton secundario" href="?p=comunicados&ver=enviados">Cancelar</a>
</form>
<?php else: ?>
<div class="dos-paneles<?= isset($_GET['id']) ? ' con-detalle' : '' ?>">
  <div class="lista-anuncios">
    <?php if (!$lista): ?><p class="nota tarjeta"><?= $ver === 'enviados' ? 'Aún no has enviado anuncios.' : 'No tienes anuncios.' ?></p><?php endif ?>
    <?php foreach ($lista as $c): ?>
    <a href="?p=comunicados<?= $ver === 'enviados' ? '&ver=enviados' : '' ?>&id=<?= e($c['id']) ?>" class="item<?= $abierto && $abierto['id'] == $c['id'] ? ' activo' : '' ?><?= $ver === 'recibidos' && !$c['leido_utc'] ? ' nuevo' : '' ?>">
      <strong><?= e($c['titulo']) ?></strong>
      <small><?= e(trim($c['autor'] . ' ' . $c['autor_apellidos'])) ?> · <?= $fecha($c['creado_utc']) ?></small>
      <span class="resumen"><?= e(mb_strimwidth($c['texto'], 0, 110, '…')) ?></span>
      <?php if ($ver === 'enviados'): [$l, $t] = $cuentas[$c['id']]; ?>
      <span class="pildora<?= $l >= $t ? ' ok' : '' ?>">Leído <?= $l ?>/<?= $t ?></span>
      <?php else: ?><span class="pildora<?= $c['leido_utc'] ? ' ok' : ' pendiente' ?>"><?= $c['leido_utc'] ? 'Leído' : 'Pendiente de leer' ?></span><?php endif ?>
      <?php if ($c['con_adjunto']): ?><span class="clip" title="Con adjunto">📎</span><?php endif ?>
    </a>
    <?php endforeach ?>
  </div>
  <?php if ($abierto):
      $mio = consulta(db(), 'SELECT leido_utc, ip FROM comunicados_leidos WHERE comunicado_id = ? AND usuario_id = ?', [$abierto['id'], $yo['id']])->fetch();
      $para_mi = es_destinatario(db(), $abierto, $yo) && $abierto['autor_id'] != $yo['id']; ?>
  <article class="tarjeta detalle-anuncio">
    <a class="volver" href="?p=comunicados<?= $ver === 'enviados' ? '&ver=enviados' : '' ?>">‹ Anuncios</a>
    <h2><?= e($abierto['titulo']) ?></h2>
    <p class="nota"><?= e(trim($abierto['autor'] . ' ' . $abierto['autor_apellidos'])) ?> · <?= local($abierto['creado_utc']) ?></p>
    <div class="texto-anuncio"><?= nl2br(e($abierto['texto'])) ?></div>
    <?php if ($autor): $dest = array_filter(array_map(fn($id) => usuario(db(), $id), destinatarios_anuncio(db(), $abierto)));
        $nombres = array_map('nombre', $dest); ?>
    <details class="para"><summary>Para <?= count($nombres) ?> destinatarios: <?= e(mb_strimwidth(implode(', ', $nombres), 0, 110, '…')) ?> <span>Ver todos</span></summary>
      <p class="nota"><?= e(implode(', ', $nombres)) ?></p></details>
    <?php endif ?>
    <?php if ($adj = adjuntos_anuncio(db(), $abierto)): ?><p class="adjuntos"><?php foreach ($adj as $a): ?><a class="adjunto" href="?p=archivo&c=<?= e($abierto['id']) ?>&adj=<?= e($a['id']) ?>"><?= icono('documentos') ?> <?= e($a['nombre_original']) ?></a> <?php endforeach ?></p><?php endif ?>
    <?php if ($para_mi): ?>
      <?php if ($mio): ?><p class="aviso ok">Confirmaste la lectura el <?= local($mio['leido_utc']) ?>.</p>
      <?php else: ?><form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($abierto['id']) ?>"><button name="que" value="leido">Confirmo que lo he leído</button></form><?php endif ?>
    <?php endif ?>
    <?php if ($autor): $r = lecturas(db(), $abierto); $tot = count($r['leidos']) + count($r['pendientes']); ?>
    <div class="acuse">
      <div class="acuse-cab">
        <h3>Acuse de lectura: <?= count($r['leidos']) ?> de <?= $tot ?></h3>
        <span class="no-imprimir">
          <?php if ($r['pendientes']): ?><form method="post" style="display:inline"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($abierto['id']) ?>"><button name="que" value="recordar" class="secundario" data-confirmar="¿Volver a avisar a las <?= count($r['pendientes']) ?> personas que no lo han leído?">Recordar a <?= count($r['pendientes']) ?> pendientes</button></form><?php endif ?>
          <button type="button" class="secundario" onclick="print()">Imprimir</button>
        </span>
      </div>
      <div class="barra"><i style="width:<?= $tot ? round(100 * count($r['leidos']) / $tot) : 0 ?>%"></i></div>
      <?php if ($r['pendientes']): ?>
      <h4>Pendientes (<?= count($r['pendientes']) ?>)</h4>
      <ul class="personas"><?php foreach ($r['pendientes'] as $p): ?><li><?= avatar($p, 'mini') ?> <?= e(nombre($p)) ?></li><?php endforeach ?></ul>
      <?php endif ?>
      <?php if ($r['leidos']): ?>
      <h4>Leído (<?= count($r['leidos']) ?>)</h4>
      <table><tr><th>Persona</th><th>Confirmado</th><th>IP</th></tr>
        <?php foreach ($r['leidos'] as $l): ?><tr><td><span class="con-avatar"><?= avatar($l['usuario'], 'mini') ?> <?= e(nombre($l['usuario'])) ?></span></td><td><?= local($l['leido_utc']) ?></td><td><?= e($l['ip'] ?: '—') ?></td></tr><?php endforeach ?>
      </table>
      <?php endif ?>
    </div>
    <?php endif ?>
  </article>
  <?php endif ?>
</div>
<?php endif;
pie();
