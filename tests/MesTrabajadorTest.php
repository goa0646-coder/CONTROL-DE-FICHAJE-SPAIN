<?php

class MesTrabajadorTest extends Base
{
    public function testCalendarioConLeyendaDeSusTurnosYSinMotivoParaCompaneros(): void
    {
        $m = $this->horario([1, 2, 3, 4, 5], '07:00', '15:00');
        $otro = $this->horario([6], '15:00', '23:00');
        consulta($this->db, "UPDATE horarios SET abreviatura = 'Q', nombre = 'TURNO PRUEBA Q' WHERE id = ?", [$m]);
        consulta($this->db, "UPDATE horarios SET abreviatura = 'Z', nombre = 'TURNO SIN USO Z' WHERE id = ?", [$otro]);
        $u = $this->usuario(['horario_id' => $m]);
        $tipo = (int)$this->db->query('SELECT id FROM tipos_ausencia ORDER BY id LIMIT 1')->fetchColumn();
        $nombre_tipo = $this->db->query("SELECT nombre FROM tipos_ausencia WHERE id = $tipo")->fetchColumn();
        consulta($this->db, "INSERT INTO ausencias (usuario_id, tipo_id, desde, hasta, estado, pedida_utc, pedida_por) VALUES (?, ?, '2099-03-10', '2099-03-11', 'aprobada', ?, ?)", [$u['id'], $tipo, ahora(), $u['id']]);
        $h = mes_trabajador($this->db, $u, '2099-03', 'cambios', true);
        $this->assertStringContainsString('TURNO PRUEBA Q', $h);
        $this->assertStringNotContainsString('TURNO SIN USO Z', $h, 'la leyenda solo lleva sus turnos');
        $this->assertStringContainsString('data-fecha="2099-03-02"', $h);
        $this->assertStringContainsString(e($nombre_tipo), $h);
        $comp = mes_trabajador($this->db, $u, '2099-03', '', false, false);
        $this->assertStringNotContainsString(e($nombre_tipo), $comp, 'a un compañero no se le enseña el motivo');
        $this->assertStringContainsString('No disponible', $comp);
        $this->assertStringNotContainsString('data-fecha', $comp);
    }

    public function testLimiteDeVisionSoloParaEmpleados(): void
    {
        $e = $this->usuario();
        $r = $this->usuario(['rol' => 'responsable']);
        consulta($this->db, "REPLACE INTO ajustes VALUES ('vision_dias', '30')");
        $this->assertSame(date('Y-m-d', strtotime(local(ahora(), 'Y-m-d') . ' +30 days')), limite_vision($this->db, $e));
        $this->assertNull(limite_vision($this->db, $r), 'responsables lo ven todo');
        consulta($this->db, "REPLACE INTO ajustes VALUES ('vision_dias', '0')");
        $this->assertNull(limite_vision($this->db, $e), 'sin límite');
    }

    public function testBorrarCuadranteEntreFechasSoloDeEsasPersonasYDesdeHoy(): void
    {
        $m = $this->horario([1, 2, 3, 4, 5], '07:00', '15:00');
        $a = $this->usuario();
        $b = $this->usuario();
        foreach ([$a, $b] as $u) foreach (['2099-05-01', '2099-05-02', '2099-05-10'] as $f)
            consulta($this->db, 'INSERT INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, ?, ?)', [$u['id'], $f, $m]);
        $this->assertSame(2, borrar_cuadrante($this->db, [$a['id']], '2099-05-01', '2099-05-05'));
        $n = fn($u) => (int)consulta($this->db, 'SELECT COUNT(*) FROM planificacion WHERE usuario_id = ?', [$u['id']])->fetchColumn();
        $this->assertSame(1, $n($a));
        $this->assertSame(3, $n($b), 'a quien no se elige no se le toca');
        $this->expectException(DomainException::class);
        borrar_cuadrante($this->db, [$a['id']], '2020-01-01', '2099-05-31');
    }
}
