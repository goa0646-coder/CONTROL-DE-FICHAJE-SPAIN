<?php

class AnunciosTest extends Base
{
    public function testDestinatariosPorGrupoYPersona(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $c1 = $this->centro(['nombre' => 'C' . uniqid()]);
        $c2 = $this->centro(['nombre' => 'C' . uniqid()]);
        $a = $this->usuario(['centro_id' => $c1]);
        $b = $this->usuario(['centro_id' => $c1]);
        $c = $this->usuario(['centro_id' => $c2]);
        $ids = resolver_destinatarios($this->db, $admin, ["centro:$c1", "persona:{$c['id']}", "persona:{$a['id']}"]);
        sort($ids);
        $this->assertSame([(int)$a['id'], (int)$b['id'], (int)$c['id']], $ids, 'sin repetir');
        // Un responsable solo llega a su equipo, aunque marque «todos».
        $jefe = $this->usuario(['rol' => 'responsable']);
        $suyo = $this->usuario(['responsable_id' => $jefe['id']]);
        $this->assertSame([(int)$suyo['id']], resolver_destinatarios($this->db, $jefe, ['todos', "persona:{$c['id']}"]));
    }

    public function testAcuseDeLecturaConFechaEIp(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $a = $this->usuario();
        $b = $this->usuario();
        $otro = $this->usuario();
        $id = crear_anuncio($this->db, $admin, 'Protocolo', 'Texto', [$a['id'], $b['id']]);
        $c = anuncio($this->db, $id);
        $this->assertTrue(es_destinatario($this->db, $c, $a));
        $this->assertFalse(es_destinatario($this->db, $c, $otro));
        $this->assertFalse(puede_ver_anuncio($this->db, $c, $otro));
        $this->assertSame([$id], array_column(array_filter(anuncios_para($this->db, $a), fn($x) => $x['id'] == $id), 'id'));
        $this->assertSame([], array_filter(anuncios_para($this->db, $otro), fn($x) => $x['id'] == $id));
        $this->assertContains($id, array_column(anuncios_pendientes($this->db, $a), 'id'));
        $this->assertNotEmpty(consulta($this->db, "SELECT 1 FROM avisos WHERE usuario_id = ? AND clave = ?", [$a['id'], "comunicado:$id"])->fetchColumn(), 'aviso + email');

        $_SERVER['REMOTE_ADDR'] = '10.1.2.3';
        confirmar_lectura($this->db, $c, $a);
        confirmar_lectura($this->db, $c, $a); // la segunda no cambia la fecha
        $r = lecturas($this->db, $c);
        $this->assertSame([(int)$a['id']], array_map(fn($l) => (int)$l['usuario']['id'], $r['leidos']));
        $this->assertSame('10.1.2.3', $r['leidos'][0]['ip']);
        $this->assertSame([(int)$b['id']], array_map(fn($u) => (int)$u['id'], $r['pendientes']));
        $this->assertNotContains($id, array_column(anuncios_pendientes($this->db, $a), 'id'));

        $this->assertSame(1, recordar_anuncio($this->db, $c, $admin), 'solo a quien falta');
        $this->expectException(DomainException::class);
        confirmar_lectura($this->db, $c, $otro);
    }

    public function testAnunciosAntiguosPorCentroSiguenFuncionando(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $centro = $this->centro(['nombre' => 'C' . uniqid()]);
        $a = $this->usuario(['centro_id' => $centro]);
        $fuera = $this->usuario(['centro_id' => $this->centro(['nombre' => 'C' . uniqid()])]);
        consulta($this->db, 'INSERT INTO comunicados (titulo, texto, centro_id, autor_id, creado_utc) VALUES (?, ?, ?, ?, ?)', ['Viejo', 'x', $centro, $admin['id'], ahora()]);
        $c = anuncio($this->db, (int)$this->db->lastInsertId());
        $this->assertTrue(es_destinatario($this->db, $c, $a));
        $this->assertFalse(es_destinatario($this->db, $c, $fuera));
        $this->assertContains((int)$a['id'], destinatarios_anuncio($this->db, $c));
    }

    public function testEnvioALaEmpresaAvisaAlResponsableYAdmin(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $jefe = $this->usuario(['rol' => 'responsable']);
        $otro_jefe = $this->usuario(['rol' => 'responsable']);
        $u = $this->usuario(['responsable_id' => $jefe['id']]);
        $id = enviar_a_empresa($this->db, $u, 'Justificante: médico', 'Adjunto el justificante');
        $receptores = consulta($this->db, 'SELECT usuario_id FROM avisos WHERE clave = ?', ["envio:$id"])->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains((string)$jefe['id'], array_map('strval', $receptores));
        $this->assertContains((string)$admin['id'], array_map('strval', $receptores));
        $this->assertNotContains((string)$u['id'], array_map('strval', $receptores));
        $e = envio($this->db, $id);
        $this->assertTrue(puede_ver_envio($this->db, $e, $u));
        $this->assertTrue(puede_ver_envio($this->db, $e, $jefe));
        $this->assertFalse(puede_ver_envio($this->db, $e, $otro_jefe));
        $this->assertContains($id, array_column(envios_recibidos($this->db, $jefe, true), 'id'));
        $this->assertNotContains($id, array_column(envios_recibidos($this->db, $otro_jefe), 'id'));
        marcar_envio_visto($this->db, $e, $jefe);
        $this->assertNotContains($id, array_column(envios_recibidos($this->db, $jefe, true), 'id'));
        $this->assertSame((string)$jefe['id'], (string)envio($this->db, $id)['visto_por']);
    }

    public function testEnvioVacioNoSeAdmite(): void
    {
        $this->expectException(DomainException::class);
        enviar_a_empresa($this->db, $this->usuario(), 'Consulta', '  ');
    }

    public function testFotoSeRecortaEnCuadradoYSinExif(): void
    {
        $u = $this->usuario();
        $img = imagecreatetruecolor(1200, 800);
        $tmp = tempnam(sys_get_temp_dir(), 'foto');
        imagejpeg($img, $tmp);
        $n = guardar_foto($this->db, $u, ['name' => 'yo.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]);
        $ruta = carpeta_archivos() . "/$n";
        [$w, $h] = getimagesize($ruta);
        $this->assertSame([400, 400], [$w, $h]);
        $this->assertSame($n, usuario($this->db, $u['id'])['foto']);
        $this->assertStringContainsString('<img class="avatar', avatar(usuario($this->db, $u['id'])));
        unlink($ruta);
        unlink($tmp);
    }

    public function testFotoRechazaLoQueNoEsImagen(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'foto');
        file_put_contents($tmp, '<?php echo 1;');
        $this->expectException(DomainException::class);
        try {
            guardar_foto($this->db, $this->usuario(), ['name' => 'x.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 13]);
        } finally {
            unlink($tmp);
        }
    }

    public function testAvatarConInicialesSinFoto(): void
    {
        $this->assertStringContainsString('>AG</i>', avatar(['id' => 3, 'nombre' => 'ana', 'apellidos' => 'Gil', 'foto' => null]));
    }
}
