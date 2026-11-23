<?php

require_once __DIR__ . '/../public_html/app/migrar.php';

class MigrarTest extends Base
{
    public function testNoHaceNadaSiEstaAlDia(): void
    {
        $this->assertFalse(migrar_si_hace_falta($this->db));
    }

    // Simula una base de datos de la versión anterior (sin la 010) y comprueba que se pone al día sola.
    public function testAplicaLaPendienteAlEntrar(): void
    {
        $c = config()['bd'];
        $root = new PDO("mysql:host={$c['host']}", $c['usuario'], $c['clave'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $nombre = $c['nombre'] . '_migrar';
        try {
            $root->exec("DROP DATABASE IF EXISTS $nombre");
            $root->exec("CREATE DATABASE $nombre");
        } catch (PDOException) {
            $this->markTestSkipped('El usuario de pruebas no puede crear bases de datos.');
        }
        $db = conectar(['nombre' => $nombre] + $c);
        migrar($db);
        $db->exec('DROP TABLE envios, comunicados_para');
        $db->exec('ALTER TABLE usuarios DROP COLUMN foto');
        $db->exec('ALTER TABLE comunicados DROP COLUMN archivo, DROP COLUMN nombre_original, DROP COLUMN por_personas');
        $db->exec('ALTER TABLE comunicados_leidos DROP COLUMN ip');
        $db->exec("DELETE FROM migraciones WHERE version = '010_anuncios_envios_fotos'");
        $this->assertTrue(migrar_si_hace_falta($db));
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM envios')->fetchColumn());
        $this->assertFalse(migrar_si_hace_falta($db));
        $root->exec("DROP DATABASE $nombre");
    }
}
