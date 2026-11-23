<?php

class PresenciaTest extends Base
{
    public function testDiaConFichajesYSinFichar(): void
    {
        $h = $this->horario();
        $a = $this->usuario(['horario_id' => $h]);
        $b = $this->usuario(['horario_id' => $h]);
        $this->fichar($a, 'entrada', '2030-01-07 08:20:00');
        $this->fichar($a, 'pausa_inicio', '2030-01-07 11:00:00');
        $this->fichar($a, 'pausa_fin', '2030-01-07 11:30:00');
        $this->fichar($a, 'salida', '2030-01-07 16:20:00');
        [$fa, $fb] = presencia_dia($this->db, [$a, $b], '2030-01-07');
        $this->assertSame(['08:20', '16:20'], [local($fa['entrada'], 'H:i'), local($fa['salida'], 'H:i')]);
        $this->assertSame([1800, 8 * 3600, 27000, -1800], [$fa['pausas'], $fa['teorico'], $fa['trabajado'], $fa['diferencia']]);
        $this->assertNull($fb['entrada']);
        $this->assertSame(8 * 3600, $fb['teorico']);
    }

    public function testNocheCuentaEnElDiaQueEmpieza(): void
    {
        $a = $this->usuario(['horario_id' => $this->horario([1, 2, 3, 4, 5], '22:00', '06:00')]);
        $this->fichar($a, 'entrada', '2030-01-07 22:00:00');
        $this->fichar($a, 'salida', '2030-01-08 06:00:00');
        $f = presencia_dia($this->db, [$a], '2030-01-07')[0];
        $this->assertSame([8 * 3600, false], [$f['trabajado'], $f['abierta']]);
        $this->assertNull(presencia_dia($this->db, [$a], '2030-01-08')[0]['entrada']);
    }

    public function testFechas(): void
    {
        $this->assertSame('1989-08-23', fecha_iso('23/08/1989'));
        $this->assertSame('1989-08-23', fecha_iso('1989-08-23'));
        $this->assertSame('1989-08-23', fecha_iso('32743'));
        $this->assertNull(fecha_iso('31/02/1990'));
        $this->assertNull(fecha_iso(''));
    }

    public function testAnuncioConVariosAdjuntos(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $u = $this->usuario();
        $id = crear_anuncio($this->db, $admin, 'Protocolos', 'Ver adjuntos', [$u['id']], [[str_repeat('a', 32) . '.pdf', 'noche.pdf'], [str_repeat('b', 32) . '.pdf', 'higiene.pdf']]);
        $this->assertSame(['noche.pdf', 'higiene.pdf'], array_column(adjuntos_anuncio($this->db, anuncio($this->db, $id)), 'nombre_original'));
        $this->assertSame(1, (int)array_column(array_filter(anuncios_para($this->db, $u), fn($c) => $c['id'] == $id), 'con_adjunto')[0]);
    }
}
