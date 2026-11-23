<?php
// Descarga de justificantes (?a=ausencia) y documentos (?d=documento) con control de permiso.
$yo = requiere(...ROLES);
if (isset($_GET['a'])) {
    $a = ausencia(db(), (int)$_GET['a']);
    $dueno = $a ? usuario(db(), (int)$a['usuario_id']) : null;
    if (!$a || !$a['justificante'] || !puede_ver($yo, $dueno)) exit('No disponible.');
    auditar(db(), (int)$yo['id'], 'ver_justificante', "ausencia={$a['id']}");
    enviar_archivo($a['justificante'], "justificante-{$a['id']}." . pathinfo($a['justificante'], PATHINFO_EXTENSION));
}
$d = documento(db(), (int)($_GET['d'] ?? 0));
if (!$d || !puede_ver_documento($yo, $d)) exit('No disponible.');
auditar(db(), (int)$yo['id'], 'ver_documento', "documento={$d['id']}");
enviar_archivo($d['archivo'], $d['nombre_original']);
