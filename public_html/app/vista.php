<?php

// Iconos de línea propios (24×24, trazo 1,8). Se pintan con el color del texto.
const ICONOS = [
    'fichar' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'historial' => '<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
    'correcciones' => '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
    'ausencias' => '<rect x="3.5" y="7" width="17" height="13" rx="2.5"/><path d="M9 7V5.5A2.5 2.5 0 0111.5 3h1A2.5 2.5 0 0115 5.5V7M3.5 12.5h17"/>',
    'calendario' => '<rect x="3.5" y="5" width="17" height="15" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    'documentos' => '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h6"/>',
    'comunicados' => '<path d="M4 10v4l11 5V5L4 10z"/><path d="M15 9a3 3 0 010 6M7 15l1 5"/>',
    'ofertas' => '<path d="M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.4 6.8 19.1l1-5.8L3.5 9.2l5.9-.9z"/>',
    'equipo' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.5 3.4-5 6.5-5s5.7 1.5 6.5 5"/><circle cx="17" cy="9" r="2.5"/><path d="M17 14c2.3 0 4 1.2 4.5 4"/>',
    'informes' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    'planificacion' => '<rect x="3.5" y="3.5" width="17" height="17" rx="3"/><path d="M3.5 9.5h17M9.5 3.5v17"/>',
    'necesidades' => '<path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5"/>',
    'nominas' => '<rect x="3.5" y="5" width="17" height="14" rx="2.5"/><path d="M3.5 9.5h17M7.5 14h4M7.5 16.5h2.5"/><circle cx="16" cy="15" r="1.6"/>',
    'enviar' => '<path d="M21 3L10 14"/><path d="M21 3l-7 18-4-7-7-4 18-7z"/>',
    'recibidos' => '<path d="M3 13l3-8h12l3 8v6H3z"/><path d="M3 13h5l1.5 2.5h5L16 13h5"/>',
    'admin' => '<path d="M4 21V7l8-4 8 4v14"/><path d="M9 21v-6h6v6M9 10h.01M15 10h.01"/>',
    'usuarios' => '<circle cx="10" cy="8" r="4"/><path d="M3 21c.9-4 3.8-6 7-6 1.5 0 2.9.4 4 1.2M18 15v6M15 18h6"/>',
    'horarios' => '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2M9 2h6"/>',
    'quioscos' => '<rect x="5" y="2.5" width="14" height="19" rx="3"/><path d="M11 18h2"/>',
    'auditoria' => '<path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6l8-3z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
    'avisos' => '<path d="M6 16V11a6 6 0 0112 0v5l2 2H4l2-2z"/><path d="M10 20a2 2 0 004 0"/>',
    'perfil' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4.2 4.2-6 8-6s7 1.8 8 6"/>',
    'salir' => '<path d="M14 4h4a2 2 0 012 2v12a2 2 0 01-2 2h-4M10 16l4-4-4-4M14 12H3"/>',
    'mas' => '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
];

function icono(string $n): string
{
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true">' . (ICONOS[$n] ?? ICONOS['mas']) . '</svg>';
}

// Menú por grupos según el rol y los permisos: [grupo => [página => texto]].
function menu_de(array $u): array
{
    $r = $u['rol'];
    $g = [];
    if ($r !== 'inspeccion') $g['Personal'] = ['fichar' => 'Fichar', 'historial' => 'Mi registro', 'ausencias' => 'Ausencias', 'calendario' => 'Calendario',
        'correcciones' => 'Correcciones', 'documentos' => 'Documentos', 'comunicados' => 'Anuncios', 'enviar' => 'Enviar a la empresa'];
    if ($r !== 'inspeccion' && ($na = count(anuncios_pendientes(db(), $u)))) $g['Personal']['comunicados'] = "Anuncios ($na)";
    $no = (int)consulta(db(), "SELECT COUNT(*) FROM ofertas_turno WHERE usuario_id = ? AND estado = 'pendiente' AND fecha >= ?", [$u['id'], local(ahora(), 'Y-m-d')])->fetchColumn();
    if ($no) $g['Personal']['ofertas'] = "Turnos ofrecidos ($no)";
    $e = [];
    if ($r !== 'empleado') $e['equipo'] = $r === 'responsable' ? 'Mi equipo' : 'Personas';
    if (in_array($r, ['responsable', 'admin'], true)) $e['recibidos'] = 'Recibidos' . (($nr = count(envios_recibidos(db(), $u, true))) ? " ($nr)" : '');
    if (puede($u, 'informes')) $e['informes'] = 'Informes';
    if (puede($u, 'planificar') && $r !== 'empleado') $e += ['planificacion' => 'Planificación', 'necesidades' => 'Necesidades'];
    $a = [];
    if (puede($u, 'documentos') && $r !== 'empleado') $e['nominas'] = 'Nóminas';
    if ($e) $g['Equipo'] = $e;
    if ($r === 'admin') $a = ['admin' => 'Empresa', 'usuarios' => 'Usuarios', 'horarios' => 'Horarios', 'quioscos' => 'Quioscos'];
    if (in_array($r, ['admin', 'inspeccion'], true)) $a['auditoria'] = 'Auditoría';
    if ($a) $g['Administración'] = $a;
    return $g;
}

// URL del logo de la empresa (con la fecha de subida para que el navegador no use uno viejo), o null.
function logo_empresa(): ?string
{
    try {
        $n = ajuste(db(), 'logo');
    } catch (Throwable) {
        return null; // sin BD todavía (asistente)
    }
    return $n ? '?p=logo&v=' . substr($n, 0, 8) : null;
}

