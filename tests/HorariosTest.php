<?php

class HorariosTest extends Base
{
    // 2026-03-02 es lunes.
    public function testCalendarioConFestivoYPlanificacion(): void
    {
        $c = $this->centro();
        $u = $this->usuario(['horario_id' => $this->horario(), 'centro_id' => $c]);
        $this->db->prepare('INSERT INTO festivos (centro_id, fecha, nombre) VALUES (?, ?, ?)')->execute([$c, '2026-03-03', 'Fiesta local']);
        $otro = $this->horario([3], '14:00', '22:00', 480);
        $this->db->prepare('INSERT INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, ?, ?), (?, ?, NULL)')->execute([$u['id'], '2026-03-04', $otro, $u['id'], '2026-03-05']);
        $cal = calendario_teorico($this->db, $u, '2026-03-02', '2026-03-08');
        $this->assertEquals([480, 0, 480, 0, 480, 0, 0], array_column($cal, 'minutos'));
        $this->assertEquals('Fiesta local', $cal['2026-03-03']['festivo']);
        $this->assertEquals('14:00:00', $cal['2026-03-04']['entrada']);
        $this->assertFalse($cal['2026-03-05']['laborable'], 'libre planificado');
    }

    public function testSinTeoricoAntesDelAlta(): void
    {
        $u = $this->usuario(['horario_id' => $this->horario(), 'alta_utc' => '2026-03-04 07:00:00']);
        $this->assertEquals([0, 0, 480], array_column(calendario_teorico($this->db, $u, '2026-03-02', '2026-03-04'), 'minutos'));
    }

    public function testResumenExtraYDiferencia(): void
    {
        $u = $this->usuario(['horario_id' => $this->horario()]);
        $this->fichar($u, 'entrada', '2026-03-02 08:00');
        $this->fichar($u, 'salida', '2026-03-02 18:00');
        $this->fichar($u, 'entrada', '2026-03-03 08:00');
        $this->fichar($u, 'salida', '2026-03-03 15:00');
        $t = resumen($this->db, $u, '2026-03-02', '2026-03-03')['total'];
        $this->assertEquals(17 * 3600, $t['trabajado']);
        $this->assertEquals(2 * 3600, $t['extra']);
        $this->assertEquals(1 * 3600, $t['diferencia']);
    }

    public function testPausaRetribuidaCuentaComoTrabajo(): void
    {
        $u = $this->usuario();
        $medico = $this->db->query("SELECT id FROM motivos_pausa WHERE retribuida = 1 LIMIT 1")->fetchColumn();
        $comida = $this->db->query("SELECT id FROM motivos_pausa WHERE retribuida = 0 LIMIT 1")->fetchColumn();
        $this->fichar($u, 'entrada', '2026-03-02 08:00');
        $this->fichar($u, 'pausa_inicio', '2026-03-02 10:00', ['motivo_id' => $medico]);
        $this->fichar($u, 'pausa_fin', '2026-03-02 11:00');
        $this->fichar($u, 'pausa_inicio', '2026-03-02 13:00', ['motivo_id' => $comida]);
        $this->fichar($u, 'pausa_fin', '2026-03-02 14:00');
        $this->fichar($u, 'salida', '2026-03-02 17:00');
        $j = jornadas(fichajes_de($this->db, $u['id']))[0];
        $this->assertEquals(8 * 3600, $j['trabajado']);
        $this->assertEquals(3600, $j['pausa']);
        $filas = $this->db->query("SELECT * FROM fichajes WHERE usuario_id = {$u['id']} ORDER BY id")->fetchAll();
        $this->assertNull(verificar_cadena($filas), 'el motivo entra en la cadena');
        $filas[1]['motivo_id'] = $comida;
        $this->assertNotNull(verificar_cadena($filas), 'cambiar el motivo se detecta');
    }

    public function testMotivoInvalidoSeRechaza(): void
    {
        $u = $this->usuario();
        $this->fichar($u, 'entrada', '2026-03-02 08:00');
        $this->expectException(DomainException::class);
        $this->fichar($u, 'pausa_inicio', '2026-03-02 10:00', ['motivo_id' => 99999]);
    }

