<?php

class PushTest extends Base
{
    public function testJwtVapidSeVerificaConLaClavePublica(): void
    {
        [$pem, $pub] = claves_vapid($this->db);
        $this->assertSame([$pem, $pub], claves_vapid($this->db), 'se generan una sola vez');
        $jwt = jwt_vapid($pem, 'https://fcm.googleapis.com/fcm/send/abc', 'a@b.es');
        [$h, $c, $firma] = explode('.', $jwt);
        $this->assertEquals('https://fcm.googleapis.com', json_decode(base64_decode(strtr($c, '-_', '+/')), true)['aud']);
        $raw = base64_decode(strtr($firma, '-_', '+/') . str_repeat('=', (4 - strlen($firma) % 4) % 4));
        $this->assertSame(64, strlen($raw));
        // r||s → DER para comprobarla con OpenSSL.
        $int = function (string $b): string {
            $b = ltrim($b, "\0") ?: "\0";            // primero quitar ceros a la izquierda…
            if (ord($b[0]) & 0x80) $b = "\0$b";       // …y luego evitar que se lea como negativo
            return "\x02" . chr(strlen($b)) . $b;
        };
        $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        $der = "\x30" . chr(strlen($seq)) . $seq;
        $this->assertSame(1, openssl_verify("$h.$c", $der, openssl_pkey_get_details(openssl_pkey_get_private($pem))['key'], OPENSSL_ALGO_SHA256));
        $this->assertSame(65, strlen(base64_decode(strtr($pub, '-_', '+/') . '==')));
    }

    public function testSuscripcionSoloHttps(): void
    {
        $u = $this->usuario();
        suscribir_push($this->db, $u['id'], 'https://push.example/x');
        suscribir_push($this->db, $u['id'], 'https://push.example/x');
        $this->assertEquals(1, $this->db->query("SELECT COUNT(*) FROM suscripciones_push WHERE usuario_id = {$u['id']}")->fetchColumn());
        $this->expectException(DomainException::class);
        suscribir_push($this->db, $u['id'], 'http://inseguro/x');
    }
}
