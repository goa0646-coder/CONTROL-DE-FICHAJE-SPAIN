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
    $s = $db->prepare('SELECT f.*, NOT ' . NO_ANULADO . ' AS anulado, m.nombre AS motivo, m.retribuida
        FROM fichajes f LEFT JOIN motivos_pausa m ON m.id = f.motivo_id
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
        // Entrada como pronto 10 minutos antes de su turno (solo al fichar la persona; correcciones y sistema, no).
        if ($tipo === 'entrada' && in_array($origen, ['web', 'movil', 'qr', 'quiosco', 'chat', 'api'], true) && empty($u['sin_fichaje'])) {
            $hoy = local($ahora ?? gmdate('Y-m-d H:i:s'), 'Y-m-d');
            $c = calendario_teorico($db, $u, $hoy, $hoy)[$hoy];
            $t = strtotime(($ahora ?? gmdate('Y-m-d H:i:s')) . ' UTC');
            if ($c['turno'] && !$c['flexible'] && $c['entrada'] && !$c['ausencia'] && $t < ts_local($hoy, $c['entrada']) - 600 && $t > ts_local($hoy, $c['entrada']) - 4 * 3600) {
                throw new DomainException('Aún no puedes fichar la entrada: tu turno empieza a las ' . substr($c['entrada'], 0, 5) . ' y se puede fichar desde 10 minutos antes.');
            }
        }
        $f = ['usuario_id' => $u['id'], 'tipo' => $tipo, 'momento_utc' => $ahora ?? gmdate('Y-m-d H:i:s'), 'origen' => $origen,
            'centro_id' => $u['centro_id'], 'quiosco_id' => null, 'zona' => null, 'ip' => $d['ip'] ?? '', 'correccion_id' => null];
        if ($tipo === 'pausa_inicio' && !empty($d['motivo_id'])) {
            $m = $db->prepare('SELECT id FROM motivos_pausa WHERE id = ? AND activo = 1');
            $m->execute([(int)$d['motivo_id']]);
            $f['motivo_id'] = $m->fetchColumn() ?: throw new DomainException('Motivo de pausa no válido.');
        }
        $gps = null;
        if ($origen === 'automatico') {
            // Cierre automático de INDA: sin GPS ni IP (no lo hace la persona).
        } elseif ($origen === 'quiosco' || $origen === 'qr') {
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
            if ($c && $f['zona'] === 'fuera' && $c['fuera_zona'] === 'rechazar') throw new DomainException('Estás fuera de tu centro de trabajo: no se puede fichar desde aquí.');
            if ($c && !ip_permitida($c['ips'], $f['ip'])) throw new DomainException('No se puede fichar desde esta red. Usa la red del centro.');
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
    $partes = [$f['hash_anterior'] ?? '', $f['usuario_id'], $f['tipo'], $f['momento_utc'], $f['origen'], $f['zona'] ?? '', $f['correccion_id'] ?? '', $f['creado_utc']];
    // Solo si existe: así los fichajes anteriores a la fase 2 conservan su hash.
    if (!empty($f['motivo_id'])) $partes[] = 'm' . $f['motivo_id'];
    return hash('sha256', implode('|', $partes));
}

// $lista: prefijos separados por comas o espacios ("" = cualquier red). Ej.: "88.12.34.5, 10.0.".
function ip_permitida(string $lista, string $ip): bool
{
    $prefijos = preg_split('/[\s,]+/', trim($lista), -1, PREG_SPLIT_NO_EMPTY);
    if (!$prefijos) return true;
    foreach ($prefijos as $p) if ($ip !== '' && str_starts_with($ip, $p)) return true;
    return false;
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
// Las pausas retribuidas cuentan como trabajo. tramos = intervalos trabajados [inicio, fin] en segundos Unix.
function jornadas(array $fichajes): array
{
    $out = [];
    $j = null;
    $desde = $pausa = null;
    $pagada = false;
    $cerrar_pausa = function (int $t) use (&$j, &$pausa, &$pagada) {
        $j['pausas'] += $t - $pausa; // todas las pausas, pagadas o no (para la pausa obligatoria)
        if ($pagada) {
            $j['trabajado'] += $t - $pausa;
            $j['tramos'][] = [$pausa, $t];
        } else {
            $j['pausa'] += $t - $pausa;
        }
        $pausa = null;
    };
    foreach ($fichajes as $f) {
        if ($f['anulado']) continue;
        $t = strtotime($f['momento_utc'] . ' UTC');
        switch ($f['tipo']) {
            case 'entrada':
                if ($j) $out[] = $j;
                $j = ['entrada' => $f['momento_utc'], 'salida' => null, 'trabajado' => 0, 'pausa' => 0, 'pausas' => 0, 'tramos' => []];
                $desde = $t;
                $pausa = null;
                break;
            case 'pausa_inicio':
                if ($j && $desde) {
                    $j['trabajado'] += $t - $desde;
                    $j['tramos'][] = [$desde, $t];
                    [$desde, $pausa, $pagada] = [null, $t, !empty($f['retribuida'])];
                }
                break;
            case 'pausa_fin':
                if ($j && $pausa) {
                    $cerrar_pausa($t);
                    $desde = $t;
                }
                break;
            case 'salida':
                if (!$j) break;
                if ($desde) {
                    $j['trabajado'] += $t - $desde;
                    $j['tramos'][] = [$desde, $t];
                }
                if ($pausa) $cerrar_pausa($t);
                $j['salida'] = $f['momento_utc'];
                $out[] = $j;
                $j = null;
                break;
        }
    }
    // Jornada abierta: en_curso = desde cuándo se está trabajando ahora (null si está en pausa).
    if ($j) $out[] = $j + ['en_curso' => $desde];
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

// Pausas con motivo que pide justificante (médico, asunto personal…) y aún sin él. $uid = null: de todo el mundo.
// Plazo: 1 día desde el inicio de la pausa. Devuelve [id, usuario_id, momento_utc, motivo, vence_utc].
function pausas_sin_justificante(PDO $db, ?int $uid = null, ?string $desde_utc = null): array
{
    return consulta($db, "SELECT f.id, f.usuario_id, f.momento_utc, m.nombre AS motivo, DATE_ADD(f.momento_utc, INTERVAL 1 DAY) AS vence_utc
        FROM fichajes f JOIN motivos_pausa m ON m.id = f.motivo_id
        WHERE f.tipo = 'pausa_inicio' AND m.justificante = 1 AND " . NO_ANULADO . "
          AND NOT EXISTS (SELECT 1 FROM justificantes_pausa j WHERE j.fichaje_id = f.id)
          AND (? IS NULL OR f.usuario_id = ?) AND f.momento_utc >= ? ORDER BY f.momento_utc",
        [$uid, $uid, $desde_utc ?? gmdate('Y-m-d H:i:s', strtotime('-60 days'))])->fetchAll();
}

// $guardar: guarda el archivo subido y devuelve [nombre en disco, nombre original] (solo se llama si la pausa lo admite).
function justificar_pausa(PDO $db, array $u, int $fichaje, callable $guardar): void
{
    $p = array_values(array_filter(pausas_sin_justificante($db, (int)$u['id'], '2000-01-01'), fn($x) => $x['id'] == $fichaje))[0] ?? throw new DomainException('Esa pausa no necesita justificante o ya lo tiene.');
    [$nombre, $original] = $guardar();
    consulta($db, 'INSERT INTO justificantes_pausa (fichaje_id, usuario_id, archivo, nombre_original, subido_utc) VALUES (?, ?, ?, ?, ?)',
        [$fichaje, $u['id'], $nombre, mb_substr(basename($original), 0, 200), ahora()]);
    auditar($db, (int)$u['id'], 'justificante_pausa', "fichaje=$fichaje");
    avisar_aprobadores($db, $u, "justif_ok:$fichaje", nombre($u) . " ha subido el justificante de su pausa ({$p['motivo']}) del " . local($p['momento_utc'], 'd/m H:i') . '.', 'equipo');
}

// Cron: al día de la pausa sin justificante, aviso a la persona y a quien aprueba (una vez cada uno).
function revisar_justificantes(PDO $db, ?string $ahora = null): int
{
    $ahora ??= ahora();
    $n = 0;
    foreach (pausas_sin_justificante($db) as $p) {
        if ($p['vence_utc'] > $ahora) continue;
        $u = usuario($db, (int)$p['usuario_id']);
        if (!$u || !$u['activo']) continue;
        $cuando = local($p['momento_utc'], 'd/m H:i');
        notificar($db, (int)$u['id'], "justif:{$p['id']}", "Falta el justificante de tu pausa ({$p['motivo']}) del $cuando. Súbelo en Fichar.", 'fichar');
        foreach (aprobadores($db, $u) as $a) $n += (int)notificar($db, $a, "justif_falta:{$p['id']}", nombre($u) . " no ha subido el justificante de su pausa ({$p['motivo']}) del $cuando.", 'equipo');
    }
    return $n;
}

// Pausa automática de la ficha: en cada jornada de más de 6 h sin pausas fichadas se apunta la pausa (computa como trabajo,
// así no tiene que ficharla y se cumple el descanso del art. 34.4 ET).
function con_pausa_auto(array $jornadas, array $u): array
{
    $min = (int)($u['pausa_auto_min'] ?? 0);
    if (!$min) return $jornadas;
    foreach ($jornadas as &$j) {
        if ($j['salida'] && !$j['pausas'] && strtotime($j['salida'] . ' UTC') - strtotime($j['entrada'] . ' UTC') > 6 * 3600) {
            $j['pausas'] = $min * 60;
            $j['pausa_auto'] = $min * 60;
        }
    }
    return $jornadas;
}

// Fichaje automático: a quien lo tiene en su ficha, INDA le ficha la entrada y la salida a la hora de su turno
// (origen «automatico», visible en su registro). No se ficha en ausencias, festivos ni días sin turno.
function fichajes_automaticos(PDO $db, ?string $ahora = null): int
{
    $ahora ??= ahora();
    $t = strtotime("$ahora UTC");
    $n = 0;
    foreach ($db->query("SELECT * FROM usuarios WHERE activo = 1 AND fichaje_auto = 1 AND rol <> 'inspeccion'")->fetchAll() as $u) {
        $hoy = local($ahora, 'Y-m-d');
        foreach ([date('Y-m-d', strtotime("$hoy -1 day")), $hoy] as $dia) {
            $c = calendario_teorico($db, $u, $dia, $dia)[$dia];
            if (!$c['turno'] || $c['ausencia'] || $c['flexible'] || !$c['entrada'] || !$c['salida']) continue;
            $ini = ts_local($dia, $c['entrada']);
            $fin = ts_local($dia, $c['salida']);
            if ($fin <= $ini) $fin += 86400;
            if ($t < $ini) continue;
            $hay = consulta($db, "SELECT COUNT(*) FROM fichajes f WHERE usuario_id = ? AND tipo = 'entrada' AND momento_utc >= ? AND momento_utc < ? AND " . NO_ANULADO,
                [$u['id'], gmdate('Y-m-d H:i:s', $ini - 4 * 3600), gmdate('Y-m-d H:i:s', $fin)])->fetchColumn();
            $ultimo = (int)strtotime(consulta($db, 'SELECT MAX(momento_utc) FROM fichajes WHERE usuario_id = ?', [$u['id']])->fetchColumn() . ' UTC');
            try {
                if (!$hay && $t < $fin && estado_actual($db, (int)$u['id']) === 'fuera') {
                    registrar_fichaje($db, $u, 'entrada', 'automatico', [], gmdate('Y-m-d H:i:s', max($ini, $ultimo + 1)));
                    $n++;
                } elseif ($hay && $t >= $fin && estado_actual($db, (int)$u['id']) !== 'fuera') {
                    if (estado_actual($db, (int)$u['id']) === 'pausa') registrar_fichaje($db, $u, 'pausa_fin', 'automatico', [], gmdate('Y-m-d H:i:s', max($fin - 1, $ultimo + 1)));
                    registrar_fichaje($db, $u, 'salida', 'automatico', [], gmdate('Y-m-d H:i:s', max($fin, $ultimo + 2)));
                    $n++;
                }
            } catch (DomainException) {
            }
        }
    }
    return $n;
}
