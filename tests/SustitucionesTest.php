<?php

class SustitucionesTest extends Base
{
    private int $manana;
    private int $puesto;
    private string $dia = '2099-03-03'; // martes

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->exec('DELETE FROM necesidades');
        guardar_reglas($this->db, []);
        $this->manana = $this->horario([1, 2, 3, 4, 5, 6, 7], '06:00', '14:00', 480);
        $this->db->exec("INSERT INTO puestos (nombre) VALUES ('Gerocultor')");
        $this->puesto = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO necesidades (horario_id, puesto_id, dia, personas) VALUES (?, ?, 0, 1)')->execute([$this->manana, $this->puesto]);
    }

    protected function tearDown(): void
    {
        $this->db->exec('DELETE FROM necesidades');
        guardar_reglas($this->db, []);
    }

    private function persona(string $alta = '2099-01-01 00:00:00'): array
    {
        $u = $this->usuario(['alta_utc' => $alta]);
        $this->db->prepare('INSERT INTO usuarios_puestos VALUES (?, ?)')->execute([$u['id'], $this->puesto]);
        return $u;
    }

    private function baja(array $u): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $t = $this->db->query("SELECT id FROM tipos_ausencia WHERE nombre = 'Baja por IT'")->fetchColumn();
        resolver_ausencia($this->db, pedir_ausencia($this->db, $u, ['tipo_id' => $t, 'desde' => $this->dia, 'hasta' => $this->dia], $u['id']), $admin, true);
    }

    public function testBajaCandidatoOfertaYAceptacion(): void
    {
        $jefe = $this->usuario(['rol' => 'admin']);
        $titular = $this->persona();
        $nuevo = $this->persona('2099-02-01 00:00:00');
        $veterana = $this->persona('2098-01-01 00:00:00');
        $this->db->prepare('INSERT INTO planificacion (usuario_id, fecha, horario_id, puesto_id) VALUES (?, ?, ?, ?)')->execute([$titular['id'], $this->dia, $this->manana, $this->puesto]);
        $gente = [$titular, $nuevo, $veterana];
        $this->assertSame([], huecos($this->db, $gente, $this->dia, $this->dia));
        $this->baja($titular);
        $this->assertSame([[$this->dia, $this->manana, $this->puesto, 1]], huecos($this->db, $gente, $this->dia, $this->dia));

        $c = candidatos($this->db, $gente, $this->dia, $this->manana, $this->puesto);
        $this->assertSame([$veterana['id'], $nuevo['id']], array_map(fn($x) => $x['persona']['id'], $c), 'de baja fuera; a igualdad, más antigüedad primero');
        $this->assertSame(['extra', []], [$c[0]['tipo'], $c[0]['mal']]);

        $o1 = ofrecer_turno($this->db, $jefe, $veterana, $this->dia, $this->manana, $this->puesto, false, 'Baja');
        $o2 = ofrecer_turno($this->db, $jefe, $nuevo, $this->dia, $this->manana, $this->puesto, false, 'Baja');
        aceptar_oferta($this->db, $nuevo, $o2, 'codigo-email');
        $this->assertSame([], huecos($this->db, $gente, $this->dia, $this->dia), 'cubierto');
        $estados = $this->db->query("SELECT id, estado FROM ofertas_turno WHERE id IN ($o1, $o2)")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(['anulada', 'aceptada'], [$estados[$o1], $estados[$o2]]);
        $this->assertSame(64, strlen($this->db->query("SELECT hash FROM ofertas_turno WHERE id = $o2")->fetchColumn()));
        $cal = calendario_teorico($this->db, $nuevo, $this->dia, $this->dia)[$this->dia];
        $this->assertSame([true, 0, 480], [$cal['extra'], $cal['minutos'], $cal['turno']], 'cuenta como horas extra, no como jornada');
        $this->expectException(DomainException::class);
        aceptar_oferta($this->db, $veterana, $o1, 'codigo-email');
    }

    public function testTopeDeHorasExtraYFuerzaMayor(): void
    {
        guardar_reglas($this->db, ['horas_extra_max' => 4]);
        $jefe = $this->usuario(['rol' => 'admin']);
        $u = $this->persona();
        $c = candidatos($this->db, [$u], $this->dia, $this->manana, $this->puesto);
        $this->assertStringContainsString('h extra este año', $c[0]['mal'][0][2]);
        try {
            ofrecer_turno($this->db, $jefe, $u, $this->dia, $this->manana, $this->puesto, false, '');
            $this->fail('supera el tope');
        } catch (DomainException) {
        }
        $this->assertGreaterThan(0, ofrecer_turno($this->db, $jefe, $u, $this->dia, $this->manana, $this->puesto, true, 'Inundación'), 'fuerza mayor no cuenta en el tope');
    }

    public function testMoverRespetaElDescanso(): void
    {
        $jefe = $this->usuario(['rol' => 'admin']);
        $tarde = $this->horario([1, 2, 3, 4, 5, 6, 7], '14:00', '22:00', 480);
        $u = $this->persona();
        $this->db->prepare('INSERT INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, ?, ?), (?, ?, ?)')->execute([$u['id'], '2099-03-02', $tarde, $u['id'], $this->dia, $tarde]);
        $this->expectExceptionMessage('descanso');
        mover_turno($this->db, $jefe, $u, $this->dia, $this->manana, $this->puesto); // tarde el lunes → mañana el martes: 8 h
    }

    public function testCodigoDeUnSoloUso(): void
    {
        $u = $this->usuario();
        $this->assertFalse(enviar_codigo($this->db, $u, 'oferta:1'), 'sin correo configurado no se puede enviar');
        $this->db->prepare('INSERT INTO codigos (usuario_id, proposito, hash, expira_utc) VALUES (?, ?, ?, ?)')->execute([$u['id'], 'oferta:1', hash('sha256', '123456'), gmdate('Y-m-d H:i:s', time() + 600)]);
        $this->assertFalse(comprobar_codigo($this->db, $u, 'oferta:2', '123456'), 'otro propósito');
        $this->assertFalse(comprobar_codigo($this->db, $u, 'oferta:1', '000000'));
        $this->assertTrue(comprobar_codigo($this->db, $u, 'oferta:1', '123456'));
        $this->assertFalse(comprobar_codigo($this->db, $u, 'oferta:1', '123456'), 'solo una vez');
        $this->db->prepare('INSERT INTO codigos (usuario_id, proposito, hash, expira_utc, intentos) VALUES (?, ?, ?, ?, 5)')->execute([$u['id'], 'oferta:3', hash('sha256', '111111'), gmdate('Y-m-d H:i:s', time() + 600)]);
        $this->assertFalse(comprobar_codigo($this->db, $u, 'oferta:3', '111111'), 'bloqueado tras 5 intentos');
    }
}
