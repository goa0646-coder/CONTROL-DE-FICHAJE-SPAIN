<?php

class NominasTest extends Base
{
    public function testEmparejaPorDniYPorNombre(): void
    {
        $p = [['id' => 1, 'nombre' => 'Ana', 'apellidos' => 'Fernández Peleteiro', 'dni' => '12345678Z'],
            ['id' => 2, 'nombre' => 'José Luis', 'apellidos' => 'Núñez', 'dni' => ''],
            ['id' => 3, 'nombre' => 'Ana', 'apellidos' => 'Pérez', 'dni' => ''],
            ['id' => 4, 'nombre' => 'Ana', 'apellidos' => 'Pérez Gil', 'dni' => '']];
        $this->assertSame(['uid' => 1, 'por' => 'dni'], emparejar_nomina(['dni' => '12345678Z', 'nombre' => 'otro'], $p));
        $this->assertSame(['uid' => 2, 'por' => 'nombre'], emparejar_nomina(['dni' => '', 'nombre' => 'NUÑEZ, JOSE LUIS'], $p), 'orden y acentos dan igual');
        $this->assertNull(emparejar_nomina(['dni' => '', 'nombre' => 'ANA PEREZ GIL'], $p), 'dos posibles: que elija el admin');
        $this->assertNull(emparejar_nomina(['dni' => '', 'nombre' => ''], $p));
    }

    public function testValidaLoQueLeeLaIa(): void
    {
        $d = ia_validar('nomina', ['nombre' => ' Ana ', 'dni' => '12.345.678-z', 'periodo' => '2026-10', 'es_nomina' => true]);
        $this->assertSame(['Ana', '12345678Z', '2026-10', true], [$d['nombre'], $d['dni'], $d['periodo'], $d['es_nomina']]);
        $d = ia_validar('nomina', ['nombre' => 'X', 'dni' => 'B12345678', 'periodo' => 'octubre', 'es_nomina' => false]);
        $this->assertSame(['', '', false], [$d['dni'], $d['periodo'], $d['es_nomina']], 'un CIF no vale como DNI');
    }
}
