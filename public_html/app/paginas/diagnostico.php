<?php
// Diagnóstico (solo administración): versión, PHP, extensiones, base de datos, archivos y últimos errores.
$yo = requiere('admin');
if (es_post() && ($_POST['que'] ?? '') === 'vaciar') {
    @file_put_contents(archivo_errores(), '');
    auditar(db(), (int)$yo['id'], 'errores_vaciados');
    aviso('Registro de errores vaciado.');
    redirigir('diagnostico');
}
$migr = glob(__DIR__ . '/../migraciones/*.sql');
$hechas = db()->query('SELECT version FROM migraciones')->fetchAll(PDO::FETCH_COLUMN);
$faltan = array_values(array_diff(array_map(fn($f) => basename($f, '.sql'), $migr), $hechas));
$comprobar = [
    'Versión de INDA' => [true, VERSION],
    'PHP 8.1 o superior' => [version_compare(PHP_VERSION, '8.1', '>='), PHP_VERSION],
    'MySQL / MariaDB' => [true, db()->query('SELECT VERSION()')->fetchColumn()],
    'Base de datos al día' => [!$faltan, $faltan ? 'Faltan: ' . implode(', ', $faltan) : count($hechas) . ' migraciones aplicadas'],
    'Extensión PDO MySQL' => [extension_loaded('pdo_mysql'), ''],
    'Extensión ZIP (leer Excel)' => [class_exists('ZipArchive'), ''],
    'Extensión GD (fotos y colores del logo)' => [function_exists('imagecreatefromstring'), ''],
    'Extensión mbstring' => [function_exists('mb_strlen'), ''],
    'Extensión OpenSSL (avisos push)' => [function_exists('openssl_sign'), ''],
    'Carpeta de archivos escribible' => [is_writable(carpeta_archivos()), carpeta_archivos()],
    'Límite de subida' => [true, ini_get('upload_max_filesize') . ' (formulario ' . ini_get('post_max_size') . ')'],
    'Tiempo máximo por página' => [(int)ini_get('max_execution_time') === 0 || (int)ini_get('max_execution_time') >= 30, ini_get('max_execution_time') . ' s'],
    'Memoria' => [true, ini_get('memory_limit')],
    'Hora del servidor (Madrid)' => [true, local(ahora(), 'd/m/Y H:i:s')],
    'Último aviso automático (cron)' => [true, ($c = db()->query("SELECT MAX(creado_utc) FROM avisos")->fetchColumn()) ? local($c) : 'ninguno todavía'],
];
// Archivos clave con su fecha: si alguno es antiguo, la subida del ZIP no se completó.
$clave = ['index.php', 'app/vista.php', 'app/cuadrante.php', 'app/paginas/planificacion.php', 'app/paginas/calendario.php', 'assets/estilo.css', 'assets/app.js'];
$errores = errores_recientes(50);
cabecera('Diagnóstico', $yo);
?>
<p class="nota">Si algo no funciona, haz una captura de esta página y mándasela a quien te da soporte.</p>
<table>
  <?php foreach ($comprobar as $n => [$ok, $det]): ?>
  <tr><td><?= e($n) ?></td><td><?= $ok ? '<span class="pildora ok">Bien</span>' : '<span class="pildora mal">Revisar</span>' ?></td><td><?= e($det) ?></td></tr>
  <?php endforeach ?>
</table>
<h2>Archivos</h2>
<table>
  <tr><th>Archivo</th><th>Fecha</th></tr>
  <?php foreach ($clave as $f): $r = __DIR__ . '/../../' . $f; ?>
  <tr><td><code><?= e($f) ?></code></td><td><?= is_file($r) ? date('d/m/Y H:i', filemtime($r)) : '<span class="error">No está</span>' ?></td></tr>
  <?php endforeach ?>
</table>
<h2>Últimos errores (<?= count($errores) ?>)</h2>
<?php if (!$errores): ?><p class="aviso ok"><?= icono('ok') ?><span>No hay errores registrados.</span></p><?php else: ?>
<table class="informe-import">
  <tr><th>Fecha</th><th>Código</th><th>Página</th><th>Error</th><th>Dónde</th></tr>
  <?php foreach ($errores as $x): ?>
  <tr><td><?= local($x['fecha']) ?></td><td><code><?= e($x['codigo']) ?></code></td><td><?= e($x['pagina']) ?></td><td><?= e($x['mensaje']) ?><?php if ($x['traza']): ?><details><summary>Traza</summary><pre style="white-space:pre-wrap;font-size:.75rem"><?= e($x['traza']) ?></pre></details><?php endif ?></td><td><code><?= e($x['donde']) ?></code></td></tr>
  <?php endforeach ?>
</table>
<form method="post"><?= csrf_campo() ?><button name="que" value="vaciar" class="secundario" data-confirmar="¿Vaciar el registro de errores?">Vaciar el registro</button></form>
<?php endif;
pie();
