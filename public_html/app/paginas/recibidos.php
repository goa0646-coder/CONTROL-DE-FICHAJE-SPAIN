<?php
// Lo que la plantilla manda a la empresa. El admin ve todo; el responsable, lo de su equipo.
$yo = requiere('responsable', 'admin');
$todos = ($_GET['ver'] ?? '') === 'todos';
$q = trim($_GET['q'] ?? '');
$lista = envios_recibidos(db(), $yo, !$todos);
if ($q !== '') $lista = array_values(array_filter($lista, fn($e) => str_contains(normalizar($e['nombre'] . ' ' . $e['apellidos'] . ' ' . $e['asunto'] . ' ' . $e['texto']), normalizar($q))));
$abierto = isset($_GET['id']) ? envio(db(), (int)$_GET['id']) : null;
if ($abierto && !puede_ver_envio(db(), $abierto, $yo)) $abierto = null;
if ($abierto) {
    marcar_envio_visto(db(), $abierto, $yo);
    $abierto = envio(db(), (int)$abierto['id']);
}
$nuevos = count(envios_recibidos(db(), $yo, true));
cabecera('Recibidos', $yo);
?>
<div class="barra-acciones">
  <nav class="segmentos">
    <a href="?p=recibidos" class="<?= !$todos ? 'activo' : '' ?>">Sin revisar<?= $nuevos ? " ($nuevos)" : '' ?></a>
    <a href="?p=recibidos&ver=todos" class="<?= $todos ? 'activo' : '' ?>">Todos</a>
  </nav>
  <form class="buscar"><input type="hidden" name="p" value="recibidos"><?php if ($todos): ?><input type="hidden" name="ver" value="todos"><?php endif ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar…"></form>
</div>
<div class="dos-paneles<?= $abierto ? ' con-detalle' : '' ?>">
  <div class="lista-anuncios">
    <?php if (!$lista): ?><p class="nota tarjeta"><?= $todos ? 'Nadie ha enviado nada todavía.' : 'Todo revisado. No hay envíos nuevos.' ?></p><?php endif ?>
    <?php foreach ($lista as $e): ?>
    <a href="?p=recibidos<?= $todos ? '&ver=todos' : '' ?>&id=<?= e($e['id']) ?>" class="item con-avatar<?= $abierto && $abierto['id'] == $e['id'] ? ' activo' : '' ?><?= $e['visto_utc'] ? '' : ' nuevo' ?>">
      <?= avatar(['id' => $e['usuario_id']] + $e) ?>
      <span><strong><?= e(trim($e['nombre'] . ' ' . $e['apellidos'])) ?></strong>
      <small><?= local($e['creado_utc']) ?><?= $e['archivo'] ? ' · ' . icono('clip') : '' ?></small>
      <span class="resumen"><?= e($e['asunto']) ?></span></span>
    </a>
    <?php endforeach ?>
  </div>
  <?php if ($abierto): $de = usuario(db(), (int)$abierto['usuario_id']); $visto = $abierto['visto_por'] ? usuario(db(), (int)$abierto['visto_por']) : null; ?>
  <article class="tarjeta detalle-anuncio">
    <a class="volver" href="?p=recibidos<?= $todos ? '&ver=todos' : '' ?>">‹ Recibidos</a>
    <p class="quien"><?= avatar($de) ?> <span><strong><?= e(nombre($de)) ?></strong><small><?= e($de['email']) ?></small></span></p>
    <h2><?= e($abierto['asunto']) ?></h2>
    <p class="nota">Enviado el <?= local($abierto['creado_utc']) ?><?= $abierto['ip'] ? ' · IP ' . e($abierto['ip']) : '' ?></p>
    <?php if ($abierto['texto'] !== ''): ?><div class="texto-anuncio"><?= nl2br(e($abierto['texto'])) ?></div><?php endif ?>
    <?php if ($abierto['archivo']): ?><p><a class="adjunto" href="?p=archivo&e=<?= e($abierto['id']) ?>"><?= icono('documentos') ?> <?= e($abierto['nombre_original']) ?></a></p><?php endif ?>
    <p class="nota">Revisado<?= $visto ? ' por ' . e(nombre($visto)) . ' el ' . local($abierto['visto_utc']) : '' ?>.
      <a href="mailto:<?= e($de['email']) ?>?subject=<?= rawurlencode('Re: ' . $abierto['asunto']) ?>">Responder por email</a></p>
  </article>
  <?php endif ?>
</div>
<?php pie();
