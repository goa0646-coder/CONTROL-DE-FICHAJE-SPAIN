<?php
// Vacaciones, permisos y bajas. Fechas locales (Y-m-d), horas locales (H:i).

const ESTADOS_AUSENCIA = ['pendiente' => 'Pendiente', 'aprobada' => 'Aprobada', 'rechazada' => 'Rechazada', 'cancelada' => 'Cancelada'];

function tipos_ausencia(PDO $db, bool $solo_activos = true): array
{
    return $db->query('SELECT id AS clave, tipos_ausencia.* FROM tipos_ausencia' . ($solo_activos ? ' WHERE activo = 1' : '') . ' ORDER BY nombre')->fetchAll(PDO::FETCH_UNIQUE);
}

// Ausencias aprobadas por día: ['tipo', 'color', 'retribuida', 'minutos' (null = día completo)].
function ausencias_por_dia(PDO $db, int $uid, string $desde, string $hasta): array
{
    $out = [];
    $s = consulta($db, "SELECT a.*, t.nombre, t.color, t.retribuida FROM ausencias a JOIN tipos_ausencia t ON t.id = a.tipo_id
        WHERE a.usuario_id = ? AND a.estado = 'aprobada' AND a.desde <= ? AND a.hasta >= ?", [$uid, $hasta, $desde]);
    foreach ($s as $a) {
        $min = $a['hora_desde'] ? intdiv(strtotime("2000-01-01 {$a['hora_hasta']}") - strtotime("2000-01-01 {$a['hora_desde']}"), 60) : null;
        foreach (rango_fechas(max($desde, $a['desde']), min($hasta, $a['hasta'])) as $f) {
            $prev = $out[$f]['minutos'] ?? 0;
            $out[$f] = ['id' => $a['id'], 'tipo' => $a['nombre'], 'color' => $a['color'], 'retribuida' => (bool)$a['retribuida'],
                'minutos' => ($min === null || $prev === null) ? null : $prev + $min];
        }
    }
    return $out;
}

// Días laborables de la persona en el rango (según su horario, festivos; sin horario: lunes a viernes).
function dias_laborables(PDO $db, array $u, string $desde, string $hasta, ?int $excluir_ausencia = null): int
{
    $n = 0;
    foreach (calendario_teorico($db, $u, $desde, $hasta) as $c) {
        if ($c['laborable'] && !$c['festivo'] && (!$c['ausencia'] || $c['ausencia']['id'] === $excluir_ausencia)) $n++;
    }
    return $n;
}

// Días consumidos/pedidos de un tipo en un año, recortando al año.
function dias_de_tipo(PDO $db, array $u, int $tipo, int $anio, array $estados): int
{
    $s = consulta($db, 'SELECT * FROM ausencias WHERE usuario_id = ? AND tipo_id = ? AND estado IN (' . implode(',', array_fill(0, count($estados), '?')) . ')
        AND desde <= ? AND hasta >= ?', [$u['id'], $tipo, ...$estados, "$anio-12-31", "$anio-01-01"]);
    $n = 0;
    $t = tipos_ausencia($db, false)[$tipo] ?? [];
    foreach ($s as $a) $n += dias_computables($db, $u, $t, max($a['desde'], "$anio-01-01"), min($a['hasta'], "$anio-12-31"), (int)$a['id']);
    return $n;
}

// Por tipo con límite: asignado, consumido (aprobado), comprometido (pendiente), disponible.
function saldos_ausencia(PDO $db, array $u, int $anio): array
{
    $propios = consulta($db, 'SELECT tipo_id, dias FROM saldos_ausencia WHERE usuario_id = ? AND anio = ?', [$u['id'], $anio])->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = [];
    foreach (tipos_ausencia($db) as $id => $t) {
        if ($t['dias_anuales'] === null && !isset($propios[$id])) continue;
        $asig = (float)($propios[$id] ?? $t['dias_anuales']);
        $cons = dias_de_tipo($db, $u, $id, $anio, ['aprobada']);
        $comp = dias_de_tipo($db, $u, $id, $anio, ['pendiente']);
        $out[$id] = ['tipo' => $t['nombre'], 'asignado' => $asig, 'consumido' => $cons, 'comprometido' => $comp, 'disponible' => $asig - $cons - $comp];
    }
    return $out;
}

function pedir_ausencia(PDO $db, array $u, array $d, int $pedida_por, ?string $justificante = null): int
{
    $tipos = tipos_ausencia($db);
    $t = $tipos[(int)($d['tipo_id'] ?? 0)] ?? throw new DomainException('Elige el tipo de ausencia.');
    $fecha = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d[$k] ?? '') && strtotime($d[$k]) ? $d[$k] : throw new DomainException('Revisa las fechas.');
    $desde = $fecha('desde');
    $hasta = $t['por_horas'] ? $desde : (($d['hasta'] ?? '') === '' ? $desde : $fecha('hasta'));
    if ($hasta < $desde) throw new DomainException('La fecha final no puede ser anterior a la inicial.');
    $hd = $hh = null;
    if ($t['por_horas']) {
        $hd = preg_match('/^\d{2}:\d{2}$/', $d['hora_desde'] ?? '') ? $d['hora_desde'] : throw new DomainException('Indica la hora de inicio.');
        $hh = preg_match('/^\d{2}:\d{2}$/', $d['hora_hasta'] ?? '') && $d['hora_hasta'] > $hd ? $d['hora_hasta'] : throw new DomainException('La hora final debe ser posterior a la inicial.');
    }
    $solape = consulta($db, "SELECT COUNT(*) FROM ausencias WHERE usuario_id = ? AND estado IN ('pendiente','aprobada') AND desde <= ? AND hasta >= ?
        AND (hora_desde IS NULL OR ? IS NULL OR (hora_desde < ? AND hora_hasta > ?))", [$u['id'], $hasta, $desde, $hd, $hh, $hd])->fetchColumn();
    if ($solape) throw new DomainException('Ya tienes una ausencia pedida o aprobada en esas fechas.');
    if (!$t['por_horas'] && ($saldo = saldos_ausencia($db, $u, (int)substr($desde, 0, 4))[$t['id']] ?? null)) {
        $pide = dias_computables($db, $u, $t, $desde, $hasta);
        if (substr($desde, 0, 4) === substr($hasta, 0, 4) && $pide > $saldo['disponible']) {
            throw new DomainException("No tienes días suficientes: pides $pide y te quedan " . (float)$saldo['disponible'] . '.');
        }
    }
    consulta($db, 'INSERT INTO ausencias (usuario_id, tipo_id, desde, hasta, hora_desde, hora_hasta, motivo, justificante, pedida_por, pedida_utc)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$u['id'], $t['id'], $desde, $hasta, $hd, $hh, mb_substr(trim($d['motivo'] ?? ''), 0, 500), $justificante, $pedida_por, ahora()]);
    return (int)$db->lastInsertId();
}

function ausencia(PDO $db, int $id): ?array
{
    return consulta($db, 'SELECT a.*, t.nombre AS tipo, t.color, t.por_horas, t.justificante AS pide_justificante, u.nombre, u.apellidos, u.responsable_id, u.departamento_id
        FROM ausencias a JOIN tipos_ausencia t ON t.id = a.tipo_id JOIN usuarios u ON u.id = a.usuario_id WHERE a.id = ?', [$id])->fetch() ?: null;
}

function resolver_ausencia(PDO $db, int $id, array $yo, bool $aprobar, string $comentario = ''): array
{
    $a = ausencia($db, $id);
    if (!$a || $a['estado'] !== 'pendiente') throw new DomainException('La solicitud ya no está pendiente.');
    if (!puede_aprobar($yo, ['id' => $a['usuario_id'], 'responsable_id' => $a['responsable_id']])) throw new DomainException('No puedes resolver esta solicitud.');
    $s = consulta($db, "UPDATE ausencias SET estado = ?, resuelta_por = ?, resuelta_utc = ?, comentario = ? WHERE id = ? AND estado = 'pendiente'",
        [$aprobar ? 'aprobada' : 'rechazada', $yo['id'], ahora(), mb_substr(trim($comentario), 0, 500), $id]);
    if (!$s->rowCount()) throw new DomainException('La solicitud ya no está pendiente.');
    return $a;
}

// La persona cancela lo pendiente, o lo aprobado que aún no ha empezado.
// Aviso si las vacaciones se aprueban con menos antelación de la que pide la ley (art. 38.3 ET: 2 meses).
function aviso_preaviso(PDO $db, array $a): ?string
{
    if ($a['tipo'] !== 'Vacaciones') return null;
    $dias = (int)((strtotime($a['desde']) - strtotime(local(ahora(), 'Y-m-d'))) / 86400);
    $min = (int)reglas($db)['preaviso_vacaciones_dias'];
    return $dias < $min ? "Empiezan en $dias días: la ley pide que se conozcan con $min días de antelación. Si las pide la propia persona, no hay problema." : null;
}

function cancelar_ausencia(PDO $db, int $id, array $u): array
{
    $a = ausencia($db, $id);
    $hoy = local(ahora(), 'Y-m-d');
    if (!$a || $a['usuario_id'] != $u['id'] || !($a['estado'] === 'pendiente' || ($a['estado'] === 'aprobada' && $a['desde'] > $hoy))) {
        throw new DomainException('Esta ausencia ya no se puede cancelar.');
    }
    consulta($db, "UPDATE ausencias SET estado = 'cancelada' WHERE id = ?", [$id]);
    return $a;
}

function ausencias_pendientes(PDO $db, array $yo): array
{
    $todas = $db->query("SELECT a.id FROM ausencias a WHERE a.estado = 'pendiente' ORDER BY a.pedida_utc")->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter(array_map(fn($id) => ausencia($db, (int)$id), $todas),
        fn($a) => puede_aprobar($yo, ['id' => $a['usuario_id'], 'responsable_id' => $a['responsable_id']])));
}

// Compañeros (mismo responsable o departamento) ausentes o pendientes en esas fechas.
function solapes(PDO $db, array $a): array
{
    return consulta($db, "SELECT u.nombre, u.apellidos, b.desde, b.hasta, b.estado, t.nombre AS tipo FROM ausencias b
        JOIN usuarios u ON u.id = b.usuario_id JOIN tipos_ausencia t ON t.id = b.tipo_id
        WHERE b.id <> ? AND b.usuario_id <> ? AND b.estado IN ('pendiente','aprobada') AND b.desde <= ? AND b.hasta >= ?
          AND ((u.responsable_id IS NOT NULL AND u.responsable_id = ?) OR (u.departamento_id IS NOT NULL AND u.departamento_id = ?))",
        [$a['id'], $a['usuario_id'], $a['hasta'], $a['desde'], $a['responsable_id'], $a['departamento_id']])->fetchAll();
}

function ausencias_de(PDO $db, int $uid, ?int $anio = null): array
{
    $sql = 'SELECT a.*, t.nombre AS tipo, t.color, t.justificante AS pide_justificante FROM ausencias a JOIN tipos_ausencia t ON t.id = a.tipo_id WHERE a.usuario_id = ?';
    $p = [$uid];
    if ($anio) {
        $sql .= ' AND a.desde <= ? AND a.hasta >= ?';
        array_push($p, "$anio-12-31", "$anio-01-01");
    }
    return consulta($db, $sql . ' ORDER BY a.desde DESC', $p)->fetchAll();
}

// Arrastra lo no disfrutado de $anio al año siguiente (asignado del siguiente = el suyo + sobrante).
function arrastrar_saldo(PDO $db, array $u, int $tipo, int $anio): float
{
    $s = saldos_ausencia($db, $u, $anio)[$tipo] ?? throw new DomainException('Ese tipo no tiene saldo.');
    $sobra = max(0, $s['asignado'] - $s['consumido']);
    $sig = saldos_ausencia($db, $u, $anio + 1)[$tipo]['asignado'];
    consulta($db, 'REPLACE INTO saldos_ausencia VALUES (?, ?, ?, ?)', [$u['id'], $tipo, $anio + 1, $sig + $sobra]);
    return $sobra;
}

// --- Archivos (justificantes y documentos): fuera de public_html, se sirven con permiso. ---

function carpeta_archivos(): string
{
    $dir = config()['archivos'] ?? dirname(__DIR__, 2) . '/inda-archivos';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    return $dir;
}

const EXTENSIONES = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'txt'];

// $f: una entrada de $_FILES. Devuelve el nombre guardado o null si no se subió nada.
function guardar_archivo(?array $f): ?string
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 10 * 1024 * 1024) throw new DomainException('El archivo no se pudo subir (máximo 10 MB).');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, EXTENSIONES, true)) throw new DomainException('Tipo de archivo no permitido.');
    $nombre = bin2hex(random_bytes(16)) . ".$ext";
    $destino = carpeta_archivos() . "/$nombre";
    if (!move_uploaded_file($f['tmp_name'], $destino)) {
        throw new DomainException('No se pudo guardar el archivo.');
    }
    return $nombre;
}

function enviar_archivo(string $nombre, string $descarga): never
{
    $ruta = carpeta_archivos() . '/' . basename($nombre);
    if (!preg_match('/^[a-f0-9]{32}\.[a-z]+$/', $nombre) || !is_file($ruta)) {
        http_response_code(404);
        exit('Archivo no encontrado.');
    }
    $tipos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    header('Content-Type: ' . ($tipos[pathinfo($nombre, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.\- ]/u', '_', $descarga) . '"');
    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
    exit;
}
