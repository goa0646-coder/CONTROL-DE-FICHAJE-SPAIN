<?php
// Descarga con control de permiso: justificantes (?a=), documentos (?d=), adjuntos de anuncios (?c=) y envíos a la empresa (?e=).
$yo = requiere(...ROLES);
if (isset($_GET['a'])) {
    $a = ausencia(db(), (int)$_GET['a']);
    $dueno = $a ? usuario(db(), (int)$a['usuario_id']) : null;
    if (!$a || !$a['justificante'] || !puede_ver($yo, $dueno)) exit('No disponible.');
    auditar(db(), (int)$yo['id'], 'ver_justificante', "ausencia={$a['id']}");
    enviar_archivo($a['justificante'], "justificante-{$a['id']}." . pathinfo($a['justificante'], PATHINFO_EXTENSION));
}
if (isset($_GET['c'])) {
    $c = anuncio(db(), (int)$_GET['c']);
    if (!$c || !$c['archivo'] || !puede_ver_anuncio(db(), $c, $yo)) exit('No disponible.');
    enviar_archivo($c['archivo'], $c['nombre_original'] ?: 'adjunto');
}
if (isset($_GET['e'])) {
    $en = envio(db(), (int)$_GET['e']);
    if (!$en || !$en['archivo'] || !puede_ver_envio(db(), $en, $yo)) exit('No disponible.');
    auditar(db(), (int)$yo['id'], 'ver_envio', "envio={$en['id']}");
    enviar_archivo($en['archivo'], $en['nombre_original'] ?: 'adjunto');
}
$d = documento(db(), (int)($_GET['d'] ?? 0));
if (!$d || !puede_ver_documento($yo, $d)) exit('No disponible.');
auditar(db(), (int)$yo['id'], 'ver_documento', "documento={$d['id']}");
enviar_archivo($d['archivo'], $d['nombre_original']);
