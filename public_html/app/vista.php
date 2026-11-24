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
    'estudio' => '<path d="M4 20V4M4 20h16"/><path d="M7 15l4-4 3 3 5-6"/><circle cx="19" cy="8" r="1.3"/>',
    'admin' => '<path d="M4 21V7l8-4 8 4v14"/><path d="M9 21v-6h6v6M9 10h.01M15 10h.01"/>',
    'usuarios' => '<circle cx="10" cy="8" r="4"/><path d="M3 21c.9-4 3.8-6 7-6 1.5 0 2.9.4 4 1.2M18 15v6M15 18h6"/>',
    'horarios' => '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2M9 2h6"/>',
    'quioscos' => '<rect x="5" y="2.5" width="14" height="19" rx="3"/><path d="M11 18h2"/>',
    'auditoria' => '<path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6l8-3z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
    'avisos' => '<path d="M6 16V11a6 6 0 0112 0v5l2 2H4l2-2z"/><path d="M10 20a2 2 0 004 0"/>',
    'perfil' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4.2 4.2-6 8-6s7 1.8 8 6"/>',
    'salir' => '<path d="M14 4h4a2 2 0 012 2v12a2 2 0 01-2 2h-4M10 16l4-4-4-4M14 12H3"/>',
    'flecha' => '<path d="M9 6l6 6-6 6"/>',
    'ok' => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16 9.8"/>',
    'alerta' => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/>',
    'clip' => '<path d="M20 11.5l-8.2 8.2a5 5 0 01-7-7L13 4.5a3.4 3.4 0 014.8 4.8l-8.3 8.2a1.7 1.7 0 01-2.4-2.4l7.6-7.6"/>',
    'panel' => '<rect x="3.5" y="3.5" width="7" height="8" rx="2"/><rect x="13.5" y="3.5" width="7" height="5" rx="2"/><rect x="13.5" y="11.5" width="7" height="9" rx="2"/><rect x="3.5" y="14.5" width="7" height="6" rx="2"/>',
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
    if (in_array($r, ['responsable', 'admin'], true)) $g['General'] = ['panel' => 'Inicio'];
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
    if ($r === 'admin') $e['estudio'] = 'Estudio de plantilla';
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

// Nombre de la empresa (o «INDA» antes de instalar).
function nombre_empresa(): string
{
    try {
        return db()->query('SELECT nombre FROM empresa WHERE id = 1')->fetchColumn() ?: 'INDA';
    } catch (Throwable) {
        return 'INDA';
    }
}

// «martes, 24 de noviembre»
function fecha_larga(?string $fecha = null): string
{
    $t = strtotime($fecha ?? local(ahora(), 'Y-m-d'));
    return mb_strtolower(DIAS[(int)date('N', $t)]) . ', ' . (int)date('j', $t) . ' de ' . MESES[(int)date('n', $t)];
}

