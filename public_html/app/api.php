<?php
// Tokens para la extensión del navegador, chat (Slack/Teams) y exportación a gestoría.

function crear_token(PDO $db, int $uid, string $nombre, string $alcance = 'personal'): string
{
    $t = 'inda_' . bin2hex(random_bytes(20));
    consulta($db, 'INSERT INTO tokens_api (usuario_id, nombre, token_hash, alcance, creado_utc) VALUES (?, ?, ?, ?, ?)',
        [$uid, mb_substr($nombre, 0, 80) ?: 'Token', hash('sha256', $t), $alcance, ahora()]);
    return $t;
}

// Devuelve [usuario, token] si el token es válido y su dueño sigue activo.
function usuario_por_token(PDO $db, string $t): ?array
{
    $k = consulta($db, 'SELECT * FROM tokens_api WHERE token_hash = ? AND activo = 1', [hash('sha256', $t)])->fetch();
    $u = $k ? usuario($db, (int)$k['usuario_id']) : null;
    if (!$u || !acceso_vigente($u)) return null;
    consulta($db, 'UPDATE tokens_api SET usado_utc = ? WHERE id = ?', [ahora(), $k['id']]);
    return [$u, $k];
}

// Órdenes de texto comunes a chat y extensión: entrar, pausa, volver, salir, estado.
function orden_fichaje(PDO $db, array $u, string $orden, string $origen): string
{
    $o = mb_strtolower(trim($orden));
    $tipo = match (true) {
        in_array($o, ['entrar', 'entrada', 'in'], true) => 'entrada',
        in_array($o, ['pausa', 'descanso'], true) => 'pausa_inicio',
        in_array($o, ['volver', 'vuelta', 'fin pausa'], true) => 'pausa_fin',
        in_array($o, ['salir', 'salida', 'out'], true) => 'salida',
        default => null,
    };
    $estados = ['fuera' => 'fuera', 'dentro' => 'trabajando', 'pausa' => 'en pausa'];
    if ($tipo) {
        registrar_fichaje($db, $u, $tipo, $origen, ['ip' => ip()]);
        auditar($db, (int)$u['id'], "fichaje_$origen", "tipo=$tipo");
        return TIPOS[$tipo] . ' registrada a las ' . local(ahora(), 'H:i') . '.';
    }
    if ($o === '' || $o === 'estado') return 'Estás ' . $estados[estado_actual($db, (int)$u['id'])] . '. Órdenes: entrar, pausa, volver, salir.';
    throw new DomainException('No entiendo. Órdenes: entrar, pausa, volver, salir, estado.');
}

// --- Códigos QR para fichar con el móvil ---
// k<quiosco>.<código>: el quiosco lo cambia cada 30 s (hay que estar delante). c<centro>.<código>: cartel impreso fijo.

function secreto(PDO $db, string $clave): string
{
    if ($v = ajuste($db, $clave)) return $v;
    consulta($db, 'INSERT IGNORE INTO ajustes VALUES (?, ?)', [$clave, bin2hex(random_bytes(32))]);
    return ajuste($db, $clave);
}

function codigo_quiosco(array $q, ?int $t = null): string
{
    return 'k' . $q['id'] . '.' . substr(hash_hmac('sha256', (string)intdiv($t ?? time(), 30), $q['token_hash']), 0, 16);
}

function codigo_centro(PDO $db, int $centro): string
{
    return "c$centro." . substr(hash_hmac('sha256', "centro$centro", secreto($db, 'secreto_qr')), 0, 16);
}

// Devuelve ['id' => quiosco o null, 'centro_id'] si el código vale.
function validar_qr(PDO $db, string $c, ?int $t = null): ?array
{
    $t ??= time();
    if (preg_match('/^k(\d+)\.[0-9a-f]{16}$/', $c, $m)) {
        $q = consulta($db, 'SELECT * FROM quioscos WHERE id = ? AND activo = 1', [$m[1]])->fetch();
        // Vale el código actual y el anterior (hasta 60 s), por si se escanea justo al cambiar.
        if ($q && (hash_equals(codigo_quiosco($q, $t), $c) || hash_equals(codigo_quiosco($q, $t - 30), $c))) return ['id' => $q['id'], 'centro_id' => $q['centro_id']];
    } elseif (preg_match('/^c(\d+)\.[0-9a-f]{16}$/', $c, $m)) {
        $ok = consulta($db, 'SELECT activo FROM centros WHERE id = ?', [$m[1]])->fetchColumn();
        if ($ok && hash_equals(codigo_centro($db, (int)$m[1]), $c)) return ['id' => null, 'centro_id' => (int)$m[1]];
    }
    return null;
}

// --- Slack (/inda) y Microsoft Teams (webhook saliente) ---

function firma_slack_ok(string $secreto, string $ts, string $cuerpo, string $firma, ?int $ahora = null): bool
{
    return $secreto !== '' && abs(($ahora ?? time()) - (int)$ts) < 300 && hash_equals('v0=' . hash_hmac('sha256', "v0:$ts:$cuerpo", $secreto), $firma);
}

function firma_teams_ok(string $secreto, string $cuerpo, string $cabecera): bool
{
    return $secreto !== '' && hash_equals('HMAC ' . base64_encode(hash_hmac('sha256', $cuerpo, base64_decode($secreto), true)), $cabecera);
}

// Texto del chat → respuesta. "vincular CÓDIGO" asocia la cuenta del chat a la persona.
function orden_chat(PDO $db, string $chat_id, string $texto): string
{
    $texto = trim(preg_replace('/<at>.*?<\/at>/i', '', $texto));
    if (preg_match('/^vincular\s+(\S+)$/i', $texto, $m)) {
        [$u, $k] = usuario_por_token($db, $m[1]) ?? throw new DomainException('Código no válido. Créalo en INDA → Perfil.');
        if ($k['alcance'] !== 'personal') throw new DomainException('Usa un código personal.');
        consulta($db, 'UPDATE tokens_api SET chat_id = NULL WHERE chat_id = ?', [$chat_id]);
        consulta($db, 'UPDATE tokens_api SET chat_id = ? WHERE id = ?', [$chat_id, $k['id']]);
        auditar($db, (int)$u['id'], 'chat_vinculado', $chat_id);
        return 'Hola, ' . $u['nombre'] . '. Ya puedes fichar: entrar, pausa, volver, salir, estado.';
    }
    $k = consulta($db, 'SELECT * FROM tokens_api WHERE chat_id = ? AND activo = 1', [$chat_id])->fetch();
    $u = $k ? usuario($db, (int)$k['usuario_id']) : null;
    if (!$u || !acceso_vigente($u)) throw new DomainException('Primero vincula tu cuenta: crea un código en INDA → Perfil y escribe «vincular CÓDIGO».');
    return orden_fichaje($db, $u, $texto, 'chat');
}
