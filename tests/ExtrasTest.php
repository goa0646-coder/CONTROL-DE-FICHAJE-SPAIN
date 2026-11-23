<?php

class ExtrasTest extends Base
{
    private function quiosco(int $centro): array
    {
        $this->db->prepare('INSERT INTO quioscos (centro_id, nombre, token_hash, alta_utc) VALUES (?, ?, ?, ?)')->execute([$centro, 'Q', hash('sha256', uniqid()), ahora()]);
        return $this->db->query('SELECT * FROM quioscos WHERE id = ' . $this->db->lastInsertId())->fetch();
    }

    public function testQrDelQuioscoCaducaYElDelCentroNo(): void
    {
        $c = $this->centro();
        $q = $this->quiosco($c);
        $t = 1_800_000_000;
        $codigo = codigo_quiosco($q, $t);
        $this->assertEquals(['id' => $q['id'], 'centro_id' => $c], validar_qr($this->db, $codigo, $t + 29));
        $this->assertNotNull(validar_qr($this->db, $codigo, $t + 45), 'vale el anterior');
        $this->assertNull(validar_qr($this->db, $codigo, $t + 90), 'caducado');
        $this->assertNull(validar_qr($this->db, 'k' . $q['id'] . '.0000000000000000', $t));
        $this->assertEquals(['id' => null, 'centro_id' => $c], validar_qr($this->db, codigo_centro($this->db, $c)));
        $this->db->exec("UPDATE quioscos SET activo = 0 WHERE id = {$q['id']}");
        $this->assertNull(validar_qr($this->db, codigo_quiosco($q), null), 'quiosco desactivado');
    }

    public function testFicharConQrUsaElCentroDelCodigo(): void
    {
        $c = $this->centro(['gps_modo' => 'obligatorio']);
        $u = $this->usuario(['centro_id' => $this->centro()]);
        $id = registrar_fichaje($this->db, $u, 'entrada', 'qr', ['quiosco' => validar_qr($this->db, codigo_centro($this->db, $c))]);
        $f = $this->db->query("SELECT origen, centro_id FROM fichajes WHERE id = $id")->fetch();
        $this->assertEquals(['qr', (string)$c], [$f['origen'], $f['centro_id']]);
    }

    public function testTokenYOrdenes(): void
    {
        $u = $this->usuario();
        $t = crear_token($this->db, $u['id'], 'Portátil');
        $this->assertNull(usuario_por_token($this->db, 'inda_falso'));
        [$x] = usuario_por_token($this->db, $t);
        $this->assertEquals($u['id'], $x['id']);
        $this->assertStringStartsWith('Entrada registrada', orden_fichaje($this->db, $u, 'Entrar', 'api'));
        $this->assertStringContainsString('trabajando', orden_fichaje($this->db, $u, 'estado', 'api'));
        $this->assertEquals('api', fichajes_de($this->db, $u['id'])[0]['origen']);
        $this->db->exec("UPDATE usuarios SET activo = 0 WHERE id = {$u['id']}");
        $this->assertNull(usuario_por_token($this->db, $t), 'baja = token inválido');
    }

    public function testChatVincularYFichar(): void
    {
        $u = $this->usuario();
        $t = crear_token($this->db, $u['id'], 'Slack');
        try {
            orden_chat($this->db, 'slack:T1:U1', 'entrar');
            $this->fail('sin vincular');
        } catch (DomainException) {
        }
        $this->assertStringContainsString('Ya puedes fichar', orden_chat($this->db, 'slack:T1:U1', "vincular $t"));
        $this->assertStringStartsWith('Entrada', orden_chat($this->db, 'slack:T1:U1', '<at>INDA</at> entrar'));
        $this->assertEquals('chat', fichajes_de($this->db, $u['id'])[0]['origen']);
    }

    public function testFirmasDeSlackYTeams(): void
    {
        $cuerpo = 'text=entrar&user_id=U1';
        $firma = 'v0=' . hash_hmac('sha256', "v0:1000:$cuerpo", 'sec');
        $this->assertTrue(firma_slack_ok('sec', '1000', $cuerpo, $firma, 1100));
        $this->assertFalse(firma_slack_ok('sec', '1000', $cuerpo, $firma, 2000), 'demasiado antigua');
        $this->assertFalse(firma_slack_ok('', '1000', $cuerpo, $firma, 1100), 'sin secreto configurado');
        $sec = base64_encode('clave');
        $this->assertTrue(firma_teams_ok($sec, '{"a":1}', 'HMAC ' . base64_encode(hash_hmac('sha256', '{"a":1}', 'clave', true))));
        $this->assertFalse(firma_teams_ok($sec, '{"a":2}', 'HMAC ' . base64_encode(hash_hmac('sha256', '{"a":1}', 'clave', true))));
    }

    public function testDocumentosSoloParaQuienToca(): void
    {
        $jefe = $this->usuario(['rol' => 'responsable']);
        $u = $this->usuario(['responsable_id' => $jefe['id']]);
        $otro = $this->usuario();
        $d = ['usuario_id' => $u['id']];
        $this->assertTrue(puede_ver_documento($u, $d));
        $this->assertFalse(puede_ver_documento($otro, $d));
        $this->assertFalse(puede_ver_documento($jefe, $d), 'sin permiso de documentos');
        $this->assertTrue(puede_ver_documento($otro, ['usuario_id' => null]));
        $this->assertFalse(puede_ver_documento(['rol' => 'inspeccion', 'id' => 0], ['usuario_id' => null]));
    }
}
