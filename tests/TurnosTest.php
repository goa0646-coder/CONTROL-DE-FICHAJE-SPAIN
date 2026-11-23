<?php

class TurnosTest extends Base
{
    public function testCargaTurnosConLetraYDepartamento(): void
    {
        $s = uniqid();
        [$c, $a] = guardar_turnos($this->db, [["MAÑANA $s", 'm', '#f59e0b', '07:00', '15:00', '8:00', "AUX $s"], ["NOCHE $s", 'N', '#6d28d9', '23:00', '07:00', '8:00', "AUX $s"]]);
        $this->assertSame([2, 0], [$c, $a]);
        $h = array_values(array_filter(horarios($this->db), fn($x) => $x['nombre'] === "MAÑANA $s"))[0];
        $this->assertSame(['M', '#f59e0b'], [$h['abreviatura'], $h['color']]);
        $this->assertCount(5, $h['dias'], 'habitual de lunes a viernes');
        $this->assertSame(['07:00:00', '15:00:00', 480], [$h['dias'][3]['entrada'], $h['dias'][3]['salida'], (int)$h['dias'][3]['minutos']]);
        $this->assertSame("AUX $s", consulta($this->db, 'SELECT nombre FROM departamentos WHERE id = ?', [$h['departamento_id']])->fetchColumn());
        // Otra vez con otra hora: actualiza, no duplica.
        $this->assertSame([0, 1], guardar_turnos($this->db, [["mañana $s", 'M', '#f59e0b', '06:00', '14:00', '8:00', "AUX $s"]]));
        $this->assertSame('06:00:00', horarios($this->db)[$h['id']]['dias'][1]['entrada']);
        $this->assertSame(1, (int)consulta($this->db, 'SELECT COUNT(*) FROM departamentos WHERE nombre = ?', ["AUX $s"])->fetchColumn());
    }

    public function testHorasMalEscritasNoGuardanNada(): void
    {
        $s = uniqid();
        try {
            guardar_turnos($this->db, [["A $s", 'A', '', '07:00', '15:00', '8:00', ''], ["B $s", 'B', '', '07:00', '15:00', 'ocho', '']]);
            $this->fail('debía fallar');
        } catch (DomainException) {
        }
        $this->assertSame(0, (int)consulta($this->db, 'SELECT COUNT(*) FROM horarios WHERE nombre = ?', ["A $s"])->fetchColumn());
    }

    public function testCeldaDelCalendario(): void
    {
        $s = uniqid();
        guardar_turnos($this->db, [["TARDE $s", 'T', '#3b82f6', '15:00', '23:00', '8:00', '']]);
        $hid = (int)consulta($this->db, 'SELECT id FROM horarios WHERE nombre = ?', ["TARDE $s"])->fetchColumn();
        $u = $this->usuario(['horario_id' => $hid]);
        consulta($this->db, "INSERT INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, '2030-01-08', NULL)", [$u['id']]);
        $cal = calendario_teorico($this->db, $u, '2030-01-07', '2030-01-08');
        $h = horarios($this->db);
        $this->assertSame(['T', '#3b82f6'], array_slice(celda_turno($cal['2030-01-07'], $h, true), 0, 2));
        $this->assertSame('D', celda_turno($cal['2030-01-08'], $h, true)[0], 'libre planificado = descanso');
    }

    public function testMensajeDeAvisoPersonalizado(): void
    {
        $this->assertStringContainsString('aún no has fichado', texto_aviso($this->db, 'sin_entrada', ['nombre' => 'Ana', 'fecha' => '', 'hora' => '07:00', 'turno' => 'M', 'fichado' => '']));
        consulta($this->db, 'REPLACE INTO ajustes VALUES (?, ?)', ['plantillas_aviso', json_encode(['sin_entrada' => 'Ojo {nombre}: tu turno {turno} era a las {hora}.'])]);
        $this->assertSame('Ojo Ana: tu turno M era a las 07:00.', texto_aviso($this->db, 'sin_entrada', ['nombre' => 'Ana', 'fecha' => '', 'hora' => '07:00', 'turno' => 'M', 'fichado' => '']));
        // El cron usa el mensaje guardado.
        $u = $this->usuario(['horario_id' => $this->horario([1, 2, 3, 4, 5], '07:00', '15:00')]);
        revisar_avisos($this->db, a_utc('2030-01-07 08:00:00'));
        $txt = consulta($this->db, "SELECT texto FROM avisos WHERE usuario_id = ? AND clave = 'sin_entrada:2030-01-07'", [$u['id']])->fetchColumn();
        $this->assertStringStartsWith('Ojo Prueba: tu turno', $txt);
        $this->db->exec("DELETE FROM ajustes WHERE clave = 'plantillas_aviso'");
    }
}
