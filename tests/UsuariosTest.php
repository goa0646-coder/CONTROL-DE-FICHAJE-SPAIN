<?php

class UsuariosTest extends Base
{
    public function testPermisosDeVer(): void
    {
        $jefe = $this->usuario(['rol' => 'responsable']);
        $mio = $this->usuario(['responsable_id' => $jefe['id']]);
        $ajeno = $this->usuario();
        $insp = $this->usuario(['rol' => 'inspeccion']);
        $this->assertTrue(puede_ver($jefe, $mio));
        $this->assertFalse(puede_ver($jefe, $ajeno));
        $this->assertFalse(puede_ver($mio, $ajeno));
        $this->assertTrue(puede_ver($insp, $ajeno));
        $this->assertFalse(puede_aprobar($insp, $ajeno), 'Inspección solo lee');
    }

    public function testLoginBloqueaTrasCincoFallos(): void
    {
        $u = $this->usuario();
        for ($i = 0; $i < 5; $i++) $this->assertIsString(login($this->db, $u['email'], 'mal'));
        $this->assertSame('Demasiados intentos. Espera 15 minutos.', login($this->db, $u['email'], 'secreto123'));
    }

    public function testInspeccionCaducada(): void
    {
        $u = $this->usuario(['rol' => 'inspeccion']);
        $this->db->exec("UPDATE usuarios SET acceso_hasta = '2020-01-01' WHERE id = {$u['id']}");
        $this->assertSame('Tu acceso no está activo.', login($this->db, $u['email'], 'secreto123'));
    }
}
