<?php

class LibroTest extends Base
{
    private function subir(array $hojas): array
    {
        $ruta = tempnam(sys_get_temp_dir(), 'lib');
        file_put_contents($ruta, escribir_xlsx($hojas));
        $libro = leer_libro($ruta, 'xlsx');
        unlink($ruta);
        return $libro;
    }

    public function testLibroCompletoSeMontaEnOrden(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $s = uniqid();
        $email = "ana$s@x.es";
        $libro = $this->subir([
            'Instrucciones' => [['Texto que se ignora']],
            'Turnos' => [['Nombre', 'Letra', 'Color', 'Entrada', 'Salida', 'Horas', 'Departamento'], ["NOCHE $s", 'N', '#6d28d9', '23:00', '7:00', '8', "AUX $s"]],
            'Personas' => [['Nombre', 'Apellidos', 'Email', 'Departamento', 'Horario', 'Teléfono'], ['Ana', 'Prueba', $email, "AUX $s", "NOCHE $s", '600111222']],
            'Ratios' => [['Regla', 'Turno', 'Mínimo', 'Día', 'Festivos'], ["R $s", "NOCHE $s", '2', 'Sábado', 'No'], ['Mal', 'NO EXISTE', '1', 'Todos', '']],
            'Festivos' => [['Fecha', 'Nombre'], ['24/07/2031', "Fiesta $s"]],
            'Ausencias' => [['Email', 'Tipo', 'Desde', 'Hasta'], [$email, 'Vacaciones', '01/08/2031', '15/08/2031']],
        ]);
        $this->assertSame(['Instrucciones', 'Turnos', 'Personas', 'Ratios', 'Festivos', 'Ausencias'], array_keys($libro));
        $plan = preparar_libro($this->db, $libro);
        $this->assertSame(['el turno no existe'], $plan['secciones']['ratios']['filas'][1]['errores']);
        $this->assertSame([], $plan['secciones']['ausencias']['filas'][0]['errores'], 'la persona viene en el mismo libro');
        $res = aplicar_libro($this->db, $admin, $plan, true);
        $this->assertSame('1 nuevos, 0 actualizados', $res['turnos']);
        $u = consulta($this->db, 'SELECT * FROM usuarios WHERE email = ?', [$email])->fetch();
        $h = horarios($this->db)[$u['horario_id']];
        $this->assertSame(["NOCHE $s", 'N', 480, '600111222'], [$h['nombre'], $h['abreviatura'], (int)$h['dias'][1]['minutos'], $u['telefono']]);
        $this->assertSame("AUX $s", consulta($this->db, 'SELECT nombre FROM departamentos WHERE id = ?', [$u['departamento_id']])->fetchColumn());
        $r = consulta($this->db, 'SELECT * FROM necesidades WHERE nombre = ?', ["R $s"])->fetch();
        $this->assertSame([6, 2, 0], [(int)$r['dia'], (int)$r['personas'], (int)$r['festivos']]);
        $this->assertSame(1, (int)consulta($this->db, 'SELECT COUNT(*) FROM festivos WHERE nombre = ?', ["Fiesta $s"])->fetchColumn());
        $a = consulta($this->db, 'SELECT * FROM ausencias WHERE usuario_id = ?', [$u['id']])->fetch();
        $this->assertSame(['2031-08-01', '2031-08-15', 'aprobada'], [$a['desde'], $a['hasta'], $a['estado']]);
        // Otra vez el mismo libro: no duplica nada.
        aplicar_libro($this->db, $admin, preparar_libro($this->db, $libro), true);
        $this->assertSame(1, (int)consulta($this->db, 'SELECT COUNT(*) FROM ausencias WHERE usuario_id = ?', [$u['id']])->fetchColumn());
        $this->assertSame(1, (int)consulta($this->db, 'SELECT COUNT(*) FROM necesidades WHERE nombre = ?', ["R $s"])->fetchColumn());
        $this->assertSame(1, (int)consulta($this->db, 'SELECT COUNT(*) FROM festivos WHERE nombre = ?', ["Fiesta $s"])->fetchColumn());
        $this->db->exec('DELETE FROM necesidades');
    }

