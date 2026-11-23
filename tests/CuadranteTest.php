<?php

class CuadranteTest extends Base
{
    private int $manana;
    private int $tarde;
    private int $operario;
    private int $encargado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->exec('DELETE FROM necesidades');
        $this->manana = $this->horario([1, 2, 3, 4, 5, 6, 7], '06:00', '14:00', 480);
        $this->tarde = $this->horario([1, 2, 3, 4, 5, 6, 7], '14:00', '22:00', 480);
        $this->db->exec("INSERT INTO puestos (nombre) VALUES ('Operario'), ('Encargado')");
        $this->operario = (int)$this->db->lastInsertId(); // con varias filas, MySQL da el id de la primera
        $this->encargado = $this->operario + 1;
    }

    private function necesidad(int $h, ?int $p, int $n, int $dia = 0): void
    {
        $this->db->prepare('INSERT INTO necesidades (horario_id, puesto_id, dia, personas) VALUES (?, ?, ?, ?)')->execute([$h, $p, $dia, $n]);
    }

    private function persona(array $puestos): array
    {
        $u = $this->usuario(['alta_utc' => '2099-01-01 00:00:00']);
        foreach ($puestos as $p) $this->db->prepare('INSERT INTO usuarios_puestos VALUES (?, ?)')->execute([$u['id'], $p]);
        return $u;
    }

    public function testCumpleReglasRepartoJustoYDetectaHuecos(): void
    {
        $this->necesidad($this->manana, $this->operario, 2);
        $this->necesidad($this->tarde, $this->operario, 1);
        $gente = array_map(fn() => $this->persona([$this->operario]), range(1, 4));
        // Semana del lunes 2 de marzo de 2099.
        $plan = generar_cuadrante($this->db, $gente, '2099-03-02', '2099-03-08');
        guardar_cuadrante($this->db, $plan);
        $turnos = [];
        foreach ($gente as $u) {
            $turnos[] = count(array_filter($plan[$u['id']]));
            $this->assertSame([], bloqueos(comprobar_plan($this->db, $u, '2099-03-02', '2099-03-08')), 'nada que incumpla');
            $this->assertNotContains('descanso_semanal_h', array_column(comprobar_plan($this->db, $u, '2099-03-02', '2099-03-08'), 1));
        }
        $this->assertLessThanOrEqual(5, max($turnos), 'máximo 40 h: 5 turnos de 8 h');
        $this->assertLessThanOrEqual(1, max($turnos) - min($turnos), 'reparto justo');
        // 21 turnos necesarios, 4 personas × 5 = 20 → falta 1.
        $this->assertSame(1, array_sum(array_column(huecos($this->db, $gente, '2099-03-02', '2099-03-08'), 3)));
    }

    public function testPuestoObligatorioYBajasNoSeAsignan(): void
    {
        $this->necesidad($this->manana, $this->encargado, 1);
        $jefa = $this->persona([$this->encargado, $this->operario]);
        $baja = $this->persona([$this->encargado]);
        $op = $this->persona([$this->operario]);
        $admin = $this->usuario(['rol' => 'admin']);
        $tipo = $this->db->query("SELECT id FROM tipos_ausencia WHERE nombre = 'Baja por IT'")->fetchColumn();
        resolver_ausencia($this->db, pedir_ausencia($this->db, $baja, ['tipo_id' => $tipo, 'desde' => '2099-03-02', 'hasta' => '2099-03-08'], $baja['id']), $admin, true);
        $plan = generar_cuadrante($this->db, [$jefa, $baja, $op], '2099-03-02', '2099-03-04');
        $this->assertSame(3, count(array_filter($plan[$jefa['id']])));
        $this->assertSame([], array_filter($plan[$baja['id']]), 'de baja');
        $this->assertSame([], array_filter($plan[$op['id']]), 'no es encargado');
        $this->assertSame($this->encargado, $plan[$jefa['id']]['2099-03-02']['puesto_id']);
        $sin = $this->usuario(['alta_utc' => '2099-01-01 00:00:00']);
        $this->assertArrayNotHasKey($sin['id'], generar_cuadrante($this->db, [$jefa, $sin], '2099-03-02', '2099-03-02'), 'sin puestos no se toca su horario');
    }

    public function testNoPoneMananaTrasTarde(): void
    {
        // Solo una persona: no puede hacer tarde y mañana seguidas.
        $this->necesidad($this->tarde, null, 1, 1);   // lunes tarde
        $this->necesidad($this->manana, null, 1, 2);  // martes mañana
        $u = $this->persona([]);
        $plan = generar_cuadrante($this->db, [$u], '2099-03-02', '2099-03-03');
        $this->assertSame($this->tarde, $plan[$u['id']]['2099-03-02']['horario_id']);
        $this->assertNull($plan[$u['id']]['2099-03-03'], '8 h de descanso: no se asigna');
        guardar_cuadrante($this->db, $plan);
        $this->assertSame([['2099-03-03', $this->manana, null, 1]], huecos($this->db, [$u], '2099-03-03', '2099-03-03'));
    }
}
