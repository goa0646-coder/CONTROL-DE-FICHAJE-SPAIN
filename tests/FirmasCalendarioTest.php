<?php

class FirmasCalendarioTest extends Base
{
    public function testLeerIcsDeGoogle(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20261208\r\nDTEND;VALUE=DATE:20261209\r\nSUMMARY:Inmaculada Concepción\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20261224\r\nDTEND;VALUE=DATE:20261226\r\nSUMMARY:Navidad\\, cierre\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->assertSame([['2026-12-08', 'Inmaculada Concepción'], ['2026-12-24', 'Navidad, cierre'], ['2026-12-25', 'Navidad, cierre']], leer_festivos($ics));
    }

    public function testLeerCsvDeExcel(): void
    {
        $csv = "\xEF\xBB\xBFFecha;Nombre\r\n06/01/2027;Reyes\r\n2027-05-01;Trabajo\r\n31/02/2027;Mal\r\n";
        $this->assertSame([['2027-01-06', 'Reyes'], ['2027-05-01', 'Trabajo']], leer_festivos($csv));
        $this->assertSame([], leer_festivos('nada útil'));
    }

    public function testRecordatorioDeFirmaUnaVezYSegundoAvisoElDia5(): void
    {
        $u = $this->usuario();
        $this->fichar($u, 'entrada', '2026-10-05 08:00');
        $this->fichar($u, 'salida', '2026-10-05 16:00');
        $sin_fichajes = $this->usuario();
        $claves = fn($id) => $this->db->query("SELECT clave FROM avisos WHERE usuario_id = $id AND clave LIKE 'firma:%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        recordar_firmas($this->db, a_utc('2026-11-01 07:00'));
        $this->assertSame([], $claves($u['id']), 'antes de las 9 no');
        recordar_firmas($this->db, a_utc('2026-11-01 09:15'));
        recordar_firmas($this->db, a_utc('2026-11-02 10:00'));
        $this->assertSame(['firma:2026-10:1'], $claves($u['id']));
        recordar_firmas($this->db, a_utc('2026-11-05 09:00'));
        $this->assertSame(['firma:2026-10:1', 'firma:2026-10:2'], $claves($u['id']));
        $this->assertSame([], $claves($sin_fichajes['id']), 'sin fichajes no se le pide firmar');
        firmar_mes($this->db, $u, '2026-10', 'secreto123');
        $this->assertSame(0, recordar_firmas($this->db, a_utc('2026-11-20 10:00')));
    }
}
