<?php
// Firma mensual del registro e informes por persona y periodo.

// Texto canónico del mes (fichajes válidos y anulados + totales). Su hash es lo que se firma.
function contenido_mes(PDO $db, array $u, string $mes): string
{
    $sig = (new DateTime("$mes-01"))->modify('+1 month')->format('Y-m-d');
    $lineas = ["INDA registro de jornada", "persona={$u['id']} dni=" . descifrar($u['dni']) . " mes=$mes"];
    foreach (fichajes_de($db, (int)$u['id'], a_utc("$mes-01"), a_utc($sig)) as $f) {
        $lineas[] = implode(';', [$f['id'], $f['momento_utc'], $f['tipo'], $f['origen'], $f['anulado'] ? 'anulado' : 'valido', $f['hash']]);
    }
    $t = resumen($db, $u, "$mes-01", (new DateTime($sig))->modify('-1 day')->format('Y-m-d'))['total'];
    $lineas[] = "trabajado={$t['trabajado']} teorico={$t['teorico']} extra={$t['extra']} nocturno={$t['nocturno']}";
    return implode("\n", $lineas);
}

function firma_mes(PDO $db, array $u, string $mes): ?array
{
    return consulta($db, 'SELECT * FROM firmas_mensuales WHERE usuario_id = ? AND mes = ? ORDER BY firmado_utc DESC LIMIT 1', [$u['id'], $mes])->fetch() ?: null;
}

function firmar_mes(PDO $db, array $u, string $mes, string $clave): string
{
    if (!password_verify($clave, $u['password_hash'])) throw new DomainException('La contraseña no es correcta.');
    if ($mes >= local(ahora(), 'Y-m')) throw new DomainException('Solo se firman meses ya terminados.');
    $hash = hash('sha256', contenido_mes($db, $u, $mes));
    consulta($db, 'INSERT IGNORE INTO firmas_mensuales VALUES (?, ?, ?, ?, ?)', [$u['id'], $mes, $hash, ahora(), ip()]);
    return $hash;
}

// Una fila por persona con los totales del periodo (segundos) y días de ausencia por tipo.
function informe(PDO $db, array $personas, string $desde, string $hasta): array
{
    $filas = [];
    foreach ($personas as $p) {
        $r = resumen($db, $p, $desde, $hasta);
        $aus = [];
        foreach ($r['dias'] as $d) if ($d['ausencia']) $aus[$d['ausencia']['tipo']] = ($aus[$d['ausencia']['tipo']] ?? 0) + 1;
        $abiertas = 0;
        foreach ($r['dias'] as $d) foreach ($d['jornadas'] as $j) $abiertas += $j['salida'] === null;
        $filas[] = ['persona' => $p, 'ausencias' => $aus, 'abiertas' => $abiertas,
            'dias_trabajados' => count(array_filter($r['dias'], fn($d) => $d['trabajado'] > 0))] + $r['total'] + extras_convenio($r['dias']);
    }
    return $filas;
}

// Retrasos sin justificar por mes, festivos y domingos trabajados, y turnos en Nochebuena/Navidad y Nochevieja/Año Nuevo.
function extras_convenio(array $dias): array
{
    $retrasos = [];
    $festivos = $domingos = $especiales = 0;
    foreach ($dias as $f => $d) {
        if (!$d['jornadas']) continue;
        $primera = strtotime($d['jornadas'][0]['entrada'] . ' UTC');
        $hora_ok = $d['entrada'] && !$d['flexible'] ? ts_local($f, $d['entrada']) + 60 * $d['margen'] : null;
        // Sin justificar: no hay ausencia (p. ej. visita médica por horas) ese día.
        if ($hora_ok && $primera > $hora_ok && !$d['ausencia']) $retrasos[substr($f, 0, 7)] = ($retrasos[substr($f, 0, 7)] ?? 0) + 1;
        if ($d['festivo']) $festivos++;
        if (date('N', strtotime($f)) == 7) $domingos++; // un turno de noche que empieza en domingo cuenta como domingo
        foreach ([[substr($f, 0, 4) . '-12-24 22:00', substr($f, 0, 4) . '-12-25 22:00'], [substr($f, 0, 4) . '-12-31 22:00', (substr($f, 0, 4) + 1) . '-01-01 22:00'], [(substr($f, 0, 4) - 1) . '-12-31 22:00', substr($f, 0, 4) . '-01-01 22:00']] as [$a, $b]) {
            [$a, $b] = [ts_local(substr($a, 0, 10), substr($a, 11)), ts_local(substr($b, 0, 10), substr($b, 11))];
            foreach ($d['jornadas'] as $j) foreach ($j['tramos'] as [$x, $y]) if ($x < $b && $y > $a) {
                $especiales++;
                continue 3;
            }
        }
    }
    return ['retrasos' => $retrasos, 'festivos_trabajados' => $festivos, 'domingos' => $domingos, 'turnos_especiales' => $especiales];
}

// Clasificación del convenio para el peor mes: '' si no llega a leve.
function nivel_retrasos(array $por_mes, array $r): string
{
    $max = $por_mes ? max($por_mes) : 0;
    return match (true) {
        $r['retrasos_muy_grave'] > 0 && $max >= $r['retrasos_muy_grave'] => 'muy grave',
        $r['retrasos_grave'] > 0 && $max >= $r['retrasos_grave'] => 'grave',
        $r['retrasos_leve'] > 0 && $max >= $r['retrasos_leve'] => 'leve',
        default => '',
    };
}

// Personas sobre las que $yo puede sacar informes.
function personas_visibles(PDO $db, array $yo): array
{
    return array_values(array_filter(equipo_de($db, $yo), fn($p) => puede_ver($yo, $p)));
}
