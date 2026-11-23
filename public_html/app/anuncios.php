<?php
// Anuncios con destinatarios elegidos y acuse de lectura, envíos del trabajador a la empresa y fotos de perfil.

// ---------- Anuncios ----------

// Personas a las que $yo puede mandar anuncios (admin: todas; responsable: su equipo).
function personas_anunciables(PDO $db, array $yo): array
{
    return array_values(array_filter(equipo_de($db, $yo), fn($p) => $p['activo'] && $p['rol'] !== 'inspeccion'));
}

// Convierte la selección del formulario (todos, centro:3, dep:2, puesto:5, persona:9) en ids de usuario.
function resolver_destinatarios(PDO $db, array $yo, array $sel): array
{
    $pp = puestos_por_persona($db);
    $ids = [];
    foreach (personas_anunciables($db, $yo) as $p) {
        foreach ($sel as $s) {
            [$tipo, $v] = array_pad(explode(':', (string)$s, 2), 2, '');
            $v = (int)$v;
            if ($tipo === 'todos' || ($tipo === 'centro' && $p['centro_id'] == $v) || ($tipo === 'dep' && $p['departamento_id'] == $v)
                || ($tipo === 'puesto' && in_array($v, $pp[$p['id']] ?? [])) || ($tipo === 'persona' && $p['id'] == $v)) {
                $ids[(int)$p['id']] = true;
                break;
            }
        }
    }
    return array_keys($ids);
}

// $adjuntos: [[archivo guardado, nombre original], …].
function crear_anuncio(PDO $db, array $yo, string $titulo, string $texto, array $uids, array $adjuntos = []): int
{
    $titulo = trim($titulo);
    $texto = trim($texto);
    if ($titulo === '' || $texto === '') throw new DomainException('Pon título y texto.');
    if (!$uids) throw new DomainException('Elige al menos un destinatario.');
    $db->beginTransaction();
    consulta($db, 'INSERT INTO comunicados (titulo, texto, centro_id, autor_id, creado_utc, por_personas) VALUES (?, ?, NULL, ?, ?, 1)',
        [mb_substr($titulo, 0, 150), $texto, $yo['id'], ahora()]);
    $id = (int)$db->lastInsertId();
    foreach ($adjuntos as [$archivo, $original]) consulta($db, 'INSERT INTO comunicados_adjuntos (comunicado_id, archivo, nombre_original) VALUES (?, ?, ?)', [$id, $archivo, mb_substr($original, 0, 200)]);
    $s = $db->prepare('INSERT IGNORE INTO comunicados_para (comunicado_id, usuario_id) VALUES (?, ?)');
    foreach ($uids as $u) $s->execute([$id, (int)$u]);
    $db->commit();
    foreach ($uids as $u) if ($u != $yo['id']) notificar($db, (int)$u, "comunicado:$id", "Anuncio: $titulo. Ábrelo en INDA y confirma que lo has leído.", "comunicados&id=$id");
    auditar($db, (int)$yo['id'], 'comunicado_publicado', "comunicado=$id destinatarios=" . count($uids));
    return $id;
}

function anuncio(PDO $db, int $id): ?array
{
    return consulta($db, 'SELECT c.*, a.nombre AS autor, a.apellidos AS autor_apellidos FROM comunicados c JOIN usuarios a ON a.id = c.autor_id WHERE c.id = ?', [$id])->fetch() ?: null;
}

// Adjuntos: [[id, archivo, nombre_original]]. Los anuncios antiguos tenían uno solo (id 0).
function adjuntos_anuncio(PDO $db, array $c): array
{
    $l = consulta($db, 'SELECT id, archivo, nombre_original FROM comunicados_adjuntos WHERE comunicado_id = ? ORDER BY id', [$c['id']])->fetchAll();
    if ($c['archivo']) array_unshift($l, ['id' => 0, 'archivo' => $c['archivo'], 'nombre_original' => $c['nombre_original'] ?: 'adjunto']);
    return $l;
}

