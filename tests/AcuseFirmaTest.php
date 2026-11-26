<?php

class AcuseFirmaTest extends Base
{
    private const JPEG = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNTAK/9sAQwAQCwwODAoQDg0OEhEQExgoGhgWFhgxIyUdKDozPTw5Mzg3QEhcTkBEV0U3OFBtUVdfYmdoZz5NcXlwZHhcZWdj/9sAQwEREhIYFRgvGhovY0I4QmNjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2Nj/8AAEQgABAAIAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A6DXv+Ke0efUdM/c+Tt/0brC2WC/d/h6k/LtyeuaKKKAP/9k=';

    public function testFirmaRequeridaConCertificadoPdf(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $u = $this->usuario();
        consulta($this->db, "UPDATE usuarios SET dni = '11111111H' WHERE id = ?", [$u['id']]);
        $u = usuario($this->db, (int)$u['id']);
        $id = crear_anuncio($this->db, $admin, 'Cambio de turnos', "Nuevo horario desde enero.\nGracias.", [$u['id']], [], true);
        $c = anuncio($this->db, $id);
        $this->assertSame($id, firma_pendiente($this->db, $u), 'bloquea hasta firmar');
        $mal = fn(callable $f, array $d) => (function () use ($f, $c, $u, $d) { try { $f($this->db, $c, $u, $d); return 'firmó'; } catch (DomainException $e) { return 'no'; } })();
        $this->assertFalse(contenido_visible($this->db, $c, $u), 'el contenido se ve tras firmar la recepción');
        $this->assertTrue(contenido_visible($this->db, $c, $admin));
        $this->assertSame('no', $mal('firmar_anuncio', ['acepto' => 1, 'firma' => self::JPEG]), 'sin recepción no hay aceptación');
        $this->assertSame('no', $mal('recibir_anuncio', ['dni' => '11111111H', 'firma' => self::JPEG]), 'sin marcar recibido');
        $this->assertSame('no', $mal('recibir_anuncio', ['dni' => '00000000T', 'recibido' => 1, 'firma' => self::JPEG]), 'otro DNI');
        $this->assertSame('no', $mal('recibir_anuncio', ['dni' => '11111111H', 'recibido' => 1, 'firma' => 'data:image/png;base64,AAAA']), 'sin firma');
        recibir_anuncio($this->db, $c, $u, ['dni' => '11111111-h', 'recibido' => 1, 'firma' => self::JPEG]);
        $this->assertTrue(contenido_visible($this->db, $c, $u));
        $this->assertSame($id, firma_pendiente($this->db, $u), 'sigue pendiente hasta la aceptación');
        $this->assertSame('no', $mal('firmar_anuncio', ['firma' => self::JPEG]), 'sin aceptar');
        $doc = firmar_anuncio($this->db, $c, $u, ['acepto' => 1, 'firma' => self::JPEG, 'lat' => '43.3', 'lng' => '-8.4', 'precision' => '20']);
        $a = acuse_firma($this->db, $id, (int)$u['id']);
        $this->assertSame([$doc, '43.300000'], [(int)$a['documento_id'], $a['lat']]);
        $d = consulta($this->db, 'SELECT * FROM documentos WHERE id = ?', [$doc])->fetch();
        $pdf = file_get_contents(carpeta_archivos() . '/' . $d['archivo']);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertSame($a['hash_pdf'], hash('sha256', $pdf), 'huella del certificado');
        $this->assertSame(2, substr_count($pdf, '/DCTDecode'), 'las dos firmas');
        $this->assertStringContainsString('(Cambio de turnos)', $pdf);
        $this->assertNull(firma_pendiente($this->db, $u));
        $this->assertSame($doc, firmar_anuncio($this->db, $c, $u, []), 'no se firma dos veces');
        $this->assertTrue(puede_ver_documento($u, $d) && puede_ver_documento($admin, $d), 'certificado para ambas partes');
    }
}
