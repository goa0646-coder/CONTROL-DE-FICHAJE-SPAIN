<?php
require_once __DIR__ . '/../public_html/app/asistente.php';

class AsistenteTest extends Base
{
    public function testConfigGeneradaSeLeeIgual(): void
    {
        $d = ['bd' => ['host' => 'localhost', 'nombre' => "u1_inda", 'usuario' => 'u1', 'clave' => "co'mi\"lla\$x"], 'url' => 'https://a.es', 'correo_desde' => '', 'clave_instalacion' => 'x'];
        $f = tempnam(sys_get_temp_dir(), 'cfg');
        file_put_contents($f, generar_config($d));
        $this->assertSame($d, require $f);
        unlink($f);
    }

    public function testRequisitosObligatoriosEnEsteServidor(): void
    {
        foreach (requisitos() as $n => [$ok, , $oblig]) if ($oblig) $this->assertTrue($ok, $n);
    }
}
