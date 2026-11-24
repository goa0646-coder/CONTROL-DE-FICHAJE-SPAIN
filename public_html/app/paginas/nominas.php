<?php
// Nóminas: el navegador separa el PDF por páginas, la IA dice de quién es cada una y el admin
// revisa y aprueba el envío. Nada se guarda ni se envía hasta que el admin pulsa «Enviar».
$yo = requiere('responsable', 'admin');
if (!puede($yo, 'documentos')) {
    http_response_code(403);
    exit('No tienes permiso para enviar documentos.');
}
$personas = array_values(array_filter(equipo_de(db(), $yo), fn($p) => $p['activo'] && $p['rol'] !== 'inspeccion' && puede_ver($yo, $p)));

if (es_post()) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $que = $_POST['que'] ?? '';
        if ($que === 'leer') {
            $d = ia_leer('nomina', $_FILES['pagina'] ?? null);
            $m = emparejar_nomina($d, $personas);
            exit(json_encode($d + ['uid' => $m['uid'] ?? null, 'por' => $m['por'] ?? null], JSON_UNESCAPED_UNICODE));
        }
        if ($que === 'enviar') {
            $u = usuario(db(), (int)($_POST['uid'] ?? 0));
            if (!$u || !in_array($u['id'], array_column($personas, 'id'))) throw new DomainException('Persona no válida.');
            $f = $_FILES['pdf'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || file_get_contents($f['tmp_name'], false, null, 0, 5) !== '%PDF-') throw new DomainException('El archivo no es un PDF.');
            $f['name'] = 'nomina.pdf';
            $titulo = mb_substr(trim($_POST['titulo'] ?? '') ?: 'Nómina', 0, 150);
            $archivo = guardar_archivo($f);
            $firma = !empty($_POST['firma']) ? 1 : 0;
            consulta(db(), 'INSERT INTO documentos (usuario_id, titulo, archivo, nombre_original, requiere_firma, subido_por, subido_utc) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$u['id'], $titulo, $archivo, preg_replace('/[^\w\- ]/u', '', $titulo) . '.pdf', $firma, $yo['id'], ahora()]);
            $id = (int)db()->lastInsertId();
            notificar(db(), (int)$u['id'], "documento:$id", "Tienes tu nómina en INDA: $titulo" . ($firma ? ' (pendiente de firmar el recibí)' : '') . '.', 'documentos');
            auditar(db(), (int)$yo['id'], 'nomina_enviada', "documento=$id usuario={$u['id']}");
            exit(json_encode(['ok' => true]));
        }
        throw new DomainException('Acción no válida.');
    } catch (DomainException $e) {
        http_response_code(422);
        exit(json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
    }
}

$lista = array_map(fn($p) => ['id' => (int)$p['id'], 'nombre' => nombre($p), 'dni' => $p['dni']], $personas);
usort($lista, fn($a, $b) => strcoll($a['nombre'], $b['nombre']));
cabecera('Nóminas', $yo);
?>
<div class="tarjeta" data-nominas data-ia="<?= ia_activa() ? '1' : '' ?>" data-csrf="<?= e($_SESSION['csrf']) ?>" data-personas="<?= e(json_encode($lista, JSON_UNESCAPED_UNICODE)) ?>">
  <h2>1. Sube las nóminas del mes</h2>
  <p class="nota">El PDF de la gestoría con todas juntas (se separa por páginas aquí, en tu navegador) o varios PDF sueltos. <?= ia_activa()
      ? 'La IA lee cada página y propone a quién es. <strong>Nada se envía hasta que lo revises y pulses «Enviar».</strong>'
      : 'Sin clave de IA: tendrás que elegir a mano la persona de cada nómina.' ?></p>
  <?php if (ia_activa()): ?><p class="aviso error">Las nóminas llevan datos personales y salariales: usa la IA solo con una cuenta de Google con facturación activada (así Google no usa los datos). Las páginas no se guardan en INDA hasta que las envías.</p><?php endif ?>
  <label>PDF <input type="file" accept="application/pdf" multiple data-archivos></label>
  <div class="filtros">
    <label>Título <input data-titulo placeholder="Nómina octubre 2026 (se rellena solo)"></label>
    <label><input type="checkbox" data-firma checked> Pedir firma de recibí</label>
  </div>
  <button type="button" data-leer>Leer nóminas</button>
  <p class="nota" data-progreso></p>
</div>
<div data-revision hidden>
  <h2>2. Revisa y aprueba</h2>
  <table data-tabla></table>
  <p class="nota">Comprueba la persona de cada fila. Las filas sin persona no se envían. Al pulsar «Enviar», cada persona recibe solo su nómina en Documentos, con aviso por email.</p>
  <button type="button" data-enviar>Enviar</button>
</div>
<script src="<?= e(asset('assets/pdf-lib.min.js')) ?>"></script>
<script src="<?= e(asset('assets/nominas.js')) ?>"></script>
<?php pie();