    public function testPlantillaConMisDatosIdaYVuelta(): void
    {
        $libro = $this->subir(hojas_actuales($this->db));
        $this->assertSame(['Instrucciones', 'Personas', 'Turnos', 'Ratios', 'Festivos', 'Ausencias', 'Departamentos', 'Puestos'], array_keys($libro));
        $plan = preparar_libro($this->db, $libro);
        $this->assertArrayHasKey('personas', $plan['secciones']);
        $this->assertSame([], $plan['ignoradas'], 'Instrucciones no cuenta como hoja desconocida');
        foreach (['turnos', 'ratios', 'festivos'] as $sec) foreach ($plan['secciones'][$sec]['filas'] ?? [] as $f) $this->assertSame([], $f['errores'], "$sec fila {$f['fila']}");
    }

    public function testUnaSolaHojaDePersonasYXlsAntiguo(): void
    {
        $plan = preparar_libro($this->db, $this->subir(['Hoja1' => [['Nombre', 'Email'], ['Luis', 'luis' . uniqid() . '@x.es']]]));
        $this->assertSame(['personas'], array_keys($plan['secciones']));
        $this->expectException(DomainException::class);
        leer_libro('/dev/null', 'xls');
    }

    public function testHoras(): void
    {
        $this->assertSame(['07:00', '07:30', '08:00', '23:00'], [hora_hhmm('7:00'), hora_hhmm('0,3125'), hora_hhmm('8'), hora_hhmm('23:00:00')]);
        $this->assertSame(['8:00', '7:30'], [horas_desde_numero('8'), horas_desde_numero('7,5')]);
        $this->assertSame('8:00', duracion('23:00', '07:00'));
    }

    public function testCuadranteMesAMes(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $s = uniqid();
        $ea = "aux$s@x.es";
        $el = "limp$s@x.es";
        $dias = fn(int $n) => array_map(fn($d) => (string)$d, range(1, $n));
        $libro = $this->subir([
            'Turnos' => [['Nombre', 'Letra', 'Entrada', 'Salida', 'Departamento'], ["AUX M $s", 'M', '07:00', '15:00', "AUX $s"], ["AUX N $s", 'N', '23:00', '07:00', "AUX $s"], ["LIMP M $s", 'M', '08:00', '15:30', "LIMP $s"]],
            'Personas' => [['Nombre', 'Apellidos', 'Email', 'Departamento'], ['Ana', "Aux $s", $ea, "AUX $s"], ['Lola', "Limp $s", $el, "LIMP $s"]],
            // Enero con números de día; febrero con «L 1, M 2…» y la persona por nombre.
            'Enero 2031' => [['Email', ...$dias(31)], [$ea, 'M', 'M', 'N', 'N', 'D', 'D', 'V', 'V', 'V'], [$el, 'M', 'M', 'D'], ["nadie$s@x.es", 'M']],
            'Feb-31' => [['Persona', 'L 1', 'M 2', 'X 3', 'J 4', 'V 5', 'S 6', 'D 7'], ["Ana Aux $s", 'N', 'D', '', 'XX']],
        ]);
        $plan = preparar_libro($this->db, $libro);
        $r = $plan['secciones']['cuadrante']['resumen'];
        $this->assertSame(['2031-01', '2031-02'], $r['meses']);
        $this->assertSame(["nadie$s@x.es" => 1], $r['sin_persona']);
        $this->assertCount(1, $r['dudas'], 'XX no se entiende');
        $this->assertSame(['turno', "LIMP M $s"], $r['asig'][$el]['2031-01-01'], 'la M de limpieza para quien es de limpieza');
        $this->assertSame(['turno', "AUX M $s"], $r['asig'][$ea]['2031-01-01']);
        $res = aplicar_libro($this->db, $admin, $plan, true);
        $this->assertStringStartsWith('11 días', $res['cuadrante']);
        $u = consulta($this->db, 'SELECT * FROM usuarios WHERE email = ?', [$ea])->fetch();
        $cal = calendario_teorico($this->db, $u, '2031-01-01', '2031-02-02');
        $h = horarios($this->db);
        $letras = implode('', array_map(fn($c) => celda_turno($c, $h, true)[0], array_slice($cal, 0, 9)));
        $this->assertSame('MMNNDDVVV', $letras);
        $this->assertSame('N', celda_turno($cal['2031-02-01'], $h, true)[0]);
        $a = consulta($this->db, 'SELECT desde, hasta, estado FROM ausencias WHERE usuario_id = ?', [$u['id']])->fetch();
        $this->assertSame(['2031-01-07', '2031-01-09', 'aprobada'], array_values($a), 'V seguidas = una sola ausencia');
    }

