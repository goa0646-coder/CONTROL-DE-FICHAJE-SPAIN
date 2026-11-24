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
        $this->assertArrayNotHasKey($op['id'], $plan, 'no es encargado: no se toca su horario');
        $this->assertSame($this->encargado, $plan[$jefa['id']]['2099-03-02']['puesto_id']);
        $sin = $this->usuario(['alta_utc' => '2099-01-01 00:00:00']);
        $this->assertArrayNotHasKey($sin['id'], generar_cuadrante($this->db, [$jefa, $sin], '2099-03-02', '2099-03-02'), 'sin puestos no se toca su horario');
    }

    public function testCompletaLaJornadaYTurnoDeOtroDepartamento(): void
    {
        // Mínimo 1 de mañana pero 3 personas: además de cubrirlo, cada una llega a sus 40 h (5 turnos).
        $this->necesidad($this->manana, null, 1);
        $gente = array_map(fn() => $this->persona([]), range(1, 3));
        $r = planificar($this->db, $gente, '2099-03-02', '2099-03-08', ['completar' => true]);
        foreach ($gente as $u) $this->assertSame(5, count(array_filter($r['plan'][$u['id']])), 'completa 40 h');
        $this->assertSame([], $r['faltan']);
        guardar_cuadrante($this->db, $r['plan']);
        foreach ($gente as $u) $this->assertSame([], bloqueos(comprobar_plan($this->db, $u, '2099-03-02', '2099-03-08')));
        // Sin completar solo se reparte el mínimo.
        $solo = planificar($this->db, $gente, '2099-03-09', '2099-03-15');
        $this->assertSame(7, array_sum(array_map(fn($d) => count(array_filter($d)), $solo['plan'])));
        // Turno de un departamento sin nadie: no lo hace cualquiera, y dice por qué.
        $this->db->exec("INSERT INTO departamentos (nombre) VALUES ('Coordinación')");
        $dep = (int)$this->db->lastInsertId();
        $this->db->prepare('UPDATE horarios SET departamento_id = ? WHERE id = ?')->execute([$dep, $this->tarde]);
        $this->db->exec('DELETE FROM necesidades');
        $this->necesidad($this->tarde, null, 1);
        $r = planificar($this->db, $gente, '2099-04-06', '2099-04-06', ['completar' => true]);
        $this->assertSame([], $r['plan']);
        $this->assertStringContainsString('Coordinación', $r['faltan'][0][4]);
    }

    public function testCadaPuestoSusTurnosYDescansoConLoQueYaHay(): void
    {
        // Sin departamentos: «AUXILIAR MAÑANA» es de quien tiene el puesto auxiliar (por el nombre); cocina no lo hace.
        $s = uniqid();
        $this->db->exec("INSERT INTO puestos (nombre) VALUES ('AUXILIAR /GEROCULTOR $s'), ('AYUDANTE DE COCINA $s'), ('LIMPIADOR $s')");
        $aux = (int)$this->db->lastInsertId();
        $m = $this->horario([1, 2, 3, 4, 5, 6, 7], '07:00', '15:00', 480);
        $this->db->prepare('UPDATE horarios SET nombre = ? WHERE id = ?')->execute(["AUXILIAR MAÑANA $s", $m]);
        $limp = $this->horario([1, 2, 3, 4, 5, 6, 7], '08:00', '15:00', 420);
        $this->db->prepare('UPDATE horarios SET nombre = ? WHERE id = ?')->execute(["LIMPIEZA MAÑANA $s", $limp]);
        $this->necesidad($m, null, 1);
        $a = $this->persona([$aux]);
        $cocina = $this->persona([$aux + 1]);
        $limpia = $this->persona([$aux + 2]);
        $r = planificar($this->db, [$a, $cocina, $limpia], '2099-03-02', '2099-03-08', ['completar' => true]);
        $this->assertArrayNotHasKey($cocina['id'], $r['plan'], 'cocina no hace turnos de auxiliar');
        $this->assertSame([$m], array_values(array_unique(array_column(array_filter($r['plan'][$a['id']]), 'horario_id'))));
        $this->assertSame([$limp], array_values(array_unique(array_column(array_filter($r['plan'][$limpia['id']]), 'horario_id'))), 'limpieza con su turno');
        $this->assertCount(5, array_filter($r['plan'][$limpia['id']]));
        // El día siguiente al periodo ya tiene mañana a las 7: el último día no se le pone una noche que acaba a las 7.
        $n = $this->horario([1, 2, 3, 4, 5, 6, 7], '23:00', '07:00', 480);
        $this->db->exec('DELETE FROM necesidades');
        $this->necesidad($n, null, 1);
        consulta($this->db, 'INSERT INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, ?, ?)', [$a['id'], '2099-04-07', $m]);
        $r = planificar($this->db, [$a], '2099-04-06', '2099-04-06');
        $this->assertNull($r['plan'][$a['id']]['2099-04-06']);
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
