<?php

function cabecera(string $titulo, ?array $u = null): void
{
    $menu = [];
    if ($u) {
        $r = $u['rol'];
        if ($r !== 'inspeccion') $menu += ['fichar' => 'Fichar', 'historial' => 'Mi registro', 'correcciones' => 'Correcciones'];
        if ($r !== 'empleado') $menu['equipo'] = $r === 'responsable' ? 'Mi equipo' : 'Personas';
        if ($r === 'admin') $menu += ['admin' => 'Empresa', 'usuarios' => 'Usuarios', 'quioscos' => 'Quioscos'];
        if (in_array($r, ['admin', 'inspeccion'], true)) $menu['auditoria'] = 'Auditoría';
        $menu += ['clave' => 'Contraseña', 'salir' => 'Salir'];
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
<link rel="stylesheet" href="assets/estilo.css">
<title><?= e($titulo) ?> · Fichaje</title>
</head>
<body>
<?php if ($menu): ?>
<nav><?php foreach ($menu as $p => $txt): ?><a href="?p=<?= $p ?>"<?= ($_GET['p'] ?? '') === $p ? ' class="activo"' : '' ?>><?= e($txt) ?></a><?php endforeach ?></nav>
<?php endif ?>
<main>
<h1><?= e($titulo) ?></h1>
<?php if ($aviso): ?><p class="aviso <?= e($aviso[1]) ?>" role="status"><?= e($aviso[0]) ?></p><?php endif ?>
<?php
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