    public function testNocturnas(): void
    {
        // 21:00 → 07:00 hora de Madrid: 8 h nocturnas (22–6).
        $t = [strtotime(a_utc('2026-03-02 21:00') . ' UTC'), strtotime(a_utc('2026-03-03 07:00') . ' UTC')];
        $this->assertEquals(8 * 3600, segundos_nocturnos([$t]));
        $this->assertEquals(0, segundos_nocturnos([[strtotime(a_utc('2026-03-02 08:00') . ' UTC'), strtotime(a_utc('2026-03-02 16:00') . ' UTC')]]));
    }

    public function testBolsaConAjuste(): void
    {
        $u = $this->usuario(['horario_id' => $this->horario([1]), 'alta_utc' => a_utc('2026-03-02 00:00')]);
        $this->fichar($u, 'entrada', '2026-03-02 08:00');
        $this->fichar($u, 'salida', '2026-03-02 18:00');
        $lunes_pasados = count(array_filter(rango_fechas('2026-03-02', local(gmdate('Y-m-d H:i:s', time() - 86400), 'Y-m-d')), fn($f) => date('N', strtotime($f)) == 1));
        $esperado = 2 * 3600 - ($lunes_pasados - 1) * 8 * 3600;
        $this->assertEquals($esperado, saldo_bolsa($this->db, $u));
        $this->db->prepare('INSERT INTO ajustes_saldo (usuario_id, fecha, minutos, motivo, creado_por, creado_utc) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$u['id'], '2026-03-10', -120, 'Pagadas', $u['id'], ahora()]);
        $this->assertEquals($esperado - 7200, saldo_bolsa($this->db, $u));
    }

    public function testFueraDeZonaRechazada(): void
    {
        $c = $this->centro(['gps_modo' => 'obligatorio']);
        $this->db->exec("UPDATE centros SET fuera_zona = 'rechazar' WHERE id = $c");
        $u = $this->usuario(['centro_id' => $c]);
        try {
            registrar_fichaje($this->db, $u, 'entrada', 'movil', ['lat' => '41.3874', 'lng' => '2.1686']);
            $this->fail('debería rechazarse');
        } catch (DomainException $e) {
            $this->assertStringContainsString('fuera', $e->getMessage());
        }
        registrar_fichaje($this->db, $u, 'entrada', 'movil', ['lat' => '40.4169', 'lng' => '-3.7039']);
        $this->assertEquals('dentro', estado_actual($this->db, $u['id']));
    }

    public function testRedesPermitidas(): void
    {
        $this->assertTrue(ip_permitida('', '1.2.3.4'));
        $this->assertTrue(ip_permitida('88.1.2.3, 10.0.', '10.0.5.6'));
        $this->assertFalse(ip_permitida('88.1.2.3, 10.0.', '11.0.5.6'));
        $c = $this->centro();
        $this->db->exec("UPDATE centros SET ips = '88.1.2.3' WHERE id = $c");
        $u = $this->usuario(['centro_id' => $c]);
        $this->expectException(DomainException::class);
        registrar_fichaje($this->db, $u, 'entrada', 'web', ['ip' => '1.1.1.1']);
    }

    public function testQuioscoNoTieneLimiteDeRed(): void
    {
        $c = $this->centro();
        $this->db->exec("UPDATE centros SET ips = '88.1.2.3' WHERE id = $c");
        $this->db->prepare('INSERT INTO quioscos (centro_id, nombre, token_hash, alta_utc) VALUES (?, ?, ?, ?)')->execute([$c, 'Q', hash('sha256', uniqid()), ahora()]);
        $q = ['id' => $this->db->lastInsertId(), 'centro_id' => $c];
        $u = $this->usuario(['centro_id' => $c]);
        registrar_fichaje($this->db, $u, 'entrada', 'quiosco', ['quiosco' => $q, 'ip' => '1.1.1.1']);
        $this->assertEquals('dentro', estado_actual($this->db, $u['id']));
    }
}