function cabecera(string $titulo, ?array $u = null): void
{
    $logo = logo_empresa();
    $grupos = $u ? menu_de($u) : [];
    $actual = $_GET['p'] ?? '';
    $n = $u ? avisos_sin_leer(db(), (int)$u['id']) : 0;
    // Pestañas del móvil: las 4 primeras páginas del menú + «Más».
    $pestanas = array_slice(array_merge(...array_values($grupos ?: [[]])), 0, 4, true);
    $aviso = $_SESSION['aviso'] ?? null;
    unset($_SESSION['aviso']);
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f5f5f7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<link rel="manifest" href="assets/manifest.json">
<link rel="icon" href="assets/icono.svg">
<link rel="apple-touch-icon" href="assets/icono-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="INDA">
<link rel="stylesheet" href="assets/estilo.css">
<script>document.documentElement.classList.add('js')</script>
<title><?= e($titulo) ?> · INDA</title>
</head>
<body class="<?= $u ? 'con-menu' : 'sin-menu' ?>">
<?php if ($u): ?>
<aside class="lateral" id="menu" aria-label="Menú">
  <?php if ($logo): ?><a class="logo-empresa" href="?"><img src="<?= e($logo) ?>" alt="Logo de la empresa"></a><?php endif ?>
  <a class="logo" href="?"><img src="assets/icono.svg" alt=""> INDA</a>
  <?php foreach ($grupos as $g => $items): ?>
  <p class="grupo"><?= e($g) ?></p>
  <?php foreach ($items as $p => $txt): ?>
  <a href="?p=<?= $p ?>" class="<?= $actual === $p ? 'activo' : '' ?>"><?= icono($p) ?><span><?= e($txt) ?></span></a>
  <?php endforeach; endforeach ?>
  <div class="cuenta">
    <a href="?p=avisos" class="<?= $actual === 'avisos' ? 'activo' : '' ?>"><?= icono('avisos') ?><span>Avisos</span><?= $n ? '<b class="insignia">' . $n . '</b>' : '' ?></a>
    <a href="?p=perfil" class="yo <?= $actual === 'perfil' ? 'activo' : '' ?>"><?= avatar($u) ?><span><?= e(nombre($u)) ?><small><?= e(['empleado' => 'Empleado', 'responsable' => 'Responsable', 'admin' => 'Administración', 'inspeccion' => 'Inspección'][$u['rol']]) ?></small></span></a>
    <a href="?p=salir"><?= icono('salir') ?><span>Salir</span></a>
  </div>
</aside>
<div class="velo" data-cerrar-menu></div>
<header class="barra-movil">
  <span class="logo"><img src="assets/icono.svg" alt=""> INDA</span>
  <a href="?p=avisos" class="campana" aria-label="Avisos"><?= icono('avisos') ?><?= $n ? '<b class="insignia">' . $n . '</b>' : '' ?></a>
</header>
<nav class="pestanas" aria-label="Accesos rápidos">
  <?php foreach ($pestanas as $p => $txt): ?>
  <a href="?p=<?= $p ?>" class="<?= $actual === $p ? 'activo' : '' ?>"><?= icono($p) ?><span><?= e(explode(' ', $txt)[0] === 'Mi' ? $txt : explode(' ', $txt)[0]) ?></span></a>
  <?php endforeach ?>
  <button type="button" class="menu-boton" aria-expanded="false" aria-controls="menu"><?= icono('mas') ?><span>Más</span></button>
</nav>
<?php endif ?>
<main>
<?php if (!$u): ?>
<?php if ($logo): ?><p class="logo-entrada"><img src="<?= e($logo) ?>" alt="Logo de la empresa"></p><?php endif ?>
<p class="marca-grande<?= $logo ? ' pequena' : '' ?>"><img src="assets/icono.svg" alt=""> INDA</p>
<?php endif ?>
<?php if ($logo): ?><p class="solo-imprimir logo-impreso"><img src="<?= e($logo) ?>" alt=""></p><?php endif ?>
<h1><?= e($titulo) ?></h1>
<?php if ($aviso): ?><p class="aviso <?= e($aviso[1]) ?>" role="status"><?= e($aviso[0]) ?></p><?php endif ?>
<?php
}

// Botones válidos según el estado. La pausa se ofrece por motivo si hay motivos activos.
function botones_fichaje(string $estado): string
{
    $txt = ['entrada' => 'Entrar', 'pausa_inicio' => 'Empezar pausa', 'pausa_fin' => 'Volver de la pausa', 'salida' => 'Salir'];
    $html = '';
    foreach (PERMITIDOS[$estado] as $t) {
        $motivos = $t === 'pausa_inicio' ? db()->query('SELECT id, nombre FROM motivos_pausa WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        if (!$motivos) $html .= '<button class="grande ' . $t . '" name="tipo" value="' . $t . '">' . $txt[$t] . '</button>';
        if ($motivos) {
            $html .= '<p class="nota">Empezar pausa:</p><div class="pausas">';
            foreach ($motivos as $id => $m) $html .= '<button class="' . $t . '" name="tipo" value="' . $t . '|' . e($id) . '">' . e($m) . '</button>';
            $html .= '</div>';
        }
    }
    return $html;
}

function pie(): void
{
    echo "</main>\n<script src=\"assets/app.js\"></script>\n</body>\n</html>\n";
}

function opciones(array $lista, mixed $actual): string
{
    $html = '';
    foreach ($lista as $valor => $texto) {
        $html .= '<option value="' . e($valor) . '"' . ((string)$valor === (string)$actual ? ' selected' : '') . '>' . e($texto) . '</option>';
    }
    return $html;
}
