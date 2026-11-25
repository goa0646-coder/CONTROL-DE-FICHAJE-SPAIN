<?php

class PausasTest extends Base
{
    public function testPausaConJustificanteYAvisoAlDia(): void
    {
        $u = $this->usuario();
        $admin = $this->usuario(['rol' => 'admin']);
        $medico = (int)$this->db->query("SELECT id FROM motivos_pausa WHERE nombre = 'Médico'")->fetchColumn();
        $comida = (int)$this->db->query("SELECT id FROM motivos_pausa WHERE nombre = 'Comida'")->fetchColumn();
        $this->fichar($u, 'entrada', '2099-06-01 08:00');
        $this->fichar($u, 'pausa_inicio', '2099-06-01 10:00', ['motivo_id' => $comida]);
        $this->fichar($u, 'pausa_fin', '2099-06-01 10:30');
        $id = $this->fichar($u, 'pausa_inicio', '2099-06-01 11:00', ['motivo_id' => $medico]);
        $this->fichar($u, 'pausa_fin', '2099-06-01 12:00');
        $p = pausas_sin_justificante($this->db, (int)$u['id'], '2099-01-01');
        $this->assertSame([$id], array_map('intval', array_column($p, 'id')), 'solo la del médico (la comida no pide)');
        // Antes del día no avisa; pasado el día, a la persona y a quien aprueba.
        $this->assertSame(0, revisar_justificantes($this->db, '2099-06-02 08:00:00'));
        $this->assertGreaterThan(0, revisar_justificantes($this->db, '2099-06-02 10:00:00'));
        $this->assertSame(1, (int)consulta($this->db, 'SELECT COUNT(*) FROM avisos WHERE usuario_id = ? AND clave = ?', [$u['id'], "justif:$id"])->fetchColumn());
        $this->assertSame(1, (int)consulta($this->db, 'SELECT COUNT(*) FROM avisos WHERE usuario_id = ? AND clave = ?', [$admin['id'], "justif_falta:$id"])->fetchColumn());
        $this->assertSame(0, revisar_justificantes($this->db, '2099-06-03 10:00:00'), 'una sola vez');
        // Lo sube: deja de estar pendiente; dos veces no.
        justificar_pausa($this->db, $u, $id, fn() => ['abc.pdf', 'cita.pdf']);
        $this->assertSame([], pausas_sin_justificante($this->db, (int)$u['id'], '2099-01-01'));
        $this->expectException(DomainException::class);
        justificar_pausa($this->db, $u, $id, fn() => ['abc.pdf', 'cita.pdf']);
    }

    public function testSinAvisosATrabajadoresYUnAvisoGeneral(): void
    {
        $u = $this->usuario();
        $admin = $this->usuario(['rol' => 'admin']);
        consulta($this->db, 'REPLACE INTO ajustes VALUES (?, ?)', ['avisos_trabajadores', 'no']);
        $this->assertFalse(avisos_a_trabajadores($this->db));
        $this->assertTrue(notificar($this->db, (int)$u['id'], 'prueba:' . uniqid(), 'Cambio'), 'queda en la campana');
        $this->assertGreaterThan(0, anunciar_cambios($this->db, $admin, 'Tu calendario está listo.'));
        $this->assertSame(1, (int)consulta($this->db, "SELECT COUNT(*) FROM avisos WHERE usuario_id = ? AND clave LIKE 'general:%'", [$u['id']])->fetchColumn());
        consulta($this->db, 'REPLACE INTO ajustes VALUES (?, ?)', ['avisos_trabajadores', 'si']);
        $this->assertTrue(avisos_a_trabajadores($this->db));
    }
}
