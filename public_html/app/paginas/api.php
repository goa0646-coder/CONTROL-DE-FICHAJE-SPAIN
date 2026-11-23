<?php
// API JSON con token (cabecera Authorization: Bearer inda_…).
// Personal: ?accion=estado | POST ?accion=fichar (orden=entrar|pausa|volver|salir).
// Lectura (admin): ?accion=informe&desde=&hasta= | ?accion=fichajes&desde=&hasta= | ?accion=ausencias&desde=&hasta=
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$responder = function (int $codigo, array $datos): never {
    http_response_code($codigo);
    exit(json_encode($datos, JSON_UNESCAPED_UNICODE));
};
$t = preg_match('/^Bearer\s+(\S+)$/', $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '', $m) ? $m[1] : '';
if (bloqueado(db(), 'api:' . ip())) $responder(429, ['error' => 'Demasiados intentos.']);
$r = $t !== '' ? usuario_por_token(db(), $t) : null;
if (!$r) {
    fallo(db(), 'api:' . ip());
    $responder(401, ['error' => 'Código no válido.']);
}
[$u, $k] = $r;
$accion = $_GET['accion'] ?? 'estado';
$fecha = fn($n) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$n] ?? '') ? $_GET[$n] : $responder(400, ['error' => "Falta $n (AAAA-MM-DD)."]);

try {
    if ($k['alcance'] === 'personal') {
        if ($accion === 'fichar' && es_post()) {
            $txt = orden_fichaje(db(), $u, (string)($_POST['orden'] ?? json_decode(file_get_contents('php://input'), true)['orden'] ?? ''), 'api');
            $responder(200, ['ok' => true, 'mensaje' => $txt, 'estado' => estado_actual(db(), (int)$u['id'])]);
        }
        if ($accion === 'estado') {
            $hoy = local(ahora(), 'Y-m-d');
            $responder(200, ['nombre' => nombre($u), 'estado' => estado_actual(db(), (int)$u['id']), 'hoy' => horas(resumen(db(), $u, $hoy, $hoy)['dias'][$hoy]['trabajado'])]);
        }
        $responder(404, ['error' => 'Acción no válida.']);
    }
    // Lectura: solo tokens creados por un admin que sigue siéndolo.
    if ($u['rol'] !== 'admin') $responder(403, ['error' => 'Sin permiso.']);
    $desde = $fecha('desde');
    $hasta = $fecha('hasta');
    if ($hasta < $desde || strtotime($hasta) - strtotime($desde) > 400 * 86400) $responder(400, ['error' => 'Rango no válido (máximo 400 días).']);
    $personas = array_values(array_filter(equipo_de(db(), $u), fn($p) => $p['rol'] !== 'inspeccion'));
    auditar(db(), (int)$u['id'], 'api_' . $accion, "desde=$desde hasta=$hasta");
    $persona = fn($p) => ['id' => (int)$p['id'], 'num_empleado' => $p['num_empleado'], 'nombre' => nombre($p), 'dni' => $p['dni'], 'activo' => (bool)$p['activo']];
    if ($accion === 'informe') {
        $responder(200, array_map(fn($f) => $persona($f['persona']) + array_intersect_key($f, array_flip(['teorico', 'trabajado', 'extra', 'nocturno', 'festivo', 'diferencia', 'dias_trabajados', 'abiertas', 'ausencias'])),
            informe(db(), $personas, $desde, $hasta)));
    }
    if ($accion === 'fichajes') {
        $out = [];
        foreach ($personas as $p) foreach (fichajes_de(db(), (int)$p['id'], a_utc($desde), a_utc("$hasta +1 day")) as $f) {
            $out[] = ['persona' => (int)$p['id'], 'num_empleado' => $p['num_empleado'], 'momento_utc' => $f['momento_utc'], 'local' => local($f['momento_utc'], 'Y-m-d H:i:s'),
                'tipo' => $f['tipo'], 'motivo' => $f['motivo'], 'origen' => $f['origen'], 'zona' => $f['zona'], 'anulado' => (bool)$f['anulado'], 'hash' => $f['hash']];
        }
        $responder(200, $out);
    }
    if ($accion === 'ausencias') {
        $responder(200, consulta(db(), "SELECT a.usuario_id AS persona, u.num_empleado, t.nombre AS tipo, a.desde, a.hasta, a.hora_desde, a.hora_hasta, a.estado
            FROM ausencias a JOIN tipos_ausencia t ON t.id = a.tipo_id JOIN usuarios u ON u.id = a.usuario_id
            WHERE a.estado = 'aprobada' AND a.desde <= ? AND a.hasta >= ? ORDER BY a.desde", [$hasta, $desde])->fetchAll());
    }
    $responder(404, ['error' => 'Acción no válida.']);
} catch (DomainException $e) {
    $responder(422, ['error' => $e->getMessage()]);
}
