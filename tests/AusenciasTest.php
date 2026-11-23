<?php

class AusenciasTest extends Base
{
    private function tipo(string $nombre): int
    {
        return (int)$this->db->query("SELECT id FROM tipos_ausencia WHERE nombre = " . $this->db->quote($nombre))->fetchColumn();
    }

    public function testVacacionesAprobadasQuitanTeoricoYConsumen(): void
    {
        $jefe = $this->usuario(['rol' => 'responsable']);
        $u = $this->usuario(['horario_id' => $this->horario(), 'responsable_id' => $jefe['id']]);
        // Lunes 2 a domingo 8 de marzo de 2026: 5 laborables.
        $id = pedir_ausencia($this->db, $u, ['tipo_id' => $this->tipo('Vacaciones'), 'desde' => '2026-03-02', 'hasta' => '2026-03-08'], $u['id']);
        $s = saldos_ausencia($this->db, $u, 2026)[$this->tipo('Vacaciones')];
        $this->assertEquals([22.0, 0, 5, 17.0], [$s['asignado'], $s['consumido'], $s['comprometido'], $s['disponible']]);
        resolver_ausencia($this->db, $id, $jefe, true);
        $s = saldos_ausencia($this->db, $u, 2026)[$this->tipo('Vacaciones')];
        $this->assertEquals([5, 0, 17.0], [$s['consumido'], $s['comprometido'], $s['disponible']]);
        $this->assertEquals(0, resumen($this->db, $u, '2026-03-02', '2026-03-08')['total']['teorico']);
        $this->assertEquals('Vacaciones', calendario_teorico($this->db, $u, '2026-03-02', '2026-03-02')['2026-03-02']['ausencia']['tipo']);
    }

    public function testNoSePuedenPedirMasDiasDeLosQueQuedan(): void
    {
        $u = $this->usuario(['horario_id' => $this->horario()]);
        $this->expectExceptionMessage('No tienes días suficientes');
        pedir_ausencia($this->db, $u, ['tipo_id' => $this->tipo('Asuntos propios'), 'desde' => '2026-03-02', 'hasta' => '2026-03-04'], $u['id']);
    }

    public function testSolapeSeRechaza(): void
    {
        $u = $this->usuario();
        pedir_ausencia($this->db, $u, ['tipo_id' => $this->tipo('Vacaciones'), 'desde' => '2026-04-06', 'hasta' => '2026-04-10'], $u['id']);
        $this->expectExceptionMessage('Ya tienes');
        pedir_ausencia($this->db, $u, ['tipo_id' => $this->tipo('Baja por IT'), 'desde' => '2026-04-08', 'hasta' => '2026-04-08'], $u['id']);
    }

    public function testAusenciaPorHorasRestaMinutos(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $u = $this->usuario(['horario_id' => $this->horario()]);
        $id = pedir_ausencia($this->db, $u, ['tipo_id' => $this->tipo('Visita médica'), 'desde' => '2026-03-02', 'hora_desde' => '09:00', 'hora_hasta' => '11:30'], $u['id']);
        resolver_ausencia($this->db, $id, $admin, true);
        $this->assertEquals(480 - 150, calendario_teorico($this->db, $u, '2026-03-02', '2026-03-02')['2026-03-02']['minutos']);
        // Dos visitas el mismo día sin solaparse sí se permiten.
        pedir_ausencia($this->db, $u, ['tipo_id' => $this->tipo('Visita médica'), 'desde' => '2026-03-02', 'hora_desde' => '12:00', 'hora_hasta' => '13:00'], $u['id']);
        $this->expectException(DomainException::class);
        pedir_ausencia($this->db, $u, ['tipo_id' => $this->tipo('Visita médica'), 'desde' => '2026-03-02', 'hora_desde' => '10:00', 'hora_hasta' => '10:30'], $u['id']);
    }

    public function testNadieApruebaLoSuyoNiAjeno(): void
    {
        $jefe = $this->usuario(['rol' => 'responsable']);
        $otroJefe = $this->usuario(['rol' => 'responsable']);
        $u = $this->usuario(['responsable_id' => $jefe['id']]);
        $id = pedir_ausencia($this->db, $u, ['tipo_id' => $this->tipo('Vacaciones'), 'desde' => '2026-05-04', 'hasta' => '2026-05-04'], $u['id']);
        foreach ([$u, $otroJefe] as $quien) {
            try {
                resolver_ausencia($this->db, $id, $quien, true);
                $this->fail('no debería poder');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        resolver_ausencia($this->db, $id, $jefe, false, 'Coincide con cierre');
        $this->assertEquals('rechazada', ausencia($this->db, $id)['estado']);
    }

    public function testCancelar(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $u = $this->usuario();
        $pasada = pedir_ausencia($this->db, $u, ['tipo_id' => $this->tipo('Baja por IT'), 'desde' => '2026-01-12', 'hasta' => '2026-01-13'], $u['id']);
        resolver_ausencia($this->db, $pasada, $admin, true);
        $futura = pedir_ausencia($this->db, $u, ['tipo_id' => $this->tipo('Baja por IT'), 'desde' => '2099-01-12', 'hasta' => '2099-01-13'], $u['id']);
        resolver_ausencia($this->db, $futura, $admin, true);
        cancelar_ausencia($this->db, $futura, $u);
        $this->assertEquals('cancelada', ausencia($this->db, $futura)['estado']);
        $this->expectException(DomainException::class);
        cancelar_ausencia($this->db, $pasada, $u);
    }

    public function testSolapesConElEquipoYArrastre(): void
    {
        $jefe = $this->usuario(['rol' => 'responsable']);
        $a = $this->usuario(['responsable_id' => $jefe['id'], 'horario_id' => $this->horario()]);
        $b = $this->usuario(['responsable_id' => $jefe['id']]);
        pedir_ausencia($this->db, $b, ['tipo_id' => $this->tipo('Vacaciones'), 'desde' => '2025-08-03', 'hasta' => '2025-08-10'], $b['id']);
        $id = pedir_ausencia($this->db, $a, ['tipo_id' => $this->tipo('Vacaciones'), 'desde' => '2025-08-04', 'hasta' => '2025-08-08'], $a['id']);
        $this->assertCount(1, solapes($this->db, ausencia($this->db, $id)));
        resolver_ausencia($this->db, $id, $jefe, true);
        $this->assertEquals(17.0, arrastrar_saldo($this->db, $a, $this->tipo('Vacaciones'), 2025));
        $this->assertEquals(39.0, saldos_ausencia($this->db, $a, 2026)[$this->tipo('Vacaciones')]['asignado']);
    }
}
