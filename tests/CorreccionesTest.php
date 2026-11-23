<?php

class CorreccionesTest extends Base
{
    public function testAnadirOlvidoAprobado(): void
    {
        $jefe = $this->usuario(['rol' => 'responsable']);
        $u = $this->usuario(['responsable_id' => $jefe['id']]);
        registrar_fichaje($this->db, $u, 'entrada', 'web', [], '2026-01-05 08:00:00');
        $id = pedir_correccion($this->db, $u, ['accion' => 'anadir', 'tipo' => 'salida', 'momento_utc' => '2026-01-05 16:00:00', 'motivo' => 'Olvidé fichar'], $u['id']);
        $this->assertEquals('dentro', estado_actual($this->db, $u['id']), 'pendiente no cuenta');
        resolver_correccion($this->db, $id, $jefe, true, 'ok');
        $this->assertEquals('fuera', estado_actual($this->db, $u['id']));
        $f = fichajes_de($this->db, $u['id']);
        $this->assertEquals('correccion', $f[1]['origen']);
        $this->assertEquals((string)$id, $f[1]['correccion_id']);
    }

    public function testCambiarConservaOriginalAnulado(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $u = $this->usuario();
        $orig = registrar_fichaje($this->db, $u, 'entrada', 'web', [], '2026-01-05 09:30:00');
        $id = pedir_correccion($this->db, $u, ['accion' => 'cambiar', 'fichaje_id' => $orig, 'tipo' => 'entrada', 'momento_utc' => '2026-01-05 08:00:00', 'motivo' => 'Fiché tarde'], $u['id']);
        resolver_correccion($this->db, $id, $admin, true);
        $f = fichajes_de($this->db, $u['id']);
        $this->assertCount(2, $f, 'el original sigue ahí');
        $this->assertEquals(['0', '2026-01-05 08:00:00'], [$f[0]['anulado'], $f[0]['momento_utc']]);
        $this->assertEquals(['1', (string)$orig], [$f[1]['anulado'], (string)$f[1]['id']]);
    }

    public function testRechazadaNoCambiaNada(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $u = $this->usuario();
        $orig = registrar_fichaje($this->db, $u, 'entrada', 'web');
        $id = pedir_correccion($this->db, $u, ['accion' => 'anular', 'fichaje_id' => $orig, 'motivo' => 'x'], $u['id']);
        resolver_correccion($this->db, $id, $admin, false, 'no');
        $this->assertEquals('dentro', estado_actual($this->db, $u['id']));
    }

    public function testNadieApruebaLoSuyo(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $id = pedir_correccion($this->db, $admin, ['accion' => 'anadir', 'tipo' => 'entrada', 'momento_utc' => '2026-01-05 08:00:00', 'motivo' => 'x'], $admin['id']);
        $this->expectException(DomainException::class);
        resolver_correccion($this->db, $id, $admin, true);
    }

    public function testResponsableAjenoNoAprueba(): void
    {
        $otroJefe = $this->usuario(['rol' => 'responsable']);
        $u = $this->usuario();
        $id = pedir_correccion($this->db, $u, ['accion' => 'anadir', 'tipo' => 'entrada', 'momento_utc' => '2026-01-05 08:00:00', 'motivo' => 'x'], $u['id']);
        $this->expectException(DomainException::class);
        resolver_correccion($this->db, $id, $otroJefe, true);
    }

    public function testNoSePuedeAnularFichajeAjeno(): void
    {
        $u = $this->usuario();
        $otro = $this->usuario();
        $f = registrar_fichaje($this->db, $otro, 'entrada', 'web');
        $this->expectException(DomainException::class);
        pedir_correccion($this->db, $u, ['accion' => 'anular', 'fichaje_id' => $f, 'motivo' => 'x'], $u['id']);
    }

    public function testMotivoObligatorioYFechaNoFutura(): void
    {
        $u = $this->usuario();
        foreach ([['motivo' => ''], ['momento_utc' => '2999-01-01 00:00:00']] as $mal) {
            try {
                pedir_correccion($this->db, $u, $mal + ['accion' => 'anadir', 'tipo' => 'entrada', 'momento_utc' => '2026-01-05 08:00:00', 'motivo' => 'x'], $u['id']);
                $this->fail('Debería rechazarse');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
