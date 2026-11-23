<?php

class RatiosTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        // Cada prueba empieza sin reglas (la tabla es de configuración).
        $this->db->exec('DELETE FROM necesidades');
    }

    protected function tearDown(): void
    {
        $this->db->exec('DELETE FROM necesidades');
    }

    private function regla(int $h, int $n, array $extra = []): void
    {
        $extra += ['desde' => null, 'hasta' => null, 'festivos' => 1, 'activo' => 1];
        consulta($this->db, 'INSERT INTO necesidades (nombre, horario_id, dia, personas, desde, hasta, festivos, activo) VALUES (?, ?, 0, ?, ?, ?, ?, ?)',
            ['R' . uniqid(), $h, $n, $extra['desde'], $extra['hasta'], $extra['festivos'], $extra['activo']]);
    }

    public function testVigenciaActivoYFestivos(): void
    {
        $h = $this->horario([1, 2, 3, 4, 5, 6, 7]);
        $this->regla($h, 3, ['desde' => '2030-03-01', 'hasta' => '2030-03-31']);
        $this->regla($h, 9, ['activo' => 0]);
        $this->assertSame([], necesidades_dia($this->db, '2030-02-28'), 'fuera de vigencia');
        $this->assertSame([[$h, null, 3]], necesidades_dia($this->db, '2030-03-10'));
        $this->db->exec('DELETE FROM necesidades');
        $this->regla($h, 2, ['festivos' => 0]);
        consulta($this->db, "INSERT INTO festivos (fecha, nombre) VALUES ('2030-05-01', 'Trabajo')");
        $this->assertSame([], necesidades_dia($this->db, '2030-05-01'), 'no se aplica en festivo');
        $this->assertCount(1, necesidades_dia($this->db, '2030-05-02'));
        $this->db->exec("DELETE FROM festivos WHERE fecha = '2030-05-01'");
    }

    public function testCargarRatiosPorNombreDeTurno(): void
    {
        $this->db->prepare('INSERT INTO horarios (nombre, flexible, margen_min) VALUES (?, 0, 10)')->execute(['Auxiliar noche ' . ($s = uniqid())]);
        $hid = (int)$this->db->lastInsertId();
        [$creadas, $faltan] = cargar_ratios($this->db, ['RATIO NOCHE' => ["AUXILIAR NOCHE $s", 2], 'RATIO X' => ['NO EXISTE ' . $s, 1]]);
        $this->assertSame(['RATIO NOCHE'], $creadas);
        $this->assertSame(['NO EXISTE ' . $s], $faltan);
        $this->assertSame([[$hid, null, 2]], necesidades_dia($this->db, '2030-01-07'));
        $this->assertSame([[], ['NO EXISTE ' . $s]], cargar_ratios($this->db, ['RATIO NOCHE' => ["AUXILIAR NOCHE $s", 2], 'RATIO X' => ['NO EXISTE ' . $s, 1]]), 'no duplica');
    }

    public function testAvisaAAdministracionSiFaltaGente(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $h = $this->horario([1, 2, 3, 4, 5, 6, 7]);
        $this->regla($h, 250); // nadie llega a 250 personas
        $hoy = '2030-06-10 08:00:00';
        $this->assertGreaterThan(0, revisar_ratios($this->db, $hoy));
        $this->assertNotEmpty(consulta($this->db, "SELECT 1 FROM avisos WHERE usuario_id = ? AND clave LIKE 'ratio:2030-06-10:%'", [$admin['id']])->fetchColumn());
        $this->assertSame(0, revisar_ratios($this->db, $hoy), 'una vez por día y turno');
    }
}
