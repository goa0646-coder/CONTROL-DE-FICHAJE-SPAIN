<?php
// Servidor SMTP falso para las pruebas: una sola conexión, sin cifrar. Uso: php smtp_falso.php PUERTO ARCHIVO
[$_, $puerto, $archivo] = $argv;
$srv = stream_socket_server("tcp://127.0.0.1:$puerto", $errno, $err) or exit("no: $err\n");
file_put_contents("$archivo.listo", '1');
$c = stream_socket_accept($srv, 20) or exit;
$log = '';
$di = fn($t) => fwrite($c, "$t\r\n");
$di('220 falso ESMTP');
$datos = false;
while (($l = fgets($c)) !== false) {
    $log .= $l;
    if ($datos) {
        if (rtrim($l, "\r\n") === '.') {
            $datos = false;
            $di('250 OK guardado');
        }
        continue;
    }
    $cmd = strtoupper(substr(trim($l), 0, 4));
    match (true) {
        $cmd === 'EHLO' => (function () use ($di) { $di('250-falso'); $di('250 AUTH LOGIN'); })(),
        $cmd === 'AUTH' => $di('334 VXNlcm5hbWU6'),
        preg_match('/^[A-Za-z0-9+\/=]+\r?\n$/', $l) && substr_count($log, "\n") === 3 => $di('334 UGFzc3dvcmQ6'),
        preg_match('/^[A-Za-z0-9+\/=]+\r?\n$/', $l) && substr_count($log, "\n") === 4 => $di(base64_decode(trim($l)) === 'clave-buena' ? '235 OK' : '535 mal'),
        $cmd === 'MAIL', $cmd === 'RCPT' => $di('250 OK'),
        $cmd === 'DATA' => (function () use ($di, &$datos) { $datos = true; $di('354 adelante'); })(),
        $cmd === 'QUIT' => $di('221 adiós'),
        default => $di('500 ?'),
    };
    if ($cmd === 'QUIT') break;
}
file_put_contents($archivo, $log);