    public function testMesDeTexto(): void
    {
        $this->assertSame([2027, 1], mes_de_texto('Enero 2027'));
        $this->assertSame([2027, 3], mes_de_texto('mar-27'));
        $this->assertSame([2026, 11], mes_de_texto('Noviembre 2026'));
        $this->assertSame([2027, 2], mes_de_texto('2027-02'));
        $this->assertNull(mes_de_texto('Personas'));
        $this->assertFalse(es_hoja_cuadrante('Turnos'));
    }

    public function testPlantillaConCuadranteSeReimporta(): void
    {
        $s = uniqid();
        guardar_turnos($this->db, [["T $s", 'T', '#3b82f6', '15:00', '23:00', '8:00', '']]);
        $hid = (int)consulta($this->db, 'SELECT id FROM horarios WHERE nombre = ?', ["T $s"])->fetchColumn();
        $u = $this->usuario();
        consulta($this->db, "REPLACE INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, '2032-03-02', ?), (?, '2032-03-03', NULL)", [$u['id'], $hid, $u['id']]);
        $hojas = hojas_cuadrante($this->db, 2032);
        $this->assertCount(12, $hojas);
        $this->assertArrayHasKey('Marzo 2032', $hojas);
        $r = interpretar_cuadrante(leer_cuadrantes($this->subir(['Marzo 2032' => $hojas['Marzo 2032']])), ...listas_cuadrante($this->db));
        $this->assertSame([['turno', "T $s"], ['libre']], [$r['asig'][$u['email']]['2032-03-02'], $r['asig'][$u['email']]['2032-03-03']]);
    }

    public function testMigracionDeUnMesPasadoConNumerosComo8punto0(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $s = uniqid();
        $email = "mig$s@x.es";
        // Así llegan del Excel de la residencia: días «1.0», turnos «8.0», F = festivo y LD.
        $libro = $this->subir([
            'Turnos' => [['Nombre', 'Letra', 'Entrada', 'Salida', 'Horas', 'Departamento'], ["J8 $s", '8.0', '0.2916666666666667', '0.625', '0.3333333333333333', "AUX $s"]],
            'Personas' => [['Email', 'Nombre', 'Apellidos', 'Departamento', 'Rol'], [$email, 'Ana', "Mig $s", "AUX $s", 'empleado']],
            'Enero 2026' => [['Email', 'Persona', '1.0', '2.0', '3.0', '4.0', '5.0', '6.0', '7.0'], [$email, "Ana Mig $s", 'F', '8.0', '8.0', 'D', 'LD', 'V', 'X']],
        ]);
        $plan = preparar_libro($this->db, $libro);
        $r = $plan['secciones']['cuadrante']['resumen'];
        $this->assertSame(['turno' => 2, 'libre' => 2, 'ausencia' => 2], $r['cuenta']);
        $this->assertSame('«X» no es un turno, un descanso ni una ausencia conocidos', $r['problemas'][0][5]);
        $fila = array_values($r['vista']['2026-01'])[0];
        $this->assertSame(['8', true], [$fila['2026-01-02'][0], $fila['2026-01-07'][3]]);
        $this->assertNotEmpty(informe_libro($plan));
        aplicar_libro($this->db, $admin, $plan, true);
        $u = consulta($this->db, 'SELECT * FROM usuarios WHERE email = ?', [$email])->fetch();
        $this->assertSame('2026-01-01', local($u['alta_utc'], 'Y-m-d'), 'el alta se adelanta al cuadrante');
        $cal = calendario_teorico($this->db, $u, '2026-01-01', '2026-01-06');
        $this->assertSame([0, 480, 480, 0], array_map(fn($c) => $c['turno'], array_slice(array_values($cal), 0, 4)), 'los turnos de enero cuentan');
        $this->assertSame('Libre disposición', $cal['2026-01-05']['ausencia']['tipo'] ?? null);
        $inf = json_decode(ajuste($this->db, 'informe_importacion'), true);
        $this->assertContains('X', array_column($inf['filas'], 5));
    }
}
