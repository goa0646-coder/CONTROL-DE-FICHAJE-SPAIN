<?php

class SeguridadTest extends Base
{
    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM ajustes WHERE clave IN ('cifrado_datos', 'dos_pasos')");
        unset($GLOBALS['CLAVE_DATOS']);
    }

    public function testCifradoDeDatosSensiblesSeActivaYDesactiva(): void
    {
        $GLOBALS['CLAVE_DATOS'] = random_bytes(32);
        $u = $this->usuario();
        consulta($this->db, "UPDATE usuarios SET dni = '12345678Z', nss = '15/1234567890', fecha_nacimiento = '1980-05-06' WHERE id = ?", [$u['id']]);
        consulta($this->db, "REPLACE INTO ajustes VALUES ('cifrado_datos', 'si')");
        cambiar_cifrado($this->db, true);
        $crudo = consulta($this->db, 'SELECT * FROM usuarios WHERE id = ?', [$u['id']])->fetch();
        $this->assertStringStartsWith('enc:', $crudo['dni']);
        $this->assertStringStartsWith('enc:', $crudo['nss']);
        $this->assertNull($crudo['fecha_nacimiento']);
        $claro = usuario($this->db, (int)$u['id']);
        $this->assertSame(['12345678Z', '15/1234567890', '1980-05-06'], [$claro['dni'], $claro['nss'], $claro['fecha_nacimiento']]);
        $this->assertSame((int)$u['id'], (int)usuario_por_dni($this->db, '12345678-z')['id'], 'se busca por DNI aunque esté cifrado');
        $c = campos_seguros($this->db, ['dni' => '87654321X', 'nss' => '', 'fecha_nacimiento' => null]);
        $this->assertStringStartsWith('enc:', $c['dni']);
        cambiar_cifrado($this->db, false);
        $crudo = consulta($this->db, 'SELECT * FROM usuarios WHERE id = ?', [$u['id']])->fetch();
        $this->assertSame(['12345678Z', '15/1234567890', '1980-05-06', null], [$crudo['dni'], $crudo['nss'], $crudo['fecha_nacimiento'], $crudo['nacimiento_c']]);
    }

    public function testCodigosDeDosPasos(): void
    {
        $s = base32('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $s);
        $this->assertSame('287082', totp($s, 59), 'vector de la RFC 6238');
        $this->assertTrue(totp_valido($s, '287 082', 59));
        $this->assertTrue(totp_valido($s, totp($s, 89), 59), 'margen de 30 s');
        $this->assertFalse(totp_valido($s, '000000', 59));
        $admin = $this->usuario(['rol' => 'admin']);
        $this->assertFalse(pide_dos_pasos($this->db, $admin));
        cambiar_dos_pasos($this->db, true);
        $this->assertTrue(pide_dos_pasos($this->db, $admin));
        $this->assertFalse(pide_dos_pasos($this->db, $this->usuario()), 'solo administración');
    }

    public function testSinClaveNoSeGuardaEncimaDeLoCifrado(): void
    {
        consulta($this->db, "REPLACE INTO ajustes VALUES ('cifrado_datos', 'si')");
        $GLOBALS['CLAVE_DATOS'] = '';
        $this->assertTrue(clave_perdida($this->db));
        $this->expectException(DomainException::class);
        campos_seguros($this->db, ['dni' => '', 'nss' => null, 'fecha_nacimiento' => null]);
    }

    public function testCuentaDelPropietarioProtegida(): void
    {
        $this->db->exec("DELETE FROM ajustes WHERE clave = 'valoraciones_admin'");
        $a = $this->usuario(['rol' => 'admin']);
        $b = $this->usuario(['rol' => 'admin']);
        $this->assertFalse(protegida($this->db, $b, (int)$a['id']), 'sin propietario no hay protección');
        activar_valoraciones($this->db, $a, true);
        $this->assertTrue(protegida($this->db, $b, (int)$a['id']), 'otro admin no la toca');
        $this->assertFalse(protegida($this->db, $a, (int)$a['id']), 'el titular sí');
        $this->assertFalse(protegida($this->db, $a, (int)$b['id']));
        $this->db->exec("DELETE FROM ajustes WHERE clave = 'valoraciones_admin'");
    }
}
