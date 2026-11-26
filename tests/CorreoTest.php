<?php

class CorreoTest extends Base
{
    private function servidor(): array
    {
        $puerto = random_int(20000, 40000);
        $archivo = tempnam(sys_get_temp_dir(), 'smtp');
        $p = proc_open([PHP_BINARY, __DIR__ . '/smtp_falso.php', (string)$puerto, $archivo], [], $tubos);
        for ($i = 0; $i < 100 && !is_file("$archivo.listo"); $i++) usleep(20000);
        return [$puerto, $archivo, $p];
    }

    public function testEnviaPorSmtpConUsuarioYClave(): void
    {
        [$puerto, $archivo, $p] = $this->servidor();
        smtp_enviar(['host' => '127.0.0.1', 'puerto' => $puerto, 'usuario' => 'no-responder@empresa.es', 'clave' => 'clave-buena'],
            'no-responder@empresa.es', 'eva@empresa.es', 'Aviso: no has fichado', "Hola, Eva.\n.línea que empieza por punto\nAdiós");
        proc_close($p);
        $log = file_get_contents($archivo);
        $this->assertStringContainsString('MAIL FROM:<no-responder@empresa.es>', $log);
        $this->assertStringContainsString('RCPT TO:<eva@empresa.es>', $log);
        $this->assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Aviso: no has fichado') . '?=', $log);
        $this->assertStringContainsString("\r\n..línea que empieza por punto\r\n", $log, 'el punto inicial se duplica');
        $this->assertStringContainsString(base64_encode('no-responder@empresa.es'), $log);
    }

    public function testClaveMalaDaUnErrorClaro(): void
    {
        [$puerto, , $p] = $this->servidor();
        try {
            smtp_enviar(['host' => '127.0.0.1', 'puerto' => $puerto, 'usuario' => 'x@y.es', 'clave' => 'mala'], 'x@y.es', 'a@b.es', 'a', 'b');
            $this->fail('debería fallar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('contraseña del correo incorrectos', $e->getMessage());
        } finally {
            proc_terminate($p);
        }
    }

    public function testSinServidorDevuelveError(): void
    {
        $this->expectException(RuntimeException::class);
        smtp_enviar(['host' => '127.0.0.1', 'puerto' => 1], 'x@y.es', 'a@b.es', 'a', 'b');
    }

    public function testAdministracionAdmiteVariosCorreos(): void
    {
        consulta($this->db, 'REPLACE INTO ajustes VALUES (?, ?)', ['correo', json_encode(['admin' => 'rrhh@x.es, mal-email; jefa@x.es'])]);
        $this->assertSame('rrhh@x.es, mal-email; jefa@x.es', correo_cfg()['admin']);
        $this->assertSame(0, avisar_administracion('a', 'b'), 'sin remitente configurado no se envía nada');
        $this->db->exec("DELETE FROM ajustes WHERE clave = 'correo'");
    }

    public function testElFalloDeEnvioGuardaElMotivoParaEnsenarlo(): void
    {
        consulta($this->db, 'REPLACE INTO ajustes VALUES (?, ?)', ['correo', json_encode(['desde' => 'no-responde@x.es', 'smtp' => ['host' => '127.0.0.1', 'puerto' => 1]])]);
        $this->assertFalse(enviar_correo('ana@x.es', 'a', 'b'));
        $this->assertStringContainsString('No se puede conectar con 127.0.0.1:1', correo_error());
        consulta($this->db, 'REPLACE INTO ajustes VALUES (?, ?)', ['correo', json_encode(['desde' => ''])]);
        $this->assertFalse(enviar_correo('ana@x.es', 'a', 'b'));
        $this->assertStringContainsString('Email de envío', correo_error());
    }
}