function cabecera(string $titulo, ?array $u = null): void
{
    $logo = logo_empresa();
    $empresa = nombre_empresa();
    $grupos = $u ? menu_de($u) : [];
    $actual = $_GET['p'] ?? '';
    $n = $u ? avisos_sin_leer(db(), (int)$u['id']) : 0;
    // Pestañas del móvil: las 4 primeras páginas del menú + «Más».
    $pestanas = array_slice(array_merge(...array_values($grupos ?: [[]])), 0, 4, true);
    $grupo_actual = '';
    foreach ($grupos as $g => $items) if (isset($items[$actual])) $grupo_actual = $g;
    $aviso = $_SESSION['aviso'] ?? null;
    unset($_SESSION['aviso']);
    $acceso = !$u && $actual !== 'quiosco';
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0b0d12" media="(prefers-color-scheme: dark)">
<link rel="manifest" href="assets/manifest.json">
<link rel="icon" href="assets/icono.svg">
<link rel="apple-touch-icon" href="assets/icono-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="INDA">
<link rel="preload" href="assets/fuentes/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('assets/estilo.css')) ?>">
<style><?= css_marca() ?></style>
<script>document.documentElement.classList.add('js')</script>
<title><?= e($titulo) ?> · <?= e($empresa) ?></title>
</head>
<body class="<?= $u ? 'con-menu' : ($acceso ? 'sin-menu acceso' : 'sin-menu') ?> p-<?= e(preg_replace('/[^a-z]/', '', $actual) ?: 'inicio') ?>">
<?php if ($u): ?>
<aside class="lateral" id="menu" aria-label="Menú">
  <a class="marca-empresa" href="?">
    <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="<?= e($empresa) ?>"><?php else: ?><img class="icono-inda" src="assets/icono.svg" alt=""><span><?= e($empresa) ?></span><?php endif ?>
  </a>
  <nav>
  <?php foreach ($grupos as $g => $items): ?>
  <p class="grupo"><?= e($g) ?></p>
  <?php foreach ($items as $p => $txt): ?>
  <a href="?p=<?= $p ?>" class="<?= $actual === $p ? 'activo' : '' ?>"><?= icono($p) ?><span><?= e(preg_replace('/ \(\d+\)$/', '', $txt)) ?></span><?= preg_match('/\((\d+)\)$/', $txt, $m) ? '<b class="contador">' . $m[1] . '</b>' : '' ?></a>
  <?php endforeach; endforeach ?>
  </nav>
  <div class="cuenta">
    <a href="?p=perfil" class="yo <?= $actual === 'perfil' ? 'activo' : '' ?>"><?= avatar($u) ?><span><?= e(nombre($u)) ?><small><?= e(['empleado' => 'Empleado', 'responsable' => 'Responsable', 'admin' => 'Administración', 'inspeccion' => 'Inspección'][$u['rol']]) ?></small></span></a>
    <a href="?p=salir" class="salir" title="Salir"><?= icono('salir') ?><span>Salir</span></a>
  </div>
  <p class="hecho-con"><img src="assets/icono.svg" alt=""> INDA · versión <?= VERSION ?></p>
</aside>
<div class="velo" data-cerrar-menu></div>
<header class="barra-superior">
  <p class="migas"><?= $grupo_actual ? '<span>' . e($grupo_actual) . '</span>' . icono('flecha') : '' ?><strong><?= e($titulo) ?></strong></p>
  <p class="hoy"><?= e(ucfirst(fecha_larga())) ?></p>
  <a href="?p=avisos" class="campana <?= $actual === 'avisos' ? 'activo' : '' ?>" aria-label="Avisos"><?= icono('avisos') ?><?= $n ? '<b class="insignia">' . $n . '</b>' : '' ?></a>
  <a href="?p=perfil" class="yo-mini" aria-label="Mi perfil"><?= avatar($u) ?></a>
</header>
<header class="barra-movil">
  <a class="logo" href="?"><?php if ($logo): ?><img class="logo-empresa-movil" src="<?= e($logo) ?>" alt="<?= e($empresa) ?>"><?php else: ?><img src="assets/icono.svg" alt=""> <?= e($empresa) ?><?php endif ?></a>
  <a href="?p=avisos" class="campana" aria-label="Avisos"><?= icono('avisos') ?><?= $n ? '<b class="insignia">' . $n . '</b>' : '' ?></a>
</header>
<nav class="pestanas" aria-label="Accesos rápidos">
  <?php foreach ($pestanas as $p => $txt): ?>
  <a href="?p=<?= $p ?>" class="<?= $actual === $p ? 'activo' : '' ?>"><?= icono($p) ?><span><?= e(explode(' ', $txt)[0] === 'Mi' ? $txt : explode(' ', $txt)[0]) ?></span></a>
  <?php endforeach ?>
  <button type="button" class="menu-boton" aria-expanded="false" aria-controls="menu"><?= icono('mas') ?><span>Más</span></button>
</nav>
<?php elseif ($acceso): ?>
<aside class="acceso-marca">
  <div class="acceso-logo"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt="<?= e($empresa) ?>"><?php else: ?><img src="assets/icono.svg" alt="" class="icono-inda"><?php endif ?></div>
  <p class="acceso-empresa"><?= e($empresa) ?></p>
  <p class="acceso-lema">Portal del empleado</p>
  <ul class="acceso-puntos">
    <li><?= icono('fichar') ?> Ficha tu entrada y salida desde el móvil</li>
    <li><?= icono('calendario') ?> Consulta tus turnos y tu calendario</li>
    <li><?= icono('ausencias') ?> Pide vacaciones y permisos</li>
  </ul>
  <p class="hecho-con"><img src="assets/icono.svg" alt=""> INDA · registro de jornada</p>
</aside>
<?php endif ?>
<main>
<?php if ($logo && $u): ?><p class="solo-imprimir logo-impreso"><img src="<?= e($logo) ?>" alt=""></p><?php endif ?>
<?php if (!$u && !$acceso && $logo): ?><p class="logo-entrada"><img src="<?= e($logo) ?>" alt="<?= e($empresa) ?>"></p><?php endif ?>
<h1><?= e($titulo) ?></h1>
<?php if ($aviso): ?><p class="aviso <?= e($aviso[1]) ?>" role="status"><?= icono($aviso[1] === 'error' ? 'alerta' : 'ok') ?><span><?= e($aviso[0]) ?></span></p><?php endif ?>
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
    echo "</main>\n<script src=\"" . e(asset('assets/app.js')) . "\"></script>\n</body>\n</html>\n";
}

function opciones(array $lista, mixed $actual): string
{
    $html = '';
    foreach ($lista as $valor => $texto) {
        $html .= '<option value="' . e($valor) . '"' . ((string)$valor === (string)$actual ? ' selected' : '') . '>' . e($texto) . '</option>';
    }
    return $html;
}
