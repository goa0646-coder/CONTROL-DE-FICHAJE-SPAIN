<?php

class GaliciaTest extends Base
{
    protected function tearDown(): void
    {
        guardar_reglas($this->db, []);
        $this->db->exec('UPDATE empresa SET horas_anuales = NULL');
        $this->db->exec("UPDATE tipos_ausencia SET dias_anuales = 22 WHERE nombre = 'Vacaciones'");
    }

    private function r(array $x = []): array
    {
        return array_merge(REGLAS_ET, ['horas_anuales' => null], PLANTILLA_GALICIA_RESIDENCIAS['reglas'], $x);
    }

    public function testPlantillaCargaReglasYTipos(): void
    {
        $this->db->exec("INSERT IGNORE INTO empresa (id, nombre) VALUES (1, 'E')");
        aplicar_plantilla($this->db, PLANTILLA_GALICIA_RESIDENCIAS);
        $r = reglas($this->db);
        $this->assertSame([60, 20, 7, 1762], [$r['horas_extra_max'], $r['pausa_minutos'], $r['noche_hasta_h'], (int)$r['horas_anuales']]);
        $t = $this->db->query("SELECT nombre, dias_anuales, por_horas FROM tipos_ausencia WHERE nombre IN ('Vacaciones', 'Libre disposición', 'Lactancia')")->fetchAll(PDO::FETCH_UNIQUE);
        $this->assertSame(['26.0', '6.0', null], [$t['Vacaciones']['dias_anuales'], $t['Libre disposición']['dias_anuales'], $t['Lactancia']['dias_anuales']]);
        $this->assertSame(1, (int)$t['Lactancia']['por_horas']);
        $this->assertSame(1, (int)$this->db->query("SELECT retribuida FROM motivos_pausa WHERE nombre = 'Bocadillo'")->fetchColumn());
        aplicar_plantilla($this->db, PLANTILLA_GALICIA_RESIDENCIAS); // dos veces no duplica
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM tipos_ausencia WHERE nombre = 'Libre disposición'")->fetchColumn());
    }

    public function testNocheHastaLas7(): void
    {
        $t = [[strtotime(a_utc('2026-03-02 22:00') . ' UTC'), strtotime(a_utc('2026-03-03 08:00') . ' UTC')]];
        $this->assertSame(8 * 3600, segundos_nocturnos($t));
        $this->assertSame(9 * 3600, segundos_nocturnos($t, 22, 7));
    }

    public function testDomingoCadaCuatroSemanas(): void
    {
        $todos = $this->horario([1, 2, 3, 4, 5, 6, 7], '08:00', '15:00', 420);
        $u = $this->usuario();
        foreach (['2099-03-01', '2099-03-08', '2099-03-15', '2099-03-22'] as $dom) {
            $this->db->prepare('INSERT INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, ?, ?)')->execute([$u['id'], $dom, $todos]);
        }
        $mal = array_column(comprobar_plan($this->db, $u, '2099-03-01', '2099-03-22', $this->r()), 1);
        $this->assertContains('domingo_cada_semanas', $mal);
        $this->db->exec("UPDATE planificacion SET horario_id = NULL WHERE usuario_id = {$u['id']} AND fecha = '2099-03-15'");
        $this->assertNotContains('domingo_cada_semanas', array_column(comprobar_plan($this->db, $u, '2099-03-01', '2099-03-22', $this->r()), 1));
    }

    public function testSuperarNueveHorasConAcuerdoEsAviso(): void
    {
        $largo = $this->horario([1], '08:00', '18:30', 600);
        $u = $this->usuario(['horario_id' => $largo]);
        $m = array_values(array_filter(comprobar_plan($this->db, $u, '2099-03-02', '2099-03-02', $this->r()), fn($x) => $x[1] === 'jornada_max_diaria_h'))[0];
        $this->assertSame('aviso', $m[3]);
        $m = array_values(array_filter(comprobar_plan($this->db, $u, '2099-03-02', '2099-03-02', $this->r(['superar_jornada_con_acuerdo' => 0])), fn($x) => $x[1] === 'jornada_max_diaria_h'))[0];
        $this->assertSame('bloqueo', $m[3]);
    }

    public function testJornadaPartidaConMenosDeUnaHora(): void
    {
        $u = $this->usuario();
        $this->fichar($u, 'entrada', '2026-03-02 08:00');
        $this->fichar($u, 'salida', '2026-03-02 12:00');
        $this->fichar($u, 'entrada', '2026-03-02 12:30');
        $this->fichar($u, 'salida', '2026-03-02 16:00');
        $mal = comprobar_real($this->db, $u, '2026-03-02', '2026-03-02', $this->r());
        $this->assertSame(['jornada_partida_pausa_h'], array_column($mal, 1));
        $this->assertStringContainsString('30 min', $mal[0][2]);
    }

    public function testRetrasosFestivosDomingosYNavidad(): void
    {
        $c = $this->centro();
        $u = $this->usuario(['horario_id' => $this->horario([1, 2, 3, 4, 5, 6, 7], '08:00', '15:00', 420), 'centro_id' => $c]);
        $this->db->prepare('INSERT INTO festivos (centro_id, fecha, nombre) VALUES (NULL, ?, ?)')->execute(['2025-12-25', 'Navidad']);
        foreach (['2025-12-01' => '08:05', '2025-12-02' => '08:20', '2025-12-03' => '08:30', '2025-12-04' => '08:40', '2025-12-07' => '07:58', '2025-12-25' => '08:00'] as $f => $h) {
            $this->fichar($u, 'entrada', "$f $h");
            $this->fichar($u, 'salida', "$f 15:00");
        }
        $fila = informe($this->db, [$u], '2025-12-01', '2025-12-31')[0];
        $this->assertSame(['2025-12' => 3], $fila['retrasos'], 'margen de 10 min: 8:05 no cuenta');
        $this->assertSame('leve', nivel_retrasos($fila['retrasos'], $this->r()));
        $this->assertSame([1, 1, 1], [$fila['festivos_trabajados'], $fila['domingos'], $fila['turnos_especiales']]);
    }

    public function testVacacionesNoEmpiezanEnDescanso(): void
    {
        $u = $this->usuario(['horario_id' => $this->horario()]);
        $this->assertStringContainsString('día de descanso', aviso_preaviso($this->db, ['tipo' => 'Vacaciones', 'desde' => '2099-03-07', 'usuario_id' => $u['id']]));
        $this->assertNull(aviso_preaviso($this->db, ['tipo' => 'Vacaciones', 'desde' => '2099-03-09', 'usuario_id' => $u['id']]));
    }
}
