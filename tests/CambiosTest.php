<?php

class CambiosTest extends Base
{
    public function testCambioEntreCompanerosConAprobacion(): void
    {
        $s = uniqid();
        $this->db->exec("INSERT INTO puestos (nombre) VALUES ('Auxiliar $s'), ('Cocina $s')");
        $aux = (int)$this->db->lastInsertId();
        $m = $this->horario([1, 2, 3, 4, 5, 6, 7], '07:00', '15:00', 480);
        $t = $this->horario([1, 2, 3, 4, 5, 6, 7], '15:00', '23:00', 480);
        $p = function (int $puesto) { $u = $this->usuario(); $this->db->prepare('INSERT INTO usuarios_puestos VALUES (?, ?)')->execute([$u['id'], $puesto]); return $u; };
        [$a, $b, $cocina] = [$p($aux), $p($aux), $p($aux + 1)];
        $admin = $this->usuario(['rol' => 'admin']);
        // A: mañana del 6 al 8. B: tarde el 6 y libre el 7 y el 8.
        foreach (rango_fechas('2099-07-06', '2099-07-08') as $f) {
            consulta($this->db, 'INSERT INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, ?, ?)', [$a['id'], $f, $m]);
            consulta($this->db, 'INSERT INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, ?, ?)', [$b['id'], $f, $f === '2099-07-06' ? $t : null]);
        }
        $this->assertArrayHasKey($b['id'], companeros_cambio($this->db, $a));
        $this->assertArrayNotHasKey($cocina['id'], companeros_cambio($this->db, $a), 'solo mismo puesto');
        // A pide cambiar su mañana del día 8 por el libre de B.
        $id = pedir_cambio($this->db, $a, (int)$b['id'], '2099-07-08', '', 'médico');
        $this->assertSame(1, (int)consulta($this->db, 'SELECT COUNT(*) FROM avisos WHERE usuario_id = ? AND clave = ?', [$b['id'], "cambio:$id"])->fetchColumn());
        try { resolver_cambio($this->db, $admin, $id, true); $this->fail('sin aceptar no se aprueba'); } catch (DomainException) {}
        responder_cambio($this->db, $b, $id, true);
        $this->assertCount(1, cambios_por_aprobar($this->db, $admin));
        resolver_cambio($this->db, $admin, $id, true);
        $this->assertNull(calendario_teorico($this->db, $a, '2099-07-08', '2099-07-08')['2099-07-08']['horario_id']);
        $this->assertSame($m, calendario_teorico($this->db, $b, '2099-07-08', '2099-07-08')['2099-07-08']['horario_id']);
        // El 7 no: B saldría a las 23 del 6 y entraría a las 7 (8 h de descanso). Se avisa al pedir.
        $this->expectException(DomainException::class);
        pedir_cambio($this->db, $b, (int)$a['id'], '2099-07-07');
    }

    public function testSolicitudesYRecordatorio(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $u = $this->usuario();
        $tipo = (int)$this->db->query("SELECT id FROM tipos_ausencia WHERE nombre = 'Vacaciones'")->fetchColumn();
        pedir_ausencia($this->db, $u, ['tipo_id' => $tipo, 'desde' => '2099-09-01', 'hasta' => '2099-09-02'], (int)$u['id']);
        $s = solicitudes_pendientes($this->db, $admin);
        $this->assertGreaterThanOrEqual(1, count($s['ausencias']));
        $this->assertGreaterThanOrEqual(1, n_por_decidir($s));
        consulta($this->db, 'DELETE FROM ajustes WHERE clave IN (?, ?)', ['recordatorio_horas', 'recordatorio_ultimo']);
        $this->assertTrue(recordar_solicitudes($this->db, '2099-09-01 08:00:00'));
        $this->assertFalse(recordar_solicitudes($this->db, '2099-09-01 20:00:00'), 'no antes de 24 h');
        $this->assertTrue(recordar_solicitudes($this->db, '2099-09-02 08:30:00'));
        consulta($this->db, 'REPLACE INTO ajustes VALUES (?, ?)', ['recordatorio_horas', 'no']);
        $this->assertFalse(recordar_solicitudes($this->db, '2099-09-05 08:30:00'), '0 = nunca');
    }

    public function testCambioQueIncumpleSoloSiLosDosLoAceptanPorSuVoluntad(): void
    {
        $s = uniqid();
        $this->db->exec("INSERT INTO puestos (nombre) VALUES ('Enfermería $s')");
        $pu = (int)$this->db->lastInsertId();
        $m = $this->horario([1, 2, 3, 4, 5, 6, 7], '07:00', '15:00', 480);
        $t = $this->horario([1, 2, 3, 4, 5, 6, 7], '15:00', '23:00', 480);
        $p = function () use ($pu) { $u = $this->usuario(); $this->db->prepare('INSERT INTO usuarios_puestos VALUES (?, ?)')->execute([$u['id'], $pu]); return $u; };
        [$a, $b] = [$p(), $p()];
        $admin = $this->usuario(['rol' => 'admin']);
        foreach (rango_fechas('2099-07-06', '2099-07-08') as $f) {
            consulta($this->db, 'INSERT INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, ?, ?)', [$a['id'], $f, $m]);
            consulta($this->db, 'INSERT INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, ?, ?)', [$b['id'], $f, $f === '2099-07-06' ? $t : null]);
        }
        // B sale a las 23 del 6 y entraría a las 7 del 7: sin el tick no se puede.
        try { pedir_cambio($this->db, $b, (int)$a['id'], '2099-07-07'); $this->fail('sin tick no'); } catch (DomainException $e) { $this->assertStringContainsString('propia voluntad', $e->getMessage()); }
        $id = pedir_cambio($this->db, $b, (int)$a['id'], '2099-07-07', '', '', true);
        $this->assertNotSame('', cambio($this->db, $id)['incumple']);
        try { responder_cambio($this->db, $a, $id, true); $this->fail('el compañero también tiene que marcarlo'); } catch (DomainException) {}
        responder_cambio($this->db, $a, $id, true, true);
        resolver_cambio($this->db, $admin, $id, true);
        $this->assertSame($m, calendario_teorico($this->db, $b, '2099-07-07', '2099-07-07')['2099-07-07']['horario_id']);
        $this->assertSame(1, (int)consulta($this->db, "SELECT COUNT(*) FROM auditoria WHERE accion = 'cambio_aprobado' AND detalle LIKE ?", ["cambio=$id voluntario%"])->fetchColumn());
    }
}
