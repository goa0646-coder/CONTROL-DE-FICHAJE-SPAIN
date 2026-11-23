<?php

const ACCIONES = ['anadir' => 'Añadir fichaje olvidado', 'anular' => 'Anular fichaje', 'cambiar' => 'Cambiar hora/tipo'];

function pedir_correccion(PDO $db, array $u, array $d, int $pedida_por): int
{
    $accion = $d['accion'] ?? '';
    $motivo = trim($d['motivo'] ?? '');
    if (!isset(ACCIONES[$accion])) throw new DomainException('Elige qué quieres corregir.');
    if ($motivo === '') throw new DomainException('El motivo es obligatorio.');
    $fichaje = null;
    if ($accion !== 'anadir') {
        $s = $db->prepare('SELECT f.id FROM fichajes f WHERE f.id = ? AND f.usuario_id = ? AND ' . NO_ANULADO);
        $s->execute([(int)($d['fichaje_id'] ?? 0), $u['id']]);
        $fichaje = $s->fetchColumn() ?: throw new DomainException('Elige un fichaje válido.');
    }
    $tipo = $momento = null;
    if ($accion !== 'anular') {
        $tipo = isset(TIPOS[$d['tipo'] ?? '']) ? $d['tipo'] : throw new DomainException('Elige el tipo de fichaje.');
        $momento = $d['momento_utc'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $momento) || $momento > gmdate('Y-m-d H:i:s')) {
            throw new DomainException('La fecha y hora no son válidas (no puede ser futura).');
        }
    }
    $db->prepare('INSERT INTO correcciones (usuario_id, accion, fichaje_id, tipo_propuesto, momento_propuesto_utc, motivo, pedida_por, pedida_utc)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$u['id'], $accion, $fichaje, $tipo, $momento, mb_substr($motivo, 0, 500), $pedida_por, gmdate('Y-m-d H:i:s')]);
    return (int)$db->lastInsertId();
}

function resolver_correccion(PDO $db, int $id, array $yo, bool $aprobar, string $comentario = ''): void
{
    $db->beginTransaction();
    try {
        $s = $db->prepare("SELECT * FROM correcciones WHERE id = ? AND estado = 'pendiente' FOR UPDATE");
        $s->execute([$id]);
        $c = $s->fetch() ?: throw new DomainException('La corrección ya no está pendiente.');
        $u = usuario($db, (int)$c['usuario_id']);
        if (!puede_aprobar($yo, $u)) throw new DomainException('No puedes resolver esta corrección.');
        $db->prepare('SELECT id FROM usuarios WHERE id = ? FOR UPDATE')->execute([$u['id']]);
        $db->prepare('UPDATE correcciones SET estado = ?, resuelta_por = ?, resuelta_utc = ?, comentario = ? WHERE id = ?')
            ->execute([$aprobar ? 'aprobada' : 'rechazada', $yo['id'], gmdate('Y-m-d H:i:s'), mb_substr(trim($comentario), 0, 500), $id]);
        if ($aprobar && $c['accion'] !== 'anular') {
            insertar_fichaje($db, ['usuario_id' => $u['id'], 'tipo' => $c['tipo_propuesto'], 'momento_utc' => $c['momento_propuesto_utc'],
                'origen' => 'correccion', 'centro_id' => $u['centro_id'], 'quiosco_id' => null, 'zona' => null, 'ip' => '', 'correccion_id' => $id]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function correcciones_pendientes(PDO $db, array $yo): array
{
    $todas = $db->query("SELECT c.*, u.nombre, u.apellidos, u.responsable_id, f.tipo AS f_tipo, f.momento_utc AS f_momento
        FROM correcciones c JOIN usuarios u ON u.id = c.usuario_id LEFT JOIN fichajes f ON f.id = c.fichaje_id
        WHERE c.estado = 'pendiente' ORDER BY c.pedida_utc")->fetchAll();
    return array_values(array_filter($todas, fn($c) => puede_aprobar($yo, ['id' => $c['usuario_id'], 'responsable_id' => $c['responsable_id']])));
}

function correcciones_de(PDO $db, int $uid): array
{
    $s = $db->prepare('SELECT c.*, f.tipo AS f_tipo, f.momento_utc AS f_momento, r.nombre AS r_nombre, r.apellidos AS r_apellidos
        FROM correcciones c LEFT JOIN fichajes f ON f.id = c.fichaje_id LEFT JOIN usuarios r ON r.id = c.resuelta_por
        WHERE c.usuario_id = ? ORDER BY c.pedida_utc DESC');
    $s->execute([$uid]);
    return $s->fetchAll();
}
