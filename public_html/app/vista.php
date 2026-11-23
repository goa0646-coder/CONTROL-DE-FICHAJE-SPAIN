<?php

function cabecera(string $titulo, ?array $u = null): void
{
    $menu = [];
    if ($u) {
        $r = $u['rol'];
        if ($r !== 'inspeccion') $menu += ['fichar' => 'Fichar', 'historial' => 'Mi registro', 'correcciones' => 'Correcciones',
            'ausencias' => 'Ausencias', 'calendario' => 'Calendario', 'documentos' => 'Documentos', 'comunicados' => 'Comunicados'];
        if ($r !== 'empleado') $menu['equipo'] = $r === 'responsable' ? 'Mi equipo' : 'Personas';
        if (puede($u, 'informes')) $menu['informes'] = 'Informes';
        if (puede($u, 'planificar') && $r !== 'empleado') $menu += ['planificacion' => 'Planificación', 'necesidades' => 'Necesidades'];
        if ($r === 'admin') $menu += ['admin' => 'Empresa', 'usuarios' => 'Usuarios', 'horarios' => 'Horarios', 'quioscos' => 'Quioscos'];
        if (in_array($r, ['admin', 'inspeccion'], true)) $menu['auditoria'] = 'Auditoría';
        $n = avisos_sin_leer(db(), (int)$u['id']);
        $menu += ['avisos' => 'Avisos' . ($n ? " ($n)" : ''), 'perfil' => 'Perfil', 'salir' => 'Salir'];
    }
    $aviso = $_SESSION['aviso'] ?? null;
    unset($_SESSION['aviso']);
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1d4ed8">
<link rel="manifest" href="assets/manifest.json">
<link rel="icon" href="assets/icono.svg">
<link rel="apple-touch-icon" href="assets/icono-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="INDA">
<link rel="stylesheet" href="assets/estilo.css">
<script>document.documentElement.classList.add('js')</script>
<title><?= e($titulo) ?> · INDA</title>
</head>
<body>
<?php if ($menu): ?>
<nav><span class="marca">INDA</span><button type="button" class="menu-boton" aria-expanded="false" aria-controls="menu">☰ Menú</button><span id="menu"><?php foreach ($menu as $p => $txt): ?><a href="?p=<?= $p ?>"<?= ($_GET['p'] ?? '') === $p ? ' class="activo"' : '' ?>><?= e($txt) ?></a><?php endforeach ?></span></nav>
<?php endif ?>
<main>
<?php if (!$menu): ?><p class="marca-grande">INDA</p><?php endif ?>
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
