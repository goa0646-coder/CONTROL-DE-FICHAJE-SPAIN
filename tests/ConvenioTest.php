<?php

class ConvenioTest extends Base
{
    private function planificar(array $u, string $fecha, ?int $h, int $extra = 0, int $fm = 0): void
    {
        $this->db->prepare('REPLACE INTO planificacion (usuario_id, fecha, horario_id, extra, fuerza_mayor) VALUES (?, ?, ?, ?, ?)')->execute([$u['id'], $fecha, $h, $extra, $fm]);
    }

    private function reglas(array $r): array
    {
        return array_merge(REGLAS_ET, ['horas_anuales' => null], $r);
    }

    public function testReglasPorDefectoSonLasDelEstatuto(): void
    {
        $r = reglas($this->db);
        $this->assertSame([12, 36, 80], [$r['descanso_entre_jornadas_h'], $r['descanso_semanal_h'], $r['horas_extra_max']]);
        guardar_reglas($this->db, ['horas_extra_max' => '70', 'descanso_entre_jornadas_h' => 'mal']);
        $r = reglas($this->db);
        $this->assertSame([70, 12], [$r['horas_extra_max'], $r['descanso_entre_jornadas_h']]);
        guardar_reglas($this->db, []);
    }

    public function testDescansoEntreJornadasBloquea(): void
    {
        $tarde = $this->horario([1, 2, 3, 4, 5, 6, 7], '14:00', '22:00', 480);
        $manana = $this->horario([1, 2, 3, 4, 5, 6, 7], '06:00', '14:00', 480);
        $u = $this->usuario();
        $this->planificar($u, '2026-03-02', $tarde);
        $this->planificar($u, '2026-03-03', $manana);
        $b = bloqueos(comprobar_plan($this->db, $u, '2026-03-02', '2026-03-03', $this->reglas([])));
        $this->assertCount(1, $b);
        $this->assertSame(['2026-03-03', 'descanso_entre_jornadas_h'], [$b[0][0], $b[0][1]]);
        $this->assertStringContainsString('Solo 8 h', $b[0][2]);
        $this->planificar($u, '2026-03-03', $tarde);
        $this->assertSame([], bloqueos(comprobar_plan($this->db, $u, '2026-03-02', '2026-03-03', $this->reglas([]))));
    }

    public function testNocheQueCruzaLaMedianoche(): void
    {
        $noche = $this->horario([1, 2, 3, 4, 5, 6, 7], '22:00', '06:00', 480);
        $manana = $this->horario([1, 2, 3, 4, 5, 6, 7], '08:00', '16:00', 480);
        $u = $this->usuario();
        $this->planificar($u, '2026-03-02', $noche);   // termina el 3 a las 6:00
        $this->planificar($u, '2026-03-03', $manana);  // empieza el 3 a las 8:00 → 2 h
        $this->assertStringContainsString('Solo 2 h', bloqueos(comprobar_plan($this->db, $u, '2026-03-02', '2026-03-03', $this->reglas([])))[0][2]);
    }

    public function testJornadaDiariaYSemanal(): void
    {
        $largo = $this->horario([1, 2, 3, 4, 5, 6, 7], '08:00', '18:00', 600);
        $u = $this->usuario(['horario_id' => $largo]);
        $mal = comprobar_plan($this->db, $u, '2026-03-02', '2026-03-08', $this->reglas([]));
        $reglas = array_count_values(array_column($mal, 1));
        $this->assertSame(7, $reglas['jornada_max_diaria_h']);
        $this->assertSame(1, $reglas['descanso_semanal_h'], 'trabaja los 7 días');
        $this->assertSame(1, $reglas['horas_semana_media']);
        $this->assertSame('aviso', $mal[array_search('descanso_semanal_h', array_column($mal, 1))][3]);
    }

    public function testCumpleUnaSemanaNormal(): void
    {
        $u = $this->usuario(['horario_id' => $this->horario()]);
        $this->assertSame([], comprobar_plan($this->db, $u, '2026-03-02', '2026-03-08', $this->reglas([])));
    }

