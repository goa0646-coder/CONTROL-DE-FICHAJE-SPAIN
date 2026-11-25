<?php

class AsistenteIaTest extends Base
{
    public function testUsaLasConsultasYResponde(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $u = $this->usuario(['alta_utc' => '2020-01-01 00:00:00']);
        $this->fichar($u, 'entrada', '2026-10-04 08:00'); // domingo
        $this->fichar($u, 'salida', '2026-10-04 15:00');
        // Gemini falso: primero pide la consulta, luego responde con lo que recibe.
        $vistos = [];
        $falso = function (array $cuerpo) use (&$vistos) {
            $ultimo = end($cuerpo['contents']);
            if (isset($ultimo['parts'][0]['functionResponse'])) {
                $vistos[] = (array)$ultimo['parts'][0]['functionResponse']['response'];
                return ['candidates' => [['content' => ['parts' => [['text' => 'Trabajaron el domingo: ' . count($vistos[0]['personas'])]]]]]];
            }
            $this->assertNotEmpty($cuerpo['tools'][0]['functionDeclarations']);
            return ['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'dias_trabajados', 'args' => ['desde' => '2026-10-01', 'hasta' => '2026-10-31', 'dia_semana' => 7, 'todos' => true]]]]]]]];
        };
        consulta($this->db, 'UPDATE usuarios SET activo = 0 WHERE id NOT IN (?, ?)', [$admin['id'], $u['id']]);
        [$r, $hechas] = preguntar_ia($this->db, $admin, '¿Quién trabajó todos los domingos de octubre?', [], $falso);
        consulta($this->db, 'UPDATE usuarios SET activo = 1');
        $this->assertCount(1, $hechas);
        $this->assertSame('Trabajaron el domingo: 0', $r, 'octubre tiene 4 domingos y solo trabajó uno: «todos» lo excluye');
        $this->assertCount(4, $vistos[0]['dias_consultados']);
        // Consultas sueltas: errores controlados y sin datos personales sensibles.
        $this->assertArrayHasKey('error', ejecutar_consulta($this->db, $admin, 'horas_trabajadas', ['desde' => '2099-01-01', 'hasta' => '2099-12-31']));
        $x = ejecutar_consulta($this->db, $admin, 'horas_trabajadas', ['desde' => '2026-10-04', 'hasta' => '2026-10-04', 'persona' => $u['apellidos']]);
        $this->assertStringNotContainsString('@', json_encode($x));
        $this->assertArrayHasKey('cambios_propuestos', ejecutar_consulta($this->db, $admin, 'propuesta_cubrir', ['desde' => '2099-10-05', 'hasta' => '2099-10-06']));
    }

    public function testConsultaSinParametrosVaComoObjeto(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $cuerpos = [];
        $falso = function (array $c) use (&$cuerpos) {
            $cuerpos[] = json_encode($c);
            return count($cuerpos) === 1 ? ['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'trabajando_ahora', 'args' => []]]]]]]]
                : ['candidates' => [['content' => ['parts' => [['text' => 'Nadie.']]]]]];
        };
        [$r] = preguntar_ia($this->db, $admin, '¿Quién trabaja ahora?', [], $falso);
        $this->assertSame('Nadie.', $r);
        $this->assertStringContainsString('"args":{}', $cuerpos[1]);
        $this->assertStringNotContainsString('"args":[]', $cuerpos[1]);
    }
}
