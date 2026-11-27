<?php
// Avisos dentro de INDA + email. clave evita repetir el mismo aviso.

function notificar(PDO $db, int $uid, string $clave, string $texto, string $enlace = '', bool $siempre = false): bool
{
    $s = consulta($db, 'INSERT IGNORE INTO avisos (usuario_id, clave, texto, enlace, creado_utc) VALUES (?, ?, ?, ?, ?)',
        [$uid, mb_substr($clave, 0, 100), mb_substr($texto, 0, 500), $enlace, ahora()]);
    if (!$s->rowCount()) return false;
    $u = usuario($db, $uid);
    // Envío marcado «sin email» (casilla del formulario): queda en la campana de INDA, sin correo ni móvil.
    if (!$siempre && sin_correo()) return true;
    // «Avisar a los trabajadores» desactivado (p. ej. mientras se suben datos): queda en la campana, sin correo ni móvil.
    if (!$siempre && $u && $u['rol'] === 'empleado' && !avisos_a_trabajadores($db)) return true;
    if ($u && $u['activo']) {
        $url = rtrim(config()['url'] ?? '', '/') . ($enlace !== '' ? "/?p=$enlace" : '/');
        enviar_correo($u['email'], 'INDA: ' . mb_strimwidth($texto, 0, 70, '…'), "$texto\n\n$url");
        enviar_push($db, $uid);
    }
    return true;
}

// Durante esta petición, no mandar correos (sin_correo(true)); sin argumento, dice si está activo.
function sin_correo(?bool $v = null): bool
{
    static $sin = false;
    if ($v !== null) $sin = $v;
    return $sin;
}

function avisos_a_trabajadores(PDO $db): bool
{
    return ajuste($db, 'avisos_trabajadores') !== 'no';
}

// Un solo aviso a toda la plantilla (al terminar de subir el cuadrante, en vez de un correo por cada cambio).
function anunciar_cambios(PDO $db, array $yo, string $texto): int
{
    $n = 0;
    $clave = 'general:' . ahora();
    foreach ($db->query("SELECT id FROM usuarios WHERE activo = 1 AND rol = 'empleado'")->fetchAll(PDO::FETCH_COLUMN) as $id) $n += (int)notificar($db, (int)$id, $clave, $texto, 'calendario', true);
    auditar($db, (int)$yo['id'], 'aviso_general', "personas=$n");
    return $n;
}

// Quién resuelve las solicitudes de $u: su responsable o, si no tiene, los administradores.
function aprobadores(PDO $db, array $u): array
{
    if ($u['responsable_id']) return [(int)$u['responsable_id']];
    return array_map('intval', consulta($db, "SELECT id FROM usuarios WHERE rol = 'admin' AND activo = 1 AND id <> ?", [$u['id']])->fetchAll(PDO::FETCH_COLUMN));
}

function avisar_aprobadores(PDO $db, array $u, string $clave, string $texto, string $enlace = 'equipo'): void
{
    foreach (aprobadores($db, $u) as $id) notificar($db, $id, $clave, $texto, $enlace);
    avisar_administracion('INDA: ' . mb_strimwidth($texto, 0, 70, '…'), $texto . "\n\n" . rtrim(config()['url'] ?? '', '/') . "/?p=$enlace");
}

function avisos_sin_leer(PDO $db, int $uid): int
{
    return (int)consulta($db, 'SELECT COUNT(*) FROM avisos WHERE usuario_id = ? AND leido_utc IS NULL', [$uid])->fetchColumn();
}

// Del día 1 (y otra vez el 5) en adelante, a partir de las 9:00: firma el registro del mes pasado.
function recordar_firmas(PDO $db, ?string $ahora = null): int
{
    $ahora ??= ahora();
    if (local($ahora, 'H') < 9) return 0;
    $mes = (new DateTime(local($ahora, 'Y-m-01')))->modify('-1 month')->format('Y-m');
    $vuelta = (int)local($ahora, 'j') >= 5 ? 2 : 1;
    $n = 0;
    foreach ($db->query("SELECT * FROM usuarios WHERE activo = 1 AND rol IN ('empleado','responsable','admin')")->fetchAll() as $u) {
        if (local($u['alta_utc'], 'Y-m') > $mes || !consulta($db, 'SELECT 1 FROM fichajes WHERE usuario_id = ? LIMIT 1', [$u['id']])->fetchColumn()) continue;
        if (consulta($db, 'SELECT 1 FROM avisos WHERE usuario_id = ? AND clave = ?', [$u['id'], "firma:$mes:$vuelta"])->fetchColumn()) continue;
        $f = firma_mes($db, $u, $mes);
        if ($f && $f['hash'] === hash('sha256', contenido_mes($db, $u, $mes))) continue;
        for ($v = 1; $v <= $vuelta; $v++) {
            $n += notificar($db, (int)$u['id'], "firma:$mes:$v", "Firma tu registro de jornada de $mes (Mi registro → Firmar el mes).", "historial&mes=$mes");
        }
    }
    return $n;
}

