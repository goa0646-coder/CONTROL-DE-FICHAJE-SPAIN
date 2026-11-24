<?php
// Imagen de la empresa: colores sacados del logo (o elegidos en Empresa) que tiñen toda la aplicación.

const MARCA_POR_DEFECTO = ['primario' => '#2563eb', 'secundario' => '#e11d48'];

// Los dos colores con más presencia del logo (sin contar blancos, negros ni grises), de tonos distintos.
function colores_logo(string $ruta): ?array
{
    if (!function_exists('imagecreatefromstring') || !($img = @imagecreatefromstring((string)file_get_contents($ruta)))) return null;
    $p = imagecreatetruecolor(64, 64);
    imagealphablending($p, false);
    imagecopyresampled($p, $img, 0, 0, 0, 0, 64, 64, imagesx($img), imagesy($img));
    $cubos = [];
    for ($x = 0; $x < 64; $x++) for ($y = 0; $y < 64; $y++) {
        $c = imagecolorat($p, $x, $y);
        if ((($c >> 24) & 0x7F) > 60) continue; // transparente
        [$r, $g, $b] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
        [$h, $s, $l] = rgb_hsl($r, $g, $b);
        if ($s < 0.35 || $l < 0.18 || $l > 0.85) continue;
        $k = (int)floor($h / 20);
        $cubos[$k]['n'] = ($cubos[$k]['n'] ?? 0) + 1;
        $cubos[$k]['rgb'][] = [$r, $g, $b];
    }
    if (!$cubos) return null;
    uasort($cubos, fn($a, $b) => $b['n'] <=> $a['n']);
    $hex = function (array $l): string {
        $m = array_map(fn($i) => (int)round(array_sum(array_column($l, $i)) / count($l)), [0, 1, 2]);
        return sprintf('#%02x%02x%02x', ...$m);
    };
    $orden = array_keys($cubos);
    $prim = $hex($cubos[$orden[0]]['rgb']);
    // El segundo, de un tono alejado del primero (al menos 3 cubos = 60°).
    $sec = null;
    foreach (array_slice($orden, 1) as $k) if (min(abs($k - $orden[0]), 18 - abs($k - $orden[0])) >= 3 && $cubos[$k]['n'] >= 8) { $sec = $hex($cubos[$k]['rgb']); break; }
    // El rojo se reserva para errores y «Salir»: si el logo es sobre todo rojo, el principal es el segundo color.
    $rojo = fn($c) => ($h = rgb_hsl(...sscanf($c, '#%02x%02x%02x'))[0]) < 15 || $h > 340;
    if ($sec && $rojo($prim) && !$rojo($sec)) [$prim, $sec] = [$sec, $prim];
    return ['primario' => $prim, 'secundario' => $sec ?? $prim];
}

function rgb_hsl(int $r, int $g, int $b): array
{
    [$r, $g, $b] = [$r / 255, $g / 255, $b / 255];
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max === $min) return [0, 0, $l];
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    $h = match ($max) { $r => ($g - $b) / $d + ($g < $b ? 6 : 0), $g => ($b - $r) / $d + 2, default => ($r - $g) / $d + 4 };
    return [$h * 60, $s, $l];
}

// Luminancia relativa (WCAG) de un color #rrggbb.
function luminancia(string $hex): float
{
    $c = array_map(fn($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, sscanf($hex, '#%02x%02x%02x'));
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function marca(): array
{
    static $m;
    if ($m) return $m;
    try {
        $m = (json_decode(ajuste(db(), 'marca') ?? '', true) ?: []) + MARCA_POR_DEFECTO;
    } catch (Throwable) {
        $m = MARCA_POR_DEFECTO; // sin BD todavía (asistente)
    }
    return $m;
}

// Variables CSS de la marca. El texto sobre el color principal es blanco u oscuro según el contraste.
function css_marca(): string
{
    $m = marca();
    $ok = fn($c) => preg_match('/^#[0-9a-f]{6}$/i', $c) ? $c : '#2563eb';
    [$p, $s] = [$ok($m['primario']), $ok($m['secundario'])];
    $sobre = luminancia($p) > 0.45 ? '#111827' : '#ffffff';
    return ":root{--marca:$p;--marca-2:$s;--sobre-marca:$sobre}";
}
