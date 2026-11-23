<?php

const TIPOS = ['entrada' => 'Entrada', 'pausa_inicio' => 'Inicio pausa', 'pausa_fin' => 'Fin pausa', 'salida' => 'Salida'];
const PERMITIDOS = [
    'fuera' => ['entrada'],
    'dentro' => ['pausa_inicio', 'salida'],
    'pausa' => ['pausa_fin', 'salida'],
];
const NO_ANULADO = "NOT EXISTS (SELECT 1 FROM correcciones c WHERE c.fichaje_id = f.id AND c.estado = 'aprobada')";

function estado_actual(PDO $db, int $uid): string
{
    $s = $db->prepare('SELECT tipo FROM fichajes f WHERE usuario_id = ? AND ' . NO_ANULADO . ' ORDER BY momento_utc DESC, id DESC LIMIT 1');
    $s->execute([$uid]);
    return match ($s->fetchColumn()) {
        'entrada', 'pausa_fin' => 'dentro',
        'pausa_inicio' => 'pausa',
        default => 'fuera',
    };
}

// Todos los fichajes (también los anulados, marcados) ordenados por hora.
function fichajes_de(PDO $db, int $uid, string $desde = '1970-01-01', string $hasta = '9999-12-31'): array
{
    $s = $db->prepare('SELECT f.*, NOT ' . NO_ANULADO . ' AS anulado FROM fichajes f
        WHERE usuario_id = ? AND momento_utc >= ? AND momento_utc < ? ORDER BY momento_utc, id');
    $s->execute([$uid, $desde, $hasta]);
    return $s->fetchAll();
}

function registrar_fichaje(PDO $db, array $u, string $tipo, string $origen, array $d = [], ?string $ahora = null): int
{
    $db->beginTransaction();
    try {
        // Bloquea al usuario: dos clics a la vez no pueden saltarse la secuencia ni romper la cadena.
        $db->prepare('SELECT id FROM usuarios WHERE id = ? FOR UPDATE')->execute([$u['id']]);
        $estado = estado_actual($db, (int)$u['id']);
        if (!in_array($tipo, PERMITIDOS[$estado], true)) {
            throw new DomainException('Ese fichaje no es posible ahora (estás ' . ['fuera' => 'fuera', 'dentro' => 'trabajando', 'pausa' => 'en pausa'][$estado] . ').');
        }
        $f = ['usuario_id' => $u['id'], 'tipo' => $tipo, 'momento_utc' => $ahora ?? gmdate('Y-m-d H:i:s'), 'origen' => $origen,
            'centro_id' => $u['centro_id'], 'quiosco_id' => null, 'zona' => null, 'ip' => $d['ip'] ?? '', 'correccion_id' => null];
        $gps = null;
        if ($origen === 'quiosco') {
            $f['centro_id'] = $d['quiosco']['centro_id'];
            $f['quiosco_id'] = $d['quiosco']['id'];
        } else {
            $c = $u['centro_id'] ? centro($db, (int)$u['centro_id']) : null;
            $modo = $u['gps_modo'] ?? $c['gps_modo'] ?? 'no';
            $lat = filter_var($d['lat'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => -90, 'max_range' => 90]]);
            $lng = filter_var($d['lng'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => -180, 'max_range' => 180]]);
            $hay = $lat !== false && $lng !== false;
            if ($modo === 'obligatorio' && !$hay) throw new DomainException('Para fichar debes permitir la ubicación.');
            if ($modo !== 'no' && $hay) {
                $gps = [$lat, $lng, filter_var($d['precision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: null];
                if ($c && $c['lat'] !== null) $f['zona'] = distancia_m($lat, $lng, (float)$c['lat'], (float)$c['lng']) <= $c['radio_m'] ? 'dentro' : 'fuera';
            }
        }
        $id = insertar_fichaje($db, $f);
        if ($gps) $db->prepare('INSERT INTO fichajes_gps VALUES (?, ?, ?, ?)')->execute([$id, ...$gps]);
        $db->commit();
        return $id;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// Llamar dentro de una transacción con el usuario bloqueado.
function insertar_fichaje(PDO $db, array $f): int
{
    $s = $db->prepare('SELECT hash FROM fichajes WHERE usuario_id = ? ORDER BY id DESC LIMIT 1');
    $s->execute([$f['usuario_id']]);
    $f['hash_anterior'] = $s->fetchColumn() ?: null;
    $f['creado_utc'] = gmdate('Y-m-d H:i:s');
    $f['hash'] = hash_fichaje($f);
    $cols = array_keys($f);
    $db->prepare('INSERT INTO fichajes (' . implode(',', $cols) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')')
        ->execute(array_values($f));
    return (int)$db->lastInsertId();
}

function hash_fichaje(array $f): string
{
    return hash('sha256', implode('|', [$f['hash_anterior'] ?? '', $f['usuario_id'], $f['tipo'], $f['momento_utc'],
        $f['origen'], $f['zona'] ?? '', $f['correccion_id'] ?? '', $f['creado_utc']]));
}

// Devuelve el id del primer fichaje manipulado, o null si la cadena está intacta. $filas: un usuario, por id.
function verificar_cadena(array $filas): ?int
{
    $anterior = null;
    foreach ($filas as $f) {
        if ($f['hash_anterior'] !== $anterior || $f['hash'] !== hash_fichaje($f)) return (int)$f['id'];
        $anterior = $f['hash'];
    }
    return null;
}

function distancia_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
    return 12742000 * asin(sqrt($a));
}

// Agrupa fichajes válidos en jornadas (entrada → salida), aunque crucen la medianoche.
function jornadas(array $fichajes): array
{
    $out = [];
    $j = null;
    $desde = $pausa = null;
    foreach ($fichajes as $f) {
        if ($f['anulado']) continue;
        $t = strtotime($f['momento_utc'] . ' UTC');
        switch ($f['tipo']) {
            case 'entrada':
                if ($j) $out[] = $j;
                $j = ['entrada' => $f['momento_utc'], 'salida' => null, 'trabajado' => 0, 'pausa' => 0];
                $desde = $t;
                $pausa = null;
                break;
            case 'pausa_inicio':
                if ($j && $desde) [$j['trabajado'], $desde, $pausa] = [$j['trabajado'] + $t - $desde, null, $t];
                break;
            case 'pausa_fin':
                if ($j && $pausa) [$j['pausa'], $pausa, $desde] = [$j['pausa'] + $t - $pausa, null, $t];
                break;
            case 'salida':
                if (!$j) break;
                if ($desde) $j['trabajado'] += $t - $desde;
                if ($pausa) $j['pausa'] += $t - $pausa;
                $j['salida'] = $f['momento_utc'];
                $out[] = $j;
                $j = null;
                break;
        }
    }
    if ($j) $out[] = $j;
    return $out;
}

function horas(int $segundos): string
{
    return sprintf('%d:%02d', intdiv($segundos, 3600), intdiv($segundos % 3600, 60));
}

// RGPD: coordenadas fuera a los 12 meses; queda la zona (dentro/fuera) en el fichaje.
function purgar_gps(PDO $db, ?string $ahora = null): int
{
    $s = $db->prepare('DELETE g FROM fichajes_gps g JOIN fichajes f ON f.id = g.fichaje_id WHERE f.momento_utc < DATE_SUB(?, INTERVAL 12 MONTH)');
    $s->execute([$ahora ?? gmdate('Y-m-d H:i:s')]);
    return $s->rowCount();
}
