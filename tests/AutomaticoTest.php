<?php

class AutomaticoTest extends Base
{
    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM ajustes WHERE clave IN ('valoraciones_admin', 'inspeccion_enlace')");
    }

    private function con(array $u, array $campos): array
    {
        foreach ($campos as $k => $v) consulta($this->db, "UPDATE usuarios SET $k = ? WHERE id = ?", [$v, $u['id']]);
        return usuario($this->db, (int)$u['id']);
    }

    public function testPausaAutomaticaCuentaComoPausaSinQuitarHoras(): void
    {
        $u = $this->con($this->usuario(['horario_id' => $this->horario([1, 2, 3, 4, 5], '07:00', '15:00')]), ['pausa_auto_min' => 15]);
        $this->fichar($u, 'entrada', '2030-01-07 07:00:00');
        $this->fichar($u, 'salida', '2030-01-07 15:00:00');
        $d = resumen($this->db, $u, '2030-01-07', '2030-01-07')['dias']['2030-01-07'];
        $this->assertSame(8 * 3600, $d['trabajado'], 'computa como trabajo');
        $this->assertSame(900, $d['jornadas'][0]['pausa_auto']);
        $this->assertSame([], array_filter(comprobar_plan($this->db, $u, '2030-01-07', '2030-01-07'), fn($m) => $m[1] === 'pausa_minutos'), 'cumple el descanso');
        $sin = $this->con($u, ['pausa_auto_min' => 0]);
        $this->assertArrayNotHasKey('pausa_auto', resumen($this->db, $sin, '2030-01-07', '2030-01-07')['dias']['2030-01-07']['jornadas'][0]);
    }

    public function testFichajeAutomaticoEntraYSaleALaHoraDelTurno(): void
    {
        $u = $this->con($this->usuario(['horario_id' => $this->horario([1, 2, 3, 4, 5], '07:00', '15:00')]), ['fichaje_auto' => 1]);
        fichajes_automaticos($this->db, a_utc('2030-01-07 06:50:00'));
        $this->assertSame('fuera', estado_actual($this->db, (int)$u['id']), 'antes del turno no');
        fichajes_automaticos($this->db, a_utc('2030-01-07 07:04:00'));
        $this->assertSame('dentro', estado_actual($this->db, (int)$u['id']));
        fichajes_automaticos($this->db, a_utc('2030-01-07 10:00:00'));
        cerrar_jornadas($this->db, a_utc('2030-01-07 15:40:00'));
        $this->assertSame('dentro', estado_actual($this->db, (int)$u['id']), 'el cierre de olvidos no lo toca');
        fichajes_automaticos($this->db, a_utc('2030-01-07 15:03:00'));
        $f = fichajes_de($this->db, (int)$u['id']);
        $this->assertSame([['entrada', '07:00', 'automatico'], ['salida', '15:00', 'automatico']], array_map(fn($x) => [$x['tipo'], local($x['momento_utc'], 'H:i'), $x['origen']], $f));
        fichajes_automaticos($this->db, a_utc('2030-01-07 16:00:00'));
        $this->assertCount(2, fichajes_de($this->db, (int)$u['id']), 'no repite');
        fichajes_automaticos($this->db, a_utc('2030-01-12 10:00:00'));
        $this->assertCount(2, fichajes_de($this->db, (int)$u['id']), 'sábado sin turno');
        $this->assertNull(verificar_cadena(fichajes_de($this->db, (int)$u['id'])));
    }

    public function testValoracionSoloLaVeQuienLaActiva(): void
    {
        $this->db->exec("DELETE FROM ajustes WHERE clave = 'valoraciones_admin'");
        $a = $this->usuario(['rol' => 'admin']);
        $b = $this->usuario(['rol' => 'admin']);
        $insp = $this->usuario(['rol' => 'inspeccion']);
        $w = $this->usuario();
        $this->assertFalse(ve_valoraciones($this->db, $a));
        activar_valoraciones($this->db, $a, true);
        valorar($this->db, $a, (int)$w['id'], 4, 'bien');
        valorar($this->db, $a, (int)$w['id'], 5, '');
        $this->assertSame(4.5, valoraciones($this->db, $a, (int)$w['id'])['media']);
        foreach ([$b, $insp, $w] as $x) {
            $this->assertFalse(ve_valoraciones($this->db, $x));
            $this->assertSame([], valoraciones($this->db, $x, (int)$w['id'])['lista']);
        }
        $this->expectException(DomainException::class);
        activar_valoraciones($this->db, $b, true);
    }

    public function testEvaluacionConApuntesYSinContarBajas(): void
    {
        $this->db->exec("DELETE FROM ajustes WHERE clave = 'valoraciones_admin'");
        $a = $this->usuario(['rol' => 'admin']);
        $w = $this->usuario(['horario_id' => $this->horario([1, 2, 3, 4, 5], '07:00', '15:00'), 'alta_utc' => gmdate('Y-m-d H:i:s', strtotime('-20 days'))]);
        $this->assertNull(evaluacion($this->db, $a, $w), 'oculta hasta activarla');
        activar_valoraciones($this->db, $a, true);
        valorar_apartados($this->db, $a, (int)$w['id'], ['trato' => 5, 'tecnica' => 5, 'equipo' => 5, 'iniciativa' => 5, 'actitud' => 5], '');
        $this->assertNull(evaluacion($this->db, $a, $w)['objetiva']['confianza'], 'aún no ficha en INDA: no hay faltas');
        $lunes = date('Y-m-d', strtotime('monday this week -2 weeks'));
        $this->fichar($w, 'entrada', "$lunes 07:00:00");
        $this->fichar($w, 'salida', "$lunes 15:00:00");
        $ev = evaluacion($this->db, $a, $w);
        $this->assertSame(1, $ev['objetiva']['confianza'], 'desde que ficha, los días de turno sin fichar son faltas');
        $antes = $ev['final'];
        apuntar($this->db, $a, (int)$w['id'], 'incidencia', gmdate('Y-m-d'), 'llegó tarde');
        $this->assertSame($antes, evaluacion($this->db, $a, $w)['final'], 'un apunte no resta');
        apuntar($this->db, $a, (int)$w['id'], 'amonestacion', gmdate('Y-m-d'), 'otra');
        $this->assertEqualsWithDelta(max(1.0, $antes - 1), evaluacion($this->db, $a, $w)['final'], 0.051, 'dos apuntes restan 1 estrella');
        // Baja aprobada todo el periodo: ya no son faltas.
        $tipo = (int)$this->db->query("SELECT id FROM tipos_ausencia ORDER BY id LIMIT 1")->fetchColumn();
        consulta($this->db, "INSERT INTO ausencias (usuario_id, tipo_id, desde, hasta, estado, pedida_utc, pedida_por) VALUES (?, ?, ?, ?, 'aprobada', ?, ?)", [$w['id'], $tipo, gmdate('Y-m-d', strtotime('-25 days')), gmdate('Y-m-d'), ahora(), $a['id']]);
        $this->assertNull(evaluacion_objetiva($this->db, $w)['confianza'], 'de baja: no hay turnos que evaluar');
        $this->assertSame([], apuntes_de($this->db, $this->usuario(['rol' => 'admin']), (int)$w['id']), 'otro admin no ve los apuntes');
    }

    public function testCalidadEquilibraElTurnoSiPuede(): void
    {
        $this->db->exec('DELETE FROM necesidades');
        $h = $this->horario([1, 2, 3, 4, 5, 6, 7], '07:00', '15:00');
        $this->db->prepare('INSERT INTO necesidades (horario_id, puesto_id, dia, personas) VALUES (?, NULL, 0, 2)')->execute([$h]);
        $g = array_map(fn() => $this->usuario(['alta_utc' => '2099-01-01 00:00:00']), range(1, 4));
        $notas = [(int)$g[0]['id'] => 1.0, (int)$g[1]['id'] => 1.0, (int)$g[2]['id'] => 5.0, (int)$g[3]['id'] => 5.0];
        $r = planificar($this->db, $g, '2099-08-03', '2099-08-03', ['calidad' => $notas]);
        $dentro = array_keys(array_filter(array_map(fn($d) => $d['2099-08-03'] ?? null, $r['plan'])));
        $this->assertGreaterThanOrEqual(3, array_sum(array_map(fn($x) => $notas[$x], $dentro)) / count($dentro));
        $this->assertSame([], $r['calidad_baja']);
    }

    public function testEnlaceDeInspeccionSinContrasena(): void
    {
        $GLOBALS['CONFIG']['url'] ??= 'https://ejemplo.es';
        $a = $this->usuario(['rol' => 'admin']);
        $url = crear_acceso_inspeccion($this->db, $a, gmdate('Y-m-d', strtotime('+3 days')));
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $u = usuario_de_enlace($this->db, $q['t']);
        $this->assertSame(['inspeccion', 'INSPECCIÓN'], [$u['rol'], $u['nombre']]);
        $this->assertNull(usuario_de_enlace($this->db, 'falso'));
        crear_acceso_inspeccion($this->db, $a, gmdate('Y-m-d', strtotime('+3 days')));
        $this->assertNull(usuario_de_enlace($this->db, $q['t']), 'el enlace nuevo anula el anterior');
    }
}
