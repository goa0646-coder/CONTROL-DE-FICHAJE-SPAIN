<?php
// Firma mensual del registro e informes por persona y periodo.

// Texto canónico del mes (fichajes válidos y anulados + totales). Su hash es lo que se firma.
function contenido_mes(PDO $db, array $u, string $mes): string
{
    $sig = (new DateTime("$mes-01"))->modify('+1 month')->format('Y-m-d');
    $lineas = ["INDA registro de jornada", "persona={$u['id']} dni={$u['dni']} mes=$mes"];
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
            'dias_trabajados' => count(array_filter($r['dias'], fn($d) => $d['trabajado'] > 0))] + $r['total'];
    }
    return $filas;
}

// Personas sobre las que $yo puede sacar informes.
function personas_visibles(PDO $db, array $yo): array
{
    return array_values(array_filter(equipo_de($db, $yo), fn($p) => puede_ver($yo, $p)));
}
