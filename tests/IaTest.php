<?php

class IaTest extends Base
{
    public function testPeticionPideJsonConEsquema(): void
    {
        $p = ia_peticion('dni', 'xx', 'image/png');
        $this->assertSame('application/json', $p['generationConfig']['responseMimeType']);
        $this->assertSame(IA_ESQUEMAS['dni'], $p['generationConfig']['responseSchema']);
        $this->assertSame(base64_encode('xx'), $p['contents'][0]['parts'][1]['inline_data']['data']);
        $this->expectException(DomainException::class);
        ia_peticion('otra', null, null);
    }

    public function testRespuestaYErrores(): void
    {
        $ok = ['candidates' => [['content' => ['parts' => [['text' => '{"dni":"x"}']]]]]];
        $this->assertSame(['dni' => 'x'], ia_respuesta($ok));
        foreach ([['error' => ['message' => 'API key not valid']], ['candidates' => []], ['candidates' => [['content' => ['parts' => [['text' => 'no es json']]]]]]] as $mal) {
            try {
                ia_respuesta($mal);
                $this->fail('debería fallar');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testValidaLoQueDevuelveLaIa(): void
    {
        $this->assertTrue(dni_valido('12345678Z'));
        $this->assertTrue(dni_valido('X1234567L'));
        $this->assertFalse(dni_valido('12345678A'));
        $d = ia_validar('dni', ['nombre' => ' Ana ', 'apellidos' => 'Ruiz', 'dni' => '12.345.678-z', 'caducidad' => '2031-02-30']);
        $this->assertSame(['Ana', '12345678Z', true, ''], [$d['nombre'], $d['dni'], $d['dni_valido'], $d['caducidad']]);
        $f = ia_validar('festivos', ['festivos' => [['fecha' => '2027-01-06', 'nombre' => 'Reyes', 'ambito' => 'nacional'], ['fecha' => '6 de enero', 'nombre' => 'x']]]);
        $this->assertSame([['2027-01-06', 'Reyes', 'nacional']], $f);
        $c = ia_validar('convenio', ['nombre' => 'X', 'horas_anuales' => 99999, 'vacaciones_dias' => 22, 'vacaciones_tipo' => 'laborables', 'otras_reglas' => ['a']]);
        $this->assertSame([null, 22, 'laborables'], [$c['horas_anuales'], $c['vacaciones_dias'], $c['vacaciones_tipo']], 'valores absurdos se descartan');
    }

    public function testSinClaveNoSeActiva(): void
    {
        $this->assertFalse(ia_activa());
        $this->expectExceptionMessage('Falta la clave');
        ia_leer('dni', null);
    }
}
