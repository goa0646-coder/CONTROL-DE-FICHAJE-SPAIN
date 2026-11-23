<?php

class DispositivosTest extends Base
{
    public function testElMovilQuedaRecordadoHastaQueSeCierra(): void
    {
        $u = $this->usuario();
        $t = crear_dispositivo($this->db, $u, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1');
        $this->assertEquals($u['id'], usuario_por_dispositivo($this->db, $t)['id']);
        $this->assertEquals('iPhone Safari', $this->db->query("SELECT nombre FROM dispositivos WHERE usuario_id = {$u['id']}")->fetchColumn());
        $this->assertNull(usuario_por_dispositivo($this->db, 'otro'));
        $this->assertNull(usuario_por_dispositivo($this->db, ''));
        cerrar_dispositivos($this->db, $u['id']);
        $this->assertNull(usuario_por_dispositivo($this->db, $t));
    }

    public function testCambiarClaveCierraLosDemasNoEste(): void
    {
        $u = $this->usuario();
        $este = crear_dispositivo($this->db, $u);
        $otro = crear_dispositivo($this->db, $u);
        cerrar_dispositivos($this->db, $u['id'], $este);
        $this->assertNotNull(usuario_por_dispositivo($this->db, $este));
        $this->assertNull(usuario_por_dispositivo($this->db, $otro));
    }

    public function testBajaCaducidadEInspeccion(): void
    {
        $u = $this->usuario();
        $t = crear_dispositivo($this->db, $u);
        $this->db->exec("UPDATE usuarios SET activo = 0 WHERE id = {$u['id']}");
        $this->assertNull(usuario_por_dispositivo($this->db, $t), 'de baja');
        $v = $this->usuario();
        $t = crear_dispositivo($this->db, $v);
        $this->db->exec("UPDATE dispositivos SET usado_utc = '2020-01-01 00:00:00' WHERE usuario_id = {$v['id']}");
        $this->assertNull(usuario_por_dispositivo($this->db, $t), 'más de un año sin usar');
        $this->assertNull(crear_dispositivo($this->db, $this->usuario(['rol' => 'inspeccion'])));
    }
}
