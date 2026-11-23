<?php

class InformesTest extends Base
{
    public function testFirmaMensual(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $u = $this->usuario();
        $f = $this->fichar($u, 'entrada', '2026-02-02 08:00');
        $this->fichar($u, 'salida', '2026-02-02 16:00');
        try {
            firmar_mes($this->db, $u, '2026-02', 'mala');
            $this->fail('contraseña mala');
        } catch (DomainException) {
        }
        $hash = firmar_mes($this->db, $u, '2026-02', 'secreto123');
        $this->assertEquals($hash, firma_mes($this->db, $u, '2026-02')['hash']);
        try {
            $this->db->exec("DELETE FROM firmas_mensuales WHERE usuario_id = {$u['id']}");
            $this->fail('las firmas no se borran');
        } catch (PDOException $e) {
            $this->assertStringContainsString('no se pueden', $e->getMessage());
        }
        // Una corrección posterior cambia el contenido: la firma deja de valer.
        resolver_correccion($this->db, pedir_correccion($this->db, $u, ['accion' => 'anular', 'fichaje_id' => $f, 'motivo' => 'x'], $u['id']), $admin, true);
        $this->assertNotEquals($hash, hash('sha256', contenido_mes($this->db, $u, '2026-02')));
        $this->assertNotEquals($hash, firmar_mes($this->db, $u, '2026-02', 'secreto123'), 'se puede volver a firmar');
    }

    public function testNoSeFirmaElMesEnCurso(): void
    {
        $u = $this->usuario();
        $this->expectException(DomainException::class);
        firmar_mes($this->db, $u, local(ahora(), 'Y-m'), 'secreto123');
    }

    public function testInformePorPersona(): void
    {
        $u = $this->usuario(['horario_id' => $this->horario()]);
        $this->fichar($u, 'entrada', '2026-03-02 08:00');
        $this->fichar($u, 'salida', '2026-03-02 17:00');
        $this->fichar($u, 'entrada', '2026-03-03 08:00');
        $fila = informe($this->db, [$u], '2026-03-02', '2026-03-06')[0];
        $this->assertEquals([9 * 3600, 5 * 480 * 60, 3600, 1, 1], [$fila['trabajado'], $fila['teorico'], $fila['extra'], $fila['dias_trabajados'], $fila['abiertas']]);
    }

    public function testPermisosPorRol(): void
    {
        $this->assertTrue(puede(['rol' => 'admin'], 'documentos'));
        $this->assertTrue(puede(['rol' => 'responsable'], 'informes'));
        $this->assertFalse(puede(['rol' => 'empleado'], 'informes'));
    }
}
