<?php

class FichajesTest extends Base
{
    public function testSecuenciaValida(): void
    {
        $u = $this->usuario();
        $this->assertEquals('fuera', estado_actual($this->db, $u['id']));
        foreach (['entrada' => 'dentro', 'pausa_inicio' => 'pausa', 'pausa_fin' => 'dentro', 'salida' => 'fuera'] as $tipo => $estado) {
            registrar_fichaje($this->db, $u, $tipo, 'web');
            $this->assertEquals($estado, estado_actual($this->db, $u['id']));
        }
    }

    public function testSecuenciaInvalidaSeRechaza(): void
    {
        $u = $this->usuario();
        $this->expectException(DomainException::class);
        registrar_fichaje($this->db, $u, 'salida', 'web');
    }

    public function testDobleEntradaSeRechaza(): void
    {
        $u = $this->usuario();
        registrar_fichaje($this->db, $u, 'entrada', 'web');
        $this->expectException(DomainException::class);
        registrar_fichaje($this->db, $u, 'entrada', 'web');
    }

    public function testFichajesInmutables(): void
    {
        $u = $this->usuario();
        $id = registrar_fichaje($this->db, $u, 'entrada', 'web');
        foreach (["UPDATE fichajes SET tipo = 'salida' WHERE id = $id", "DELETE FROM fichajes WHERE id = $id"] as $sql) {
            try {
                $this->db->exec($sql);
                $this->fail("Debería fallar: $sql");
            } catch (PDOException $e) {
                $this->assertStringContainsString('no se pueden', $e->getMessage());
            }
        }
    }

    public function testCadenaDetectaManipulacion(): void
    {
        $u = $this->usuario();
        registrar_fichaje($this->db, $u, 'entrada', 'web');
        registrar_fichaje($this->db, $u, 'salida', 'web');
        $filas = $this->db->query("SELECT * FROM fichajes WHERE usuario_id = {$u['id']} ORDER BY id")->fetchAll();
        $this->assertNull(verificar_cadena($filas));
        $filas[0]['momento_utc'] = '2000-01-01 08:00:00';
        $this->assertEquals((int)$filas[0]['id'], verificar_cadena($filas));
        unset($filas[0]);
        $this->assertNotNull(verificar_cadena(array_values($filas)), 'borrar un fichaje también se detecta');
    }

    public function testGpsDentroYFueraDeZona(): void
    {
        $u = $this->usuario(['centro_id' => $this->centro(['gps_modo' => 'opcional'])]);
        $id = registrar_fichaje($this->db, $u, 'entrada', 'movil', ['lat' => '40.4169', 'lng' => '-3.7039', 'precision' => '20']);
        $this->assertEquals('dentro', $this->db->query("SELECT zona FROM fichajes WHERE id = $id")->fetchColumn());
        $this->assertEquals('1', $this->db->query("SELECT COUNT(*) FROM fichajes_gps WHERE fichaje_id = $id")->fetchColumn());
        $id = registrar_fichaje($this->db, $u, 'salida', 'movil', ['lat' => '41.3874', 'lng' => '2.1686']);
        $this->assertEquals('fuera', $this->db->query("SELECT zona FROM fichajes WHERE id = $id")->fetchColumn());
    }

    public function testGpsObligatorioSinUbicacionSeRechaza(): void
    {
        $u = $this->usuario(['centro_id' => $this->centro(['gps_modo' => 'obligatorio'])]);
        $this->expectException(DomainException::class);
        registrar_fichaje($this->db, $u, 'entrada', 'movil', ['lat' => '', 'lng' => '']);
    }

    public function testGpsDesactivadoNoGuardaCoordenadas(): void
    {
        // El centro pide GPS pero la persona tiene excepción.
        $u = $this->usuario(['centro_id' => $this->centro(['gps_modo' => 'obligatorio']), 'gps_modo' => 'no']);
        $id = registrar_fichaje($this->db, $u, 'entrada', 'movil', ['lat' => '40.4', 'lng' => '-3.7']);
        $this->assertEquals('0', $this->db->query("SELECT COUNT(*) FROM fichajes_gps WHERE fichaje_id = $id")->fetchColumn());
    }

    public function testPurgaGpsA12Meses(): void
    {
        $u = $this->usuario(['centro_id' => $this->centro(['gps_modo' => 'opcional'])]);
        $viejo = registrar_fichaje($this->db, $u, 'entrada', 'movil', ['lat' => '40.4168', 'lng' => '-3.7038'], '2024-01-10 08:00:00');
        $nuevo = registrar_fichaje($this->db, $u, 'salida', 'movil', ['lat' => '40.4', 'lng' => '-3.7'], '2025-06-10 08:00:00');
        purgar_gps($this->db, '2025-07-01 00:00:00');
        $quedan = $this->db->query("SELECT fichaje_id FROM fichajes_gps WHERE fichaje_id IN ($viejo, $nuevo)")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals([(string)$nuevo], $quedan);
        $this->assertEquals('dentro', $this->db->query("SELECT zona FROM fichajes WHERE id = $viejo")->fetchColumn(), 'la zona se conserva');
    }

    public function testJornadasConPausaYMedianoche(): void
    {
        $f = fn($tipo, $m, $anulado = '0') => ['tipo' => $tipo, 'momento_utc' => $m, 'anulado' => $anulado];
        $j = jornadas([
            $f('entrada', '2026-03-02 21:00:00'),
            $f('pausa_inicio', '2026-03-02 23:00:00'),
            $f('pausa_fin', '2026-03-02 23:30:00'),
            $f('salida', '2026-03-03 05:00:00'),
            $f('entrada', '2026-03-03 20:00:00'),
            $f('salida', '2026-03-03 21:00:00', '1'),
        ]);
        $this->assertCount(2, $j);
        $this->assertEquals(2 * 3600 + 5.5 * 3600, (float)$j[0]['trabajado']);
        $this->assertEquals(1800, $j[0]['pausa']);
        $this->assertNull($j[1]['salida'], 'salida anulada: jornada abierta');
    }
}
