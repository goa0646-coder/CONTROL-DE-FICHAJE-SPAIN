<?php

class AutomaticoTest extends Base
{
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
}
