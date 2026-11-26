<?php

class ContratosTest extends Base
{
    public function testContratoEditableConEstadoYSinBorrar(): void
    {
        $u = $this->usuario();
        $id = guardar_contrato($this->db, null, (int)$u['id'], ['tipo' => 'Indefinido', 'inicio' => '15/03/2018', 'fin' => '', 'convenio' => 'Residencias Galicia']);
        $c = contratos_de($this->db, (int)$u['id'])[0];
        $this->assertSame(['2018-03-15', null, 'Activo'], [$c['inicio'], $c['fin'], estado_contrato($c, '2026-11-26')]);
        guardar_contrato($this->db, null, (int)$u['id'], ['inicio' => '2018-03-15', 'fin' => '2026-01-31', 'tipo' => 'Indefinido'], $id);
        $c = contratos_de($this->db, (int)$u['id'])[0];
        $this->assertSame('Finalizado', estado_contrato($c, '2026-11-26'));
        guardar_contrato($this->db, null, (int)$u['id'], ['inicio' => '2018-03-15', 'anulado' => 1], $id);
        $this->assertSame('Anulado', estado_contrato(contratos_de($this->db, (int)$u['id'])[0]));
        $this->assertCount(1, contratos_de($this->db, (int)$u['id']), 'no se borra');
        $otro = $this->usuario();
        $this->expectException(DomainException::class);
        guardar_contrato($this->db, null, (int)$otro['id'], ['inicio' => '2020-01-01'], $id);
    }

    public function testImportarHojaContratosNoDuplica(): void
    {
        $u = $this->usuario();
        $admin = $this->usuario(['rol' => 'admin']);
        $libro = ['Contratos' => [['Email', 'Puesto / Categoría', 'Fecha de inicio', 'Fecha de fin', 'Convenio'], [$u['email'], 'Auxiliar', '19/03/2026', '*(Sin fecha / Abierto)*', 'Galicia']]];
        aplicar_libro($this->db, $admin, preparar_libro($this->db, $libro), true);
        aplicar_libro($this->db, $admin, preparar_libro($this->db, $libro), true);
        $cs = contratos_de($this->db, (int)$u['id']);
        $this->assertCount(1, $cs);
        $this->assertSame(['Auxiliar', '2026-03-19', null], [$cs[0]['categoria'], $cs[0]['inicio'], $cs[0]['fin']]);
    }
}
