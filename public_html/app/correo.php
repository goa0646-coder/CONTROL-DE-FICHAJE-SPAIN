<?php
// Envío de correo por el buzón del dominio (SMTP de Hostinger u otro). Sin librerías.
// Configuración: Empresa → Correo (se guarda en la BD); 'smtp' y 'correo_desde' de config-inda.php sirven de respaldo.

function correo_cfg(): array
{
    $c = config();
    $bd = [];
    try {
        $bd = json_decode(ajuste(db(), 'correo') ?? '[]', true) ?: [];
    } catch (Throwable) {
        // Sin BD todavía (asistente): solo config.
    }
    // Lo que se guarda en Empresa → Correo manda; config-inda.php queda como respaldo.
    $smtp = array_filter($bd['smtp'] ?? []) + ($c['smtp'] ?? []);
    return ['desde' => ($bd['desde'] ?? '') ?: ($c['correo_desde'] ?? ''), 'smtp' => $smtp, 'admin' => $bd['admin'] ?? ''];
}

function enviar_correo(string $para, string $asunto, string $texto): bool
{
    $c = correo_cfg();
    if ($c['desde'] === '' || $para === '') {
        if ($para !== '' && function_exists('registrar_error')) registrar_error('Correo sin enviar: falta el «Email de envío» en Administración → Correo', __FILE__, __LINE__);
        return false;
    }
    if (!empty($c['smtp']['host'])) {
        try {
            smtp_enviar($c['smtp'], $c['desde'], $para, $asunto, $texto);
            return true;
        } catch (RuntimeException $e) {
            error_log('INDA correo: ' . $e->getMessage());
            // Se ve en Administración → Diagnóstico (sin el destinatario).
            if (function_exists('registrar_error')) registrar_error('Correo no enviado (SMTP): ' . $e->getMessage(), __FILE__, __LINE__);
            return false;
        }
    }
    return @mail($para, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $texto, "From: INDA <{$c['desde']}>\r\nContent-Type: text/plain; charset=utf-8");
}

// Correo a administración (uno o varios emails separados por comas).
function avisar_administracion(string $asunto, string $texto): int
{
    $n = 0;
    foreach (preg_split('/[\s,;]+/', correo_cfg()['admin'], -1, PREG_SPLIT_NO_EMPTY) as $e) {
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) $n += enviar_correo($e, $asunto, $texto);
    }
    return $n;
}

// SMTP mínimo: 465 = SSL, 587 = STARTTLS, otro puerto = sin cifrar (solo pruebas locales). Lanza RuntimeException si falla.
function smtp_enviar(array $s, string $de, string $para, string $asunto, string $texto): void
{
    $puerto = (int)($s['puerto'] ?? 465);
    $host = $s['host'];
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
    $f = @stream_socket_client(($puerto === 465 ? 'ssl://' : 'tcp://') . "$host:$puerto", $errno, $err, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$f) throw new RuntimeException("No se puede conectar con $host:$puerto ($err).");
    stream_set_timeout($f, 15);
    $leer = function () use ($f): string {
        $r = '';
        while (($l = fgets($f, 1000)) !== false) {
            $r .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;
        }
        return $r;
    };
    $orden = function (?string $cmd, int $espera) use ($f, $leer): string {
        if ($cmd !== null) fwrite($f, "$cmd\r\n");
        $r = $leer();
        if ((int)substr($r, 0, 3) !== $espera) throw new RuntimeException('El servidor de correo respondió: ' . trim($r ?: 'nada'));
        return $r;
    };
    try {
        $orden(null, 220);
        $yo = parse_url(config()['url'] ?? '', PHP_URL_HOST) ?: 'localhost';
        $orden("EHLO $yo", 250);
        if ($puerto === 587) {
            $orden('STARTTLS', 220);
            if (!stream_socket_enable_crypto($f, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('No se pudo cifrar la conexión.');
            $orden("EHLO $yo", 250);
        }
        if (!empty($s['usuario'])) {
            $orden('AUTH LOGIN', 334);
            $orden(base64_encode($s['usuario']), 334);
            try {
                $orden(base64_encode($s['clave'] ?? ''), 235);
            } catch (RuntimeException) {
                throw new RuntimeException('Usuario o contraseña del correo incorrectos.');
            }
        }
        $orden("MAIL FROM:<$de>", 250);
        $orden("RCPT TO:<$para>", 250);
        $orden('DATA', 354);
        $cuerpo = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $texto)); // un punto al principio de línea terminaría el mensaje
        fwrite($f, implode("\r\n", [
            'Date: ' . date(DATE_RFC2822), 'From: INDA <' . $de . '>', "To: <$para>", 'Subject: =?UTF-8?B?' . base64_encode($asunto) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $de)[1] ?? 'inda') . '>', 'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8', 'Content-Transfer-Encoding: 8bit', '', str_replace("\n", "\r\n", $cuerpo), '.', '']));
        $orden(null, 250);
        fwrite($f, "QUIT\r\n");
    } finally {
        fclose($f);
    }
}
