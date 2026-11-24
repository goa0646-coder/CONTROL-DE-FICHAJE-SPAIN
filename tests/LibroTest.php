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
}
