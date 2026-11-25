<?php

class ImportarTest extends Base
{
    // Excel como el que exporta Woffu: etiquetas con prefijo x:, título en la fila 1 y cabeceras en la 3. Datos inventados.
    private function xlsx(array $filas): string
    {
        $m = 'xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $ss = [];
        $xml = '';
        foreach ($filas as $i => $f) {
            $xml .= '<x:row r="' . ($i + 1) . '">';
            foreach (array_values($f) as $j => $v) {
                if ($v === '') continue;
                $ss[] = $v;
                $xml .= '<x:c r="' . chr(65 + $j) . ($i + 1) . '" t="s"><x:v>' . (count($ss) - 1) . '</x:v></x:c>';
            }
            $xml .= '</x:row>';
        }
        $ruta = tempnam(sys_get_temp_dir(), 'xls');
        $z = new ZipArchive();
        $z->open($ruta, ZipArchive::OVERWRITE);
        $z->addFromString('xl/workbook.xml', "<?xml version=\"1.0\"?><x:workbook $m xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><x:sheets><x:sheet name=\"Data\" sheetId=\"2\" r:id=\"rId2\"/></x:sheets></x:workbook>");
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Target="/xl/worksheets/sheet1.xml" Id="rId2"/></Relationships>');
        $z->addFromString('xl/sharedStrings.xml', "<?xml version=\"1.0\"?><x:sst $m>" . implode('', array_map(fn($s) => '<x:si><x:t>' . htmlspecialchars($s) . '</x:t></x:si>', $ss)) . '</x:sst>');
        $z->addFromString('xl/worksheets/sheet1.xml', "\xEF\xBB\xBF<?xml version=\"1.0\"?><x:worksheet $m><x:sheetData>$xml</x:sheetData></x:worksheet>");
        $z->close();
        return $ruta;
    }

    private function woffu(): array
    {
        $cab = ['Usuario', 'E-mail', 'Responsable de primer nivel', 'Centro', 'Cargo', 'Departamento', 'Horario', 'Usuario suspendido', 'Rol'];
        $n = uniqid();
        return [['Usuarios'], [''], $cab,
            ['JEFA PRUEBA UNO', "jefa$n@x.es", 'Jefa Prueba Uno', 'RESIDENCIA PRUEBA', 'ENCARGADA', 'COORDINACIÓN', 'N/A', 'No', 'User'],
            ['MARIA DE LA PRUEBA', "maria$n@x.es", 'Jefa Prueba Uno', 'RESIDENCIA PRUEBA', 'AUXILIAR /GEROCULTOR', 'AUXILIAR', 'TURNO RARO', 'No', 'User'],
            ['PEPE ANTIGUO', "pepe$n@x.es", 'Nadie Conocido', 'RESIDENCIA PRUEBA', '', '', '', 'Si', 'User'],
            ['SIN EMAIL', 'esto-no', '', '', '', '', '', 'No', 'User'],
            ['REPE', "maria$n@x.es", '', '', '', '', '', 'No', 'User'],
        ];
    }

    public function testLeeExcelConPrefijosYCabecerasEnLaFila3(): void
    {
        $ruta = $this->xlsx($this->woffu());
        [$filas, $cols] = filas_importables(leer_hoja($ruta, 'xlsx'));
        unlink($ruta);
        $this->assertCount(5, $filas);
        $this->assertSame(['completo', 'email', 'responsable', 'centro', 'puesto', 'departamento', 'horario', 'suspendido', 'rol'], $cols);
        $this->assertSame('MARIA DE LA PRUEBA', $filas[1]['completo']);
    }

    public function testCsvConPuntoYComa(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($ruta, mb_convert_encoding("Nombre;Apellidos;Email;DNI\nJosé;Núñez;jose@x.es;12345678z\n", 'Windows-1252', 'UTF-8'));
        [$filas] = filas_importables(leer_hoja($ruta, 'csv'));
        unlink($ruta);
        $this->assertSame(['nombre' => 'José', 'apellidos' => 'Núñez', 'email' => 'jose@x.es', 'dni' => '12345678z'], $filas[0]);
    }

