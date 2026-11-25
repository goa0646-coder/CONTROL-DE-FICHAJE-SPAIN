<?php

class CierreTest extends Base
{
    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM ajustes WHERE clave = 'cierre_auto_min'");
    }

    public function testRecuerdaYCierraALos30MinutosConFinMasUno(): void
    {
        $u = $this->usuario(['horario_id' => $this->horario([1, 2, 3, 4, 5], '07:00', '15:00')]);
        $this->fichar($u, 'entrada', '2030-01-07 06:58:00');
        $aviso = fn($clave) => (bool)consulta($this->db, 'SELECT 1 FROM avisos WHERE usuario_id = ? AND clave LIKE ?', [$u['id'], "$clave%"])->fetchColumn();
        cerrar_jornadas($this->db, a_utc('2030-01-07 14:30:00'));
        $this->assertSame(['dentro', false], [estado_actual($this->db, (int)$u['id']), $aviso('recordar_salida')], 'aún en su turno');
        cerrar_jornadas($this->db, a_utc('2030-01-07 15:02:00'));
        $this->assertSame(['dentro', true], [estado_actual($this->db, (int)$u['id']), $aviso('recordar_salida')], 'recordatorio');
        cerrar_jornadas($this->db, a_utc('2030-01-07 15:10:00'));
        $this->assertSame('dentro', estado_actual($this->db, (int)$u['id']), 'hasta los 30 minutos no se cierra');
        cerrar_jornadas($this->db, a_utc('2030-01-07 15:31:00'));
        $this->assertSame('fuera', estado_actual($this->db, (int)$u['id']));
        $f = consulta($this->db, "SELECT * FROM fichajes WHERE usuario_id = ? AND tipo = 'salida'", [$u['id']])->fetch();
        $this->assertSame(['automatico', '15:01'], [$f['origen'], local($f['momento_utc'], 'H:i')], 'su jornada + 1 minuto');
        $this->assertCount(1, olvidos_fichaje($this->db, [$u], a_utc('2030-01-07 15:40:00')));
        $admin = $this->usuario(['rol' => 'admin']);
        $olvido = fn() => (bool)consulta($this->db, 'SELECT 1 FROM avisos WHERE usuario_id = ? AND clave = ?', [$admin['id'], "olvido:salida:{$u['id']}:2030-01-07"])->fetchColumn();
        avisar_olvidos($this->db, a_utc('2030-01-07 15:40:00'));
        $this->assertFalse($olvido(), 'a administración, a la hora');
        avisar_olvidos($this->db, a_utc('2030-01-07 16:02:00'));
        $this->assertTrue($olvido());
        $this->assertNotEmpty(consulta($this->db, "SELECT 1 FROM avisos WHERE usuario_id = ? AND clave = ?", [$u['id'], "cierre_auto:{$f['id']}"])->fetchColumn());
        cerrar_jornadas($this->db, a_utc('2030-01-07 16:30:00'));
        $this->assertSame(1, (int)consulta($this->db, "SELECT COUNT(*) FROM fichajes WHERE usuario_id = ? AND tipo = 'salida'", [$u['id']])->fetchColumn(), 'ya cerrada');
        $this->assertNull(verificar_cadena(fichajes_de($this->db, (int)$u['id'])), 'cadena de hashes intacta');
    }

    public function testNocheQueAcabaAlDiaSiguiente(): void
    {
        $u = $this->usuario(['horario_id' => $this->horario([1, 2, 3, 4, 5], '23:00', '07:00')]);
        $this->fichar($u, 'entrada', '2030-01-07 22:55:00');
        cerrar_jornadas($this->db, a_utc('2030-01-08 03:00:00'));
        $this->assertSame('dentro', estado_actual($this->db, (int)$u['id']));
        cerrar_jornadas($this->db, a_utc('2030-01-08 07:31:00'));
        $this->assertSame('fuera', estado_actual($this->db, (int)$u['id']));
        $this->assertSame('07:01', local(consulta($this->db, "SELECT momento_utc FROM fichajes WHERE usuario_id = ? AND tipo = 'salida'", [$u['id']])->fetchColumn(), 'H:i'));
    }

    public function testDesactivadoYFlexibleNoSeCierran(): void
    {
        $u = $this->usuario(['horario_id' => $this->horario([1, 2, 3, 4, 5], '07:00', '15:00')]);
        $flex = $this->usuario(['horario_id' => $this->horario([1, 2, 3, 4, 5], '07:00', '15:00', 480, true)]);
        $this->fichar($u, 'entrada', '2030-01-07 07:00:00');
        $this->fichar($flex, 'entrada', '2030-01-07 07:00:00');
        consulta($this->db, "REPLACE INTO ajustes VALUES ('cierre_auto_min', '0')");
        cerrar_jornadas($this->db, a_utc('2030-01-07 16:00:00'));
        $this->assertSame('dentro', estado_actual($this->db, (int)$u['id']));
        $this->db->exec("DELETE FROM ajustes WHERE clave = 'cierre_auto_min'");
        cerrar_jornadas($this->db, a_utc('2030-01-07 16:00:00'));
        $this->assertSame('fuera', estado_actual($this->db, (int)$u['id']));
        // Entrada: como pronto 10 minutos antes del turno; y quien no tiene que fichar no recibe cierres.
        $v = $this->usuario(['horario_id' => $this->horario([1, 2, 3, 4, 5], '07:00', '15:00')]);
        try { $this->fichar($v, 'entrada', '2030-01-08 06:40:00'); $this->fail('20 min antes no'); } catch (DomainException) {}
        $this->fichar($v, 'entrada', '2030-01-08 06:52:00');
        consulta($this->db, 'UPDATE usuarios SET sin_fichaje = 1 WHERE id = ?', [$v['id']]);
        cerrar_jornadas($this->db, a_utc('2030-01-08 18:00:00'));
        $this->assertSame('dentro', estado_actual($this->db, (int)$v['id']));
        $this->assertSame('dentro', estado_actual($this->db, (int)$flex['id']));
    }

    public function testGeneradorRespetaElDepartamentoDelTurno(): void
    {
        $this->db->exec('DELETE FROM necesidades');
        $s = uniqid();
        consulta($this->db, 'INSERT INTO departamentos (nombre) VALUES (?), (?)', ["AUX $s", "LIMP $s"]);
        $aux = (int)$this->db->lastInsertId();
        $limp = $aux + 1;
        guardar_turnos($this->db, [["NOCHE $s", 'N', '#6d28d9', '23:00', '07:00', '8:00', "AUX $s"]]);
        $hid = (int)consulta($this->db, 'SELECT id FROM horarios WHERE nombre = ?', ["NOCHE $s"])->fetchColumn();
        consulta($this->db, 'INSERT INTO necesidades (horario_id, dia, personas) VALUES (?, 0, 1)', [$hid]);
        $a = $this->usuario(['departamento_id' => $aux]);
        $l = $this->usuario(['departamento_id' => $limp]);
        $plan = generar_cuadrante($this->db, [$l, $a], '2030-01-07', '2030-01-07');
        $this->assertArrayHasKey($a['id'], $plan);
        $this->assertSame($hid, $plan[$a['id']]['2030-01-07']['horario_id']);
        $this->assertEmpty(array_filter($plan[$l['id']] ?? []), 'la de limpieza no entra en la noche de auxiliares');
        $this->db->exec('DELETE FROM necesidades');
    }
}
