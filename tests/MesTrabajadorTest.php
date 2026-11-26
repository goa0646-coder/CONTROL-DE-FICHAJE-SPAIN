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
}
