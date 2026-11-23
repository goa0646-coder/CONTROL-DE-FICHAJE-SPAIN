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
        $d += ['rol' => 'empleado', 'centro_id' => null, 'responsable_id' => null, 'gps_modo' => null, 'horario_id' => null, 'departamento_id' => null, 'alta_utc' => '2020-01-01 00:00:00'];
        $this->db->prepare('INSERT INTO usuarios (nombre, email, password_hash, rol, centro_id, responsable_id, gps_modo, horario_id, departamento_id, alta_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['Prueba', uniqid() . '@x.es', password_hash('secreto123', PASSWORD_DEFAULT), $d['rol'], $d['centro_id'], $d['responsable_id'], $d['gps_modo'], $d['horario_id'], $d['departamento_id'], $d['alta_utc']]);
        return usuario($this->db, (int)$this->db->lastInsertId());
    }

    // Por defecto: lunes a viernes 8:00–16:00, 8 h.
    protected function horario(array $dias = [1, 2, 3, 4, 5], string $e = '08:00', string $s = '16:00', int $min = 480, bool $flexible = false): int
    {
        $this->db->prepare('INSERT INTO horarios (nombre, flexible, margen_min) VALUES (?, ?, 10)')->execute(['H' . uniqid(), (int)$flexible]);
        $id = (int)$this->db->lastInsertId();
        foreach ($dias as $d) $this->db->prepare('INSERT INTO horario_dias VALUES (?, ?, ?, ?, ?)')->execute([$id, $d, $e, $s, $min]);
        return $id;
    }

    // Fichaje en hora local de Madrid.
    protected function fichar(array $u, string $tipo, string $local, array $d = []): int
    {
        return registrar_fichaje($this->db, $u, $tipo, 'web', $d, a_utc($local));
    }
}
