<?php
require_once __DIR__ . '/../public_html/app/errores.php';

class ErroresTest extends Base
{
    public function testRegistraYDevuelveLosUltimos(): void
    {
        $GLOBALS['CONFIG']['archivos'] = sys_get_temp_dir() . '/inda-prueba-errores-' . uniqid();
        mkdir($GLOBALS['CONFIG']['archivos']);
        $c = registrar_error('Algo falló', __FILE__, 10, 'traza');
        $this->assertMatchesRegularExpression('/^[0-9A-F]{6}$/', $c);
        $e = errores_recientes();
        $this->assertSame($c, $e[0]['codigo']);
        $this->assertSame('Algo falló', $e[0]['mensaje']);
        unset($GLOBALS['CONFIG']['archivos']);
    }
}
