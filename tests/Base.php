<?php

use PHPUnit\Framework\TestCase;

abstract class Base extends TestCase
{
    protected PDO $db;

    protected function setUp(): void
    {
        $this->db = db();
    }

    protected function centro(array $d = []): int
    {
        $d += ['nombre' => 'Centro', 'lat' => 40.4168, 'lng' => -3.7038, 'radio_m' => 150, 'gps_modo' => 'no'];
        $this->db->prepare('INSERT INTO centros (nombre, lat, lng, radio_m, gps_modo) VALUES (?, ?, ?, ?, ?)')
            ->execute([$d['nombre'], $d['lat'], $d['lng'], $d['radio_m'], $d['gps_modo']]);
        return (int)$this->db->lastInsertId();
    }

    protected function usuario(array $d = []): array
    {
        $d += ['rol' => 'empleado', 'centro_id' => null, 'responsable_id' => null, 'gps_modo' => null];
        $this->db->prepare('INSERT INTO usuarios (nombre, email, password_hash, rol, centro_id, responsable_id, gps_modo, alta_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['Prueba', uniqid() . '@x.es', password_hash('secreto123', PASSWORD_DEFAULT), $d['rol'], $d['centro_id'], $d['responsable_id'], $d['gps_modo'], ahora()]);
        return usuario($this->db, (int)$this->db->lastInsertId());
    }
}