// Varios archivos de un <input type="file" multiple name="x[]">: los guarda y devuelve [[archivo, nombre]].
function guardar_varios(?array $f): array
{
    if (!$f || !is_array($f['name'] ?? null)) return [];
    $out = [];
    foreach (array_keys($f['name']) as $i) {
        $uno = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        if ($a = guardar_archivo($uno)) $out[] = [$a, basename($uno['name'])];
    }
    return $out;
}

// Ids de los destinatarios (anuncios nuevos: lista guardada; antiguos: su centro o toda la plantilla).
function destinatarios_anuncio(PDO $db, array $c): array
{
    if ($c['por_personas']) return array_map('intval', consulta($db, 'SELECT usuario_id FROM comunicados_para WHERE comunicado_id = ?', [$c['id']])->fetchAll(PDO::FETCH_COLUMN));
    return array_map('intval', destinatarios($db, $c['centro_id'] ? (int)$c['centro_id'] : null));
}

function es_destinatario(PDO $db, array $c, array $u): bool
{
    if ($c['por_personas']) return (bool)consulta($db, 'SELECT 1 FROM comunicados_para WHERE comunicado_id = ? AND usuario_id = ?', [$c['id'], $u['id']])->fetchColumn();
    return $u['rol'] !== 'inspeccion' && ($c['centro_id'] === null || $c['centro_id'] == $u['centro_id']);
}

// El autor, el admin y los destinatarios pueden abrirlo.
function puede_ver_anuncio(PDO $db, array $c, array $u): bool
{
    return $c['autor_id'] == $u['id'] || $u['rol'] === 'admin' || es_destinatario($db, $c, $u);
}