    public function testPlanValidaYDetectaLoQueFalta(): void
    {
        $ruta = $this->xlsx($this->woffu());
        [$filas] = filas_importables(leer_hoja($ruta, 'xlsx'));
        unlink($ruta);
        $plan = preparar_importacion($this->db, $filas);
        $this->assertSame(['nuevo', 'nuevo', 'nuevo', 'error', 'error'], array_column($plan['filas'], 'estado'));
        $this->assertSame(['email no válido'], $plan['filas'][3]['errores']);
        $this->assertSame(['email repetido en el archivo'], $plan['filas'][4]['errores']);
        $this->assertSame(['Maria', 'de la Prueba'], [$plan['filas'][1]['nombre'], $plan['filas'][1]['apellidos']]);
        $this->assertSame(['TURNO RARO'], $plan['horarios_desconocidos']);
        $this->assertSame(['Nadie Conocido'], $plan['responsables_desconocidos']);
        $this->assertContains('AUXILIAR /GEROCULTOR', $plan['crear']['puestos']);
        $this->assertFalse($plan['filas'][2]['activo'], 'suspendido = de baja');
        $this->assertNull($plan['filas'][0]['responsable_email'], 'nadie es su propio responsable');
    }

    public function testAplicarCreaTodoYEnlazaResponsables(): void
    {
        $ruta = $this->xlsx($this->woffu());
        [$filas] = filas_importables(leer_hoja($ruta, 'xlsx'));
        unlink($ruta);
        $res = aplicar_importacion($this->db, preparar_importacion($this->db, $filas), true);
        $this->assertCount(2, $res['nuevos'], 'el de baja no recibe bienvenida');
        $this->assertSame(['Jefa Prueba Uno'], $res['ascendidos']);
        $jefa = consulta($this->db, 'SELECT * FROM usuarios WHERE email = ?', [$filas[0]['email']])->fetch();
        $maria = consulta($this->db, 'SELECT * FROM usuarios WHERE email = ?', [$filas[1]['email']])->fetch();
        $pepe = consulta($this->db, 'SELECT * FROM usuarios WHERE email = ?', [$filas[2]['email']])->fetch();
        $this->assertSame(['responsable', (string)$jefa['id']], [$jefa['rol'], (string)$maria['responsable_id']]);
        $this->assertSame([0, true], [(int)$pepe['activo'], $pepe['baja_utc'] !== null]);
        $this->assertSame('RESIDENCIA PRUEBA', centro($this->db, (int)$maria['centro_id'])['nombre']);
        $this->assertNotEmpty(puestos_por_persona($this->db)[$maria['id']]);
        // Importar otra vez no duplica nada.
        aplicar_importacion($this->db, preparar_importacion($this->db, $filas), true);
        $this->assertSame(1, (int)consulta($this->db, 'SELECT COUNT(*) FROM usuarios WHERE email = ?', [$filas[1]['email']])->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM centros WHERE nombre = 'RESIDENCIA PRUEBA'")->fetchColumn());
    }

    public function testVariosPuestosSeparadosPorPuntoYComa(): void
    {
        $em = uniqid() . '@x.es';
        $plan = preparar_importacion($this->db, [['nombre' => 'Coord', 'apellidos' => 'Prueba', 'email' => $em, 'puesto' => 'AUXILIAR X; COORDINACIÓN X']]);
        aplicar_importacion($this->db, $plan, true);
        $id = consulta($this->db, 'SELECT id FROM usuarios WHERE email = ?', [$em])->fetchColumn();
        $n = consulta($this->db, 'SELECT p.nombre FROM usuarios_puestos up JOIN puestos p ON p.id = up.puesto_id WHERE up.usuario_id = ? ORDER BY p.nombre', [$id])->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['AUXILIAR X', 'COORDINACIÓN X'], $n);
    }

    public function testNoQuitaElRolDeAdministrador(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $plan = preparar_importacion($this->db, [['completo' => 'Admin Nuevo Nombre', 'email' => $admin['email'], 'rol' => 'User', 'suspendido' => 'Si']]);
        $this->assertSame('actualizar', $plan['filas'][0]['estado']);
        aplicar_importacion($this->db, $plan, true);
        $a = usuario($this->db, $admin['id']);
        $this->assertSame(['admin', 1, 'Admin'], [$a['rol'], (int)$a['activo'], $a['nombre']]);
    }
}