// Cron cada 15 min: falta la entrada, entrada con retraso (al responsable) y jornada sin cerrar.
function revisar_avisos(PDO $db, ?string $ahora = null): int
{
    $ahora ??= ahora();
    $hoy = local($ahora, 'Y-m-d');
    $hora = local($ahora, 'H:i:s');
    $ahora_ts = strtotime(local($ahora, 'Y-m-d H:i:s'));
    $n = 0;
    $incidencias = [];
    $ts = fn(string $h, int $min = 0) => ts_local($hoy, $h) + 60 * $min;
    foreach ($db->query("SELECT * FROM usuarios WHERE activo = 1 AND sin_fichaje = 0 AND fichaje_auto = 0 AND rol IN ('empleado','responsable','admin')")->fetchAll() as $u) {
        $c = calendario_teorico($db, $u, $hoy, $hoy)[$hoy];
        $estado = estado_actual($db, (int)$u['id']);
        if (!$c['flexible'] && $c['entrada'] && $c['minutos'] > 0) {
            $limite = $ts($c['entrada'], $c['margen']);
            $media = $ts($c['entrada'], 30); // entrada olvidada: aviso a la persona a los 30 minutos
            $primera = consulta($db, "SELECT MIN(momento_utc) FROM fichajes f WHERE usuario_id = ? AND tipo = 'entrada' AND momento_utc >= ? AND momento_utc <= ? AND " . NO_ANULADO,
                [$u['id'], a_utc("$hoy 00:00:00"), $ahora])->fetchColumn();
            if (!$primera && $estado === 'fuera' && $ahora_ts > $media) {
                $v = ['nombre' => $u['nombre'], 'fecha' => local($ahora, 'd/m/Y'), 'hora' => substr($c['entrada'], 0, 5), 'turno' => $c['horario'] ?? '', 'fichado' => ''];
                if (notificar($db, (int)$u['id'], "sin_entrada:$hoy", texto_aviso($db, 'sin_entrada', $v), 'fichar')) $n++; // a administración, a la hora (avisar_olvidos)
            }
            if ($primera && strtotime(local($primera, 'Y-m-d H:i:s')) > $limite) {
                $txt = texto_aviso($db, 'retraso', ['nombre' => nombre($u), 'fecha' => local($ahora, 'd/m/Y'), 'hora' => substr($c['entrada'], 0, 5), 'turno' => $c['horario'] ?? '', 'fichado' => local($primera, 'H:i')]);
                $avisados = 0;
                foreach (aprobadores($db, $u) as $jefe) $avisados += notificar($db, $jefe, "retraso:{$u['id']}:$hoy", $txt, "historial&u={$u['id']}");
                if ($avisados) {
                    $n += $avisados;
                    $incidencias[] = $txt;
                }
            }
        }
        if ($estado !== 'fuera') {
            $e = consulta($db, "SELECT id, momento_utc FROM fichajes f WHERE usuario_id = ? AND tipo = 'entrada' AND " . NO_ANULADO . ' ORDER BY momento_utc DESC, id DESC LIMIT 1', [$u['id']])->fetch();
            $abierta = $e ? strtotime("$ahora UTC") - strtotime("{$e['momento_utc']} UTC") : 0;
            $tarde = $c['salida'] && $c['entrada'] && $c['salida'] > $c['entrada'] && $hora > $suma($c['salida'], 60) && local($e['momento_utc'] ?? '', 'Y-m-d') === $hoy;
            if ($e && ($abierta > 12 * 3600 || $tarde)) {
                $v = ['nombre' => $u['nombre'], 'fecha' => local($e['momento_utc'], 'd/m'), 'hora' => substr((string)$c['salida'], 0, 5), 'turno' => $c['horario'] ?? '', 'fichado' => local($e['momento_utc'], 'H:i')];
                if (notificar($db, (int)$u['id'], "sin_salida:{$e['id']}", texto_aviso($db, 'sin_salida', $v), 'fichar')) {
                    $n++;
                    $incidencias[] = nombre($u) . ': jornada abierta desde las ' . local($e['momento_utc'], 'H:i') . ' del ' . local($e['momento_utc'], 'd/m') . ' (no ha fichado la salida).';
                }
            }
        }
    }
    // Un solo correo a administración con lo nuevo de esta revisión.
    if ($incidencias) {
        avisar_administracion('INDA: ' . count($incidencias) . ' incidencias de fichaje · ' . local($ahora, 'd/m H:i'),
            'Incidencias detectadas a las ' . local($ahora, 'H:i') . ":\n\n- " . implode("\n- ", $incidencias) . "\n\n" . rtrim(config()['url'] ?? '', '/') . '/?p=equipo');
    }
    return $n;
}