// Anuncios que ha recibido $u, con su fecha de lectura (NULL = pendiente).
function anuncios_para(PDO $db, array $u, int $limite = 100): array
{
    return consulta($db, 'SELECT c.*, a.nombre AS autor, a.apellidos AS autor_apellidos, l.leido_utc, (c.archivo IS NOT NULL OR EXISTS (SELECT 1 FROM comunicados_adjuntos x WHERE x.comunicado_id = c.id)) AS con_adjunto FROM comunicados c JOIN usuarios a ON a.id = c.autor_id
        LEFT JOIN comunicados_leidos l ON l.comunicado_id = c.id AND l.usuario_id = ?
        WHERE (c.por_personas = 0 AND (c.centro_id IS NULL OR c.centro_id = ?))
           OR EXISTS (SELECT 1 FROM comunicados_para p WHERE p.comunicado_id = c.id AND p.usuario_id = ?)
        ORDER BY c.id DESC LIMIT ' . $limite, [$u['id'], $u['centro_id'], $u['id']])->fetchAll();
}

function anuncios_pendientes(PDO $db, array $u): array
{
    if ($u['rol'] === 'inspeccion') return [];
    return array_values(array_filter(anuncios_para($db, $u, 50), fn($c) => !$c['leido_utc'] && $c['autor_id'] != $u['id']));
}

// Enviados por $yo (el admin ve todos).
function anuncios_enviados(PDO $db, array $yo): array
{
    return consulta($db, 'SELECT c.*, a.nombre AS autor, a.apellidos AS autor_apellidos, (c.archivo IS NOT NULL OR EXISTS (SELECT 1 FROM comunicados_adjuntos x WHERE x.comunicado_id = c.id)) AS con_adjunto FROM comunicados c JOIN usuarios a ON a.id = c.autor_id'
        . ($yo['rol'] === 'admin' ? '' : ' WHERE c.autor_id = ' . (int)$yo['id']) . ' ORDER BY c.id DESC LIMIT 200')->fetchAll();
}

// Acuse de recibo: fecha y IP. Solo cuenta la primera vez.
function confirmar_lectura(PDO $db, array $c, array $u): void
{
    if (!es_destinatario($db, $c, $u)) throw new DomainException('Este anuncio no es para ti.');
    $s = consulta($db, 'INSERT IGNORE INTO comunicados_leidos (comunicado_id, usuario_id, leido_utc, ip) VALUES (?, ?, ?, ?)', [$c['id'], $u['id'], ahora(), ip()]);
    if ($s->rowCount()) auditar($db, (int)$u['id'], 'comunicado_leido', "comunicado={$c['id']}");
}

// [leídos => [[usuario, leido_utc, ip]], pendientes => [usuario]].
function lecturas(PDO $db, array $c): array
{
    $leidos = consulta($db, 'SELECT usuario_id, leido_utc, ip FROM comunicados_leidos WHERE comunicado_id = ? ORDER BY leido_utc', [$c['id']])->fetchAll(PDO::FETCH_UNIQUE);
    $r = ['leidos' => [], 'pendientes' => []];
    foreach (destinatarios_anuncio($db, $c) as $id) {
        if (!$u = usuario($db, $id)) continue;
        if (isset($leidos[$id])) $r['leidos'][] = ['usuario' => $u] + $leidos[$id];
        elseif ($u['activo']) $r['pendientes'][] = $u;
    }
    usort($r['pendientes'], fn($a, $b) => strcoll(nombre($a), nombre($b)));
    return $r;
}

// Vuelve a avisar (app + email) a quien no lo ha leído. Devuelve a cuántos.
function recordar_anuncio(PDO $db, array $c, array $yo): int
{
    $n = 0;
    $vez = date('YmdHi');
    foreach (lecturas($db, $c)['pendientes'] as $u) {
        $n += notificar($db, (int)$u['id'], "comunicado:{$c['id']}:recordatorio:$vez", "Recordatorio: tienes pendiente de leer el anuncio «{$c['titulo']}».", "comunicados&id={$c['id']}");
    }
    auditar($db, (int)$yo['id'], 'comunicado_recordado', "comunicado={$c['id']} personas=$n");
    return $n;
}

// ---------- Envíos del trabajador a la empresa ----------

// Quién recibe lo que manda $u: su responsable y los administradores.
function receptores_envio(PDO $db, array $u): array
{
    $ids = array_map('intval', $db->query("SELECT id FROM usuarios WHERE rol = 'admin' AND activo = 1")->fetchAll(PDO::FETCH_COLUMN));
    if ($u['responsable_id']) $ids[] = (int)$u['responsable_id'];
    return array_values(array_diff(array_unique($ids), [(int)$u['id']]));
}

function enviar_a_empresa(PDO $db, array $u, string $asunto, string $texto, ?string $archivo = null, ?string $original = null): int
{
    $asunto = trim($asunto);
    $texto = trim($texto);
    if ($asunto === '') throw new DomainException('Pon un asunto.');
    if ($texto === '' && !$archivo) throw new DomainException('Escribe algo o adjunta un archivo.');
    consulta($db, 'INSERT INTO envios (usuario_id, asunto, texto, archivo, nombre_original, creado_utc, ip) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$u['id'], mb_substr($asunto, 0, 150), $texto, $archivo, $original ? mb_substr($original, 0, 200) : null, ahora(), ip()]);
    $id = (int)$db->lastInsertId();
    $txt = nombre($u) . " te ha enviado: $asunto" . ($archivo ? ' (con archivo adjunto)' : '') . '.';
    foreach (receptores_envio($db, $u) as $r) notificar($db, $r, "envio:$id", $txt, "recibidos&id=$id");
    avisar_administracion('INDA: ' . mb_strimwidth($txt, 0, 70, '…'),
        "$txt\n\n" . ($texto !== '' ? "$texto\n\n" : '') . rtrim(config()['url'] ?? '', '/') . "/?p=recibidos&id=$id");
    auditar($db, (int)$u['id'], 'envio_empresa', "envio=$id");
    return $id;
}

function envio(PDO $db, int $id): ?array
{
    return consulta($db, 'SELECT * FROM envios WHERE id = ?', [$id])->fetch() ?: null;
}

// El que lo mandó, el admin y su responsable.
function puede_ver_envio(PDO $db, array $e, array $yo): bool
{
    if ($e['usuario_id'] == $yo['id'] || $yo['rol'] === 'admin') return true;
    return $yo['rol'] === 'responsable' && ($dueno = usuario($db, (int)$e['usuario_id'])) && $dueno['responsable_id'] == $yo['id'];
}

function envios_recibidos(PDO $db, array $yo, bool $solo_nuevos = false): array
{
    $donde = $yo['rol'] === 'admin' ? '1' : 'u.responsable_id = ' . (int)$yo['id'];
    return $db->query("SELECT e.*, u.nombre, u.apellidos, u.foto FROM envios e JOIN usuarios u ON u.id = e.usuario_id WHERE $donde"
        . ($solo_nuevos ? ' AND e.visto_utc IS NULL' : '') . ' ORDER BY e.id DESC LIMIT 300')->fetchAll();
}

function marcar_envio_visto(PDO $db, array $e, array $yo): void
{
    if ($e['visto_utc'] || $e['usuario_id'] == $yo['id']) return;
    consulta($db, 'UPDATE envios SET visto_utc = ?, visto_por = ? WHERE id = ? AND visto_utc IS NULL', [ahora(), $yo['id'], $e['id']]);
}

// ---------- Fotos de perfil ----------

// Guarda la foto recortada en cuadrado de 400 px (sin datos EXIF, que pueden llevar el GPS). Devuelve el nombre del archivo.
function guardar_foto(PDO $db, array $u, ?array $f): string
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new DomainException('Elige una imagen.');
    if ($f['size'] > 8 * 1024 * 1024) throw new DomainException('La imagen pesa demasiado (máximo 8 MB).');
    $info = @getimagesize($f['tmp_name']);
    $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($tipos[$info[2]])) throw new DomainException('La foto debe ser JPG, PNG o WEBP.');
    $nombre = bin2hex(random_bytes(16));
    $dir = carpeta_archivos();
    if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring(file_get_contents($f['tmp_name'])))) {
        $giro = $info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data') ? ((@exif_read_data($f['tmp_name'])['Orientation'] ?? 1)) : 1;
        if ($g = [3 => 180, 6 => -90, 8 => 90][$giro] ?? 0) $img = imagerotate($img, $g, 0);
        $w = imagesx($img);
        $h = imagesy($img);
        $lado = min($w, $h);
        $t = min(400, $lado);
        $dst = imagecreatetruecolor($t, $t);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $img, 0, 0, intdiv($w - $lado, 2), intdiv($h - $lado, 2), $t, $t, $lado, $lado);
        $nombre .= '.jpg';
        if (!imagejpeg($dst, "$dir/$nombre", 85)) throw new DomainException('No se pudo guardar la foto.');
    } else {
        $nombre .= '.' . $tipos[$info[2]];
        if (!move_uploaded_file($f['tmp_name'], "$dir/$nombre") && !copy($f['tmp_name'], "$dir/$nombre")) throw new DomainException('No se pudo guardar la foto.');
    }
    consulta($db, 'UPDATE usuarios SET foto = ? WHERE id = ?', [$nombre, $u['id']]);
    return $nombre;
}

// Círculo con la foto o con las iniciales (color fijo por persona).
function avatar(array $u, string $clase = ''): string
{
    $ini = mb_strtoupper(mb_substr($u['nombre'] ?? '', 0, 1) . mb_substr($u['apellidos'] ?? '', 0, 1));
    $alt = e(trim(($u['nombre'] ?? '') . ' ' . ($u['apellidos'] ?? '')));
    if (!empty($u['foto'])) return '<img class="avatar ' . $clase . '" src="?p=foto&u=' . (int)$u['id'] . '&v=' . substr($u['foto'], 0, 8) . '" alt="' . $alt . '" loading="lazy">';
    $tono = ((int)($u['id'] ?? 0) * 47) % 360;
    return '<i class="avatar ' . $clase . '" style="background:hsl(' . $tono . ' 55% 50%)" title="' . $alt . '">' . e($ini) . '</i>';
}