    public function testHorasAnualesAvisa(): void
    {
        $u = $this->usuario(['horario_id' => $this->horario(), 'alta_utc' => '2025-01-01 00:00:00']);
        $mal = comprobar_plan($this->db, $u, '2026-03-02', '2026-03-02', $this->reglas(['horas_anuales' => 1700]));
        $this->assertSame('horas_anuales', $mal[0][1]); // 2026: 261 laborables × 8 h = 2088 h
        $this->assertSame([], comprobar_plan($this->db, $u, '2026-03-02', '2026-03-02', $this->reglas(['horas_anuales' => 2100])));
    }

    public function testTurnoExtraNoSumaTeoricoYFuerzaMayorNoCuentaEnElTope(): void
    {
        $h = $this->horario([1, 2, 3, 4, 5, 6, 7], '08:00', '12:00', 240);
        $u = $this->usuario();
        $this->planificar($u, '2099-03-07', $h, 1);
        $this->planificar($u, '2099-03-08', $h, 1, 1);
        $c = calendario_teorico($this->db, $u, '2099-03-07', '2099-03-08');
        $this->assertSame([0, 240, true], [$c['2099-03-07']['minutos'], $c['2099-03-07']['turno'], $c['2099-03-07']['extra']]);
        $this->assertSame(4 * 3600, horas_extra_anio($this->db, $u, 2099), 'la de fuerza mayor no cuenta');
    }

    public function testPausaObligatoriaYDescansoReal(): void
    {
        $u = $this->usuario();
        $this->fichar($u, 'entrada', '2026-03-02 08:00');
        $this->fichar($u, 'salida', '2026-03-02 16:00');          // 8 h sin pausa
        $this->fichar($u, 'entrada', '2026-03-03 01:00');          // 9 h después
        $this->fichar($u, 'pausa_inicio', '2026-03-03 04:00');
        $this->fichar($u, 'pausa_fin', '2026-03-03 04:20');
        $this->fichar($u, 'salida', '2026-03-03 08:00');
        $mal = comprobar_real($this->db, $u, '2026-03-02', '2026-03-03', $this->reglas([]));
        $this->assertSame([['2026-03-02', 'pausa_minutos'], ['2026-03-03', 'descanso_entre_jornadas_h']], array_map(fn($m) => [$m[0], $m[1]], $mal));
    }

    public function testJornadaPartidaNoEsFaltaDeDescanso(): void
    {
        $u = $this->usuario();
        $this->fichar($u, 'entrada', '2026-03-02 08:00');
        $this->fichar($u, 'salida', '2026-03-02 12:00');
        $this->fichar($u, 'entrada', '2026-03-02 12:10');
        $this->fichar($u, 'salida', '2026-03-02 16:00');
        $this->assertSame([], comprobar_real($this->db, $u, '2026-03-02', '2026-03-02', $this->reglas([])));
    }

    public function testVacacionesEnDiasNaturales(): void
    {
        $this->db->exec("INSERT INTO tipos_ausencia (nombre, dias_anuales, computo) VALUES ('Vacaciones naturales', 30, 'naturales')");
        $tipo = (int)$this->db->lastInsertId();
        $u = $this->usuario(['horario_id' => $this->horario()]);
        pedir_ausencia($this->db, $u, ['tipo_id' => $tipo, 'desde' => '2026-08-03', 'hasta' => '2026-08-09'], $u['id']);
        $this->assertSame(7, saldos_ausencia($this->db, $u, 2026)[$tipo]['comprometido']);
    }

    public function testPreavisoDeVacaciones(): void
    {
        $this->assertNotNull(aviso_preaviso($this->db, ['tipo' => 'Vacaciones', 'desde' => date('Y-m-d', strtotime('+10 days'))]));
        $this->assertNull(aviso_preaviso($this->db, ['tipo' => 'Vacaciones', 'desde' => date('Y-m-d', strtotime('+90 days'))]));
        $this->assertNull(aviso_preaviso($this->db, ['tipo' => 'Baja por IT', 'desde' => date('Y-m-d')]));
    }
}
