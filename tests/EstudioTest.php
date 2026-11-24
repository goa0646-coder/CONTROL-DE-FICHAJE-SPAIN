<?php

class EstudioTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->exec('DELETE FROM necesidades');
    }

    protected function tearDown(): void
    {
        $this->db->exec('DELETE FROM necesidades');
    }

    private function grupo(int $personas_turno): array
    {
        $s = uniqid();
        consulta($this->db, 'INSERT INTO departamentos (nombre) VALUES (?)', ["DEP $s"]);
        $dep = (int)$this->db->lastInsertId();
        guardar_turnos($this->db, [["M $s", 'M', '#f59e0b', '07:00', '15:00', '8:00', "DEP $s"]]);
        $hid = (int)consulta($this->db, 'SELECT id FROM horarios WHERE nombre = ?', ["M $s"])->fetchColumn();
        consulta($this->db, 'INSERT INTO necesidades (horario_id, dia, personas) VALUES (?, 0, ?)', [$hid, $personas_turno]);
        return grupos_estudio($this->db)["d$dep"];
    }

    public function testMinimoDePersonasParaUnAnio(): void
    {
        $g = $this->grupo(2);
        $nec = necesidades_periodo($this->db, '2027-01-01', '2027-12-31');
        $t = microtime(true);
        $e = minimo_personas($this->db, $g, '2027-01-01', '2027-12-31', $nec, 30);
        $this->assertLessThan(30, microtime(true) - $t, 'tiene que caber en el tiempo de un hosting');
        // 2 personas × 8 h × 365 días = 5.840 h; con el tope anual del convenio hacen falta al menos 4.
        $this->assertSame(5840 * 60, $e['total']);
        $this->assertGreaterThanOrEqual($e['cota'], $e['minimo']);
        $this->assertLessThanOrEqual($e['cota'] + 3, $e['minimo']);
        $this->assertSame([], $e['faltan']);
        // Con una persona menos que el mínimo, no se cubre.
        [$p, $b, $pp] = plantilla_ficticia($g, $e['minimo'] - 1, rango_fechas('2027-01-01', '2027-12-31'), 30, []);
        $menos = planificar($this->db, $p, '2027-01-01', '2027-12-31', ['horarios' => $g['horarios'], 'necesidades' => $nec, 'modo' => 'minimo', 'bloqueados' => $b, 'puestos' => $pp]);
        $this->assertNotEmpty($menos['faltan']);
    }

    public function testNadieSuperaLasHorasAnualesNiTrabajaMasDe5DiasSeguidos(): void
    {
        $antes = $this->db->query('SELECT horas_anuales FROM empresa WHERE id = 1')->fetchColumn();
        $this->db->exec('INSERT IGNORE INTO empresa (id, nombre, cif) VALUES (1, "E", "")');
        $this->db->exec('UPDATE empresa SET horas_anuales = 1762 WHERE id = 1'); // VI Convenio de residencias de Galicia
        $g = $this->grupo(1);
        $nec = necesidades_periodo($this->db, '2027-01-01', '2027-12-31');
        $e = minimo_personas($this->db, $g, '2027-01-01', '2027-12-31', $nec, 0);
        [$p, $b, $pp] = plantilla_ficticia($g, $e['minimo'], rango_fechas('2027-01-01', '2027-12-31'), 0, []);
        $res = planificar($this->db, $p, '2027-01-01', '2027-12-31', ['horarios' => $g['horarios'], 'necesidades' => $nec, 'modo' => 'minimo', 'bloqueados' => $b, 'puestos' => $pp]);
        $tope = reglas($this->db)['horas_anuales'];
        $this->assertSame(1762, (int)$tope);
        foreach ($res['plan'] as $dias) {
            $seguidos = $max = 0;
            foreach ($dias as $x) { $seguidos = $x ? $seguidos + 1 : 0; $max = max($max, $seguidos); }
            $this->assertLessThanOrEqual(5, $max);
        }
        foreach ($res['min'] as $m) $this->assertLessThanOrEqual($tope * 60, $m);
        $this->assertSame([], $res['faltan']);
        $this->db->prepare('UPDATE empresa SET horas_anuales = ? WHERE id = 1')->execute([$antes ?: null]);
    }

    public function testTurnoImposibleSeExplica(): void
    {
        $s = uniqid();
        guardar_turnos($this->db, [["LARGO $s", 'L', '', '07:00', '19:00', '12:00', '']]);
        $hid = (int)consulta($this->db, 'SELECT id FROM horarios WHERE nombre = ?', ["LARGO $s"])->fetchColumn();
        consulta($this->db, 'INSERT INTO necesidades (horario_id, dia, personas) VALUES (?, 0, 1)', [$hid]);
        $g = grupos_estudio($this->db)['sin'];
        $e = minimo_personas($this->db, $g, '2027-01-04', '2027-01-10', necesidades_periodo($this->db, '2027-01-04', '2027-01-10'));
        $this->assertNull($e['minimo']);
        $this->assertArrayHasKey('el turno dura más que la jornada máxima diaria', $e['motivos']);
    }

    public function testCalendarioAnualConElMinimoDePersonasYVacaciones(): void
    {
        $g = $this->grupo(1);
        $gente = [];
        for ($i = 0; $i < 6; $i++) $gente[] = $this->usuario(['departamento_id' => $g['departamento_id'], 'alta_utc' => "201$i-01-01 00:00:00"]);
        $res = plan_anual($this->db, [$g], '2027-01-01', '2027-12-31', 'minimo', 30);
        $this->assertSame([], $res['faltan']);
        $usadas = array_keys(array_filter($res['min']));
        $this->assertLessThan(6, count($usadas), 'no hacen falta las 6');
        $this->assertContains((int)$gente[0]['id'], $usadas, 'primero las de más antigüedad');
        $libre = array_values(array_diff(array_column($gente, 'id'), $usadas))[0];
        $this->assertSame([], array_filter($res['plan'][$libre]), 'la que sobra queda sin turnos');
        // Sus vacaciones: 30 días seguidos sin turno.
        [$a, $b] = $res['vacaciones'][$gente[0]['id']];
        $this->assertSame(29, (int)((strtotime($b) - strtotime($a)) / 86400));
        foreach (rango_fechas($a, $b) as $f) $this->assertNull($res['plan'][$gente[0]['id']][$f]);
        $justo = plan_anual($this->db, [$g], '2027-01-01', '2027-12-31', 'justo', 30);
        $this->assertCount(6, array_filter($justo['min']), 'reparto justo: trabajan todas');
    }

    public function testPermisosDelEstatutoSinDuplicar(): void
    {
        cargar_tipos_ausencia($this->db, PERMISOS_ET);
        $nombres = array_column(tipos_ausencia($this->db), 'nombre');
        foreach (PERMISOS_ET as [$n]) $this->assertContains($n, $nombres);
        $this->assertSame(0, cargar_tipos_ausencia($this->db, PERMISOS_ET));
        $t = consulta($this->db, "SELECT * FROM tipos_ausencia WHERE nombre LIKE 'Deber inexcusable%'")->fetch();
        $this->assertSame([1, 1, 1], [(int)$t['por_horas'], (int)$t['retribuida'], (int)$t['justificante']]);
    }
}
