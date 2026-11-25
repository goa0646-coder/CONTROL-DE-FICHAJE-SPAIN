<?php

class AvisosTest extends Base
{
    private function avisos(int $uid): array
    {
        return $this->db->query("SELECT clave FROM avisos WHERE usuario_id = $uid ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testFaltaEntradaRetrasoYOlvidoDeSalida(): void
    {
        $jefe = $this->usuario(['rol' => 'responsable']);
        $u = $this->usuario(['horario_id' => $this->horario(), 'responsable_id' => $jefe['id']]);
        // Lunes 2/3/2026: a las 8:20 aún no se avisa; a las 8:31 (30 min) sí.
        revisar_avisos($this->db, a_utc('2026-03-02 08:20'));
        $this->assertSame([], $this->avisos($u['id']));
        revisar_avisos($this->db, a_utc('2026-03-02 08:31'));
        $this->assertEquals(['sin_entrada:2026-03-02'], $this->avisos($u['id']));
        revisar_avisos($this->db, a_utc('2026-03-02 08:45'));
        $this->assertCount(1, $this->avisos($u['id']), 'no se repite');
        $this->fichar($u, 'entrada', '2026-03-02 08:50');
        revisar_avisos($this->db, a_utc('2026-03-02 09:00'));
        $this->assertEquals(["retraso:{$u['id']}:2026-03-02"], $this->avisos($jefe['id']));
        revisar_avisos($this->db, a_utc('2026-03-02 17:30'));
        $this->assertStringStartsWith('sin_salida:', $this->avisos($u['id'])[1]);
    }

    public function testSinAvisoEnVacacionesNiConHorarioFlexible(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $u = $this->usuario(['horario_id' => $this->horario()]);
        $f = $this->usuario(['horario_id' => $this->horario([1, 2, 3, 4, 5], '08:00', '16:00', 480, true)]);
        $tipo = $this->db->query("SELECT id FROM tipos_ausencia WHERE nombre = 'Vacaciones'")->fetchColumn();
        resolver_ausencia($this->db, pedir_ausencia($this->db, $u, ['tipo_id' => $tipo, 'desde' => '2026-03-09', 'hasta' => '2026-03-09'], $u['id']), $admin, true);
        revisar_avisos($this->db, a_utc('2026-03-09 10:00'));
        $this->assertSame([], $this->avisos($u['id']));
        $this->assertSame([], $this->avisos($f['id']));
    }

    public function testAprobadoresSinResponsableSonLosAdmins(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $u = $this->usuario();
        $this->assertContains((int)$admin['id'], aprobadores($this->db, $u));
        $this->assertNotContains((int)$u['id'], aprobadores($this->db, $u));
    }
}
