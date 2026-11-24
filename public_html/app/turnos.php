<?php
// Turnos de la residencia (plantilla editable) y mensajes de aviso por email personalizables.

// [nombre, letra, color, entrada, salida, horas, departamento]. Mañana y limpieza: según los fichajes de la residencia;
// tarde, noche y partido: horario habitual de residencias. Se revisan en pantalla antes de crearlos.
const TURNOS_RESIDENCIA = [
    ['AUXILIAR MAÑANA', 'M', '#f59e0b', '07:00', '15:00', '8:00', 'AUXILIAR / GEROCULTOR'],
    ['AUXILIAR TARDE', 'T', '#3b82f6', '15:00', '23:00', '8:00', 'AUXILIAR / GEROCULTOR'],
    ['AUXILIAR NOCHE', 'N', '#6d28d9', '23:00', '07:00', '8:00', 'AUXILIAR / GEROCULTOR'],
    ['COORDINACIÓN PARTIDO', 'P', '#0d9488', '09:00', '19:00', '8:00', 'COORDINACIÓN /AUXILIAR'],
    ['LIMPIEZA MAÑANA', 'M', '#f59e0b', '08:00', '15:30', '7:30', 'LIMPIEZA'],
    ['LIMPIEZA TARDE', 'T', '#3b82f6', '15:00', '21:30', '6:30', 'LIMPIEZA'],
];

// Crea o actualiza los turnos (por nombre). Como horario habitual valen de lunes a viernes; en Planificación
// se pueden poner cualquier día (fines de semana, rotaciones) y los descansos.
// $filas: [[nombre, letra, color, entrada, salida, horas, departamento], …]. Devuelve [creados, actualizados].
function guardar_turnos(PDO $db, array $filas): array
{
    $hors = $deps = [];
    foreach ($db->query('SELECT id, nombre FROM horarios') as $h) $hors[normalizar($h['nombre'])] = (int)$h['id'];
    foreach ($db->query('SELECT id, nombre FROM departamentos') as $d) $deps[normalizar($d['nombre'])] = (int)$d['id'];
    $min = function (string $t): int {
        if (!preg_match('/^(\d{1,2}):([0-5]\d)$/', trim($t), $m)) throw new DomainException("Horas no válidas: $t");
        return $m[1] * 60 + $m[2];
    };
    $res = [0, 0];
    $db->beginTransaction();
    try {
        foreach ($filas as [$nombre, $letra, $color, $e, $s, $horas, $dep]) {
            $nombre = trim($nombre);
            if ($nombre === '') continue;
            if (!preg_match('/^\d{2}:\d{2}$/', $e) || !preg_match('/^\d{2}:\d{2}$/', $s)) throw new DomainException("Pon entrada y salida en $nombre.");
            $did = null;
            if (trim($dep) !== '') {
                $did = $deps[normalizar($dep)] ?? null;
                if (!$did) {
                    consulta($db, 'INSERT INTO departamentos (nombre) VALUES (?)', [mb_substr(trim($dep), 0, 100)]);
                    $did = $deps[normalizar($dep)] = (int)$db->lastInsertId();
                }
            }
            $datos = [mb_substr(mb_strtoupper(trim($letra)), 0, 3), preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : color_turno($nombre, $e), $did];
            if ($id = $hors[normalizar($nombre)] ?? null) {
                consulta($db, 'UPDATE horarios SET abreviatura = ?, color = ?, departamento_id = ?, flexible = 0, activo = 1 WHERE id = ?', [...$datos, $id]);
                $res[1]++;
            } else {
                consulta($db, 'INSERT INTO horarios (nombre, flexible, margen_min, activo, abreviatura, color, departamento_id) VALUES (?, 0, 10, 1, ?, ?, ?)', [mb_substr($nombre, 0, 80), ...$datos]);
                $id = $hors[normalizar($nombre)] = (int)$db->lastInsertId();
                $res[0]++;
            }
            consulta($db, 'DELETE FROM horario_dias WHERE horario_id = ?', [$id]);
            for ($d = 1; $d <= 5; $d++) consulta($db, 'INSERT INTO horario_dias VALUES (?, ?, ?, ?, ?)', [$id, $d, "$e:00", "$s:00", $min($horas)]);
        }
        $db->commit();
    } catch (Throwable $t) {
        $db->rollBack();
        throw $t;
    }
    return $res;
}

// Lo que se pinta en una celda del calendario: [texto, color de fondo, título].
function celda_turno(array $c, array $hors, bool $detalle): array
{
    if ($c['ausencia']) {
        $ini = implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_filter(explode(' ', $c['ausencia']['tipo']), fn($w) => mb_strlen($w) > 3 || $w === 'IT')));
        return $detalle ? [$ini, $c['ausencia']['color'], $c['ausencia']['tipo']] : ['·', '#6b7280', 'Ausente'];
    }
    if ($c['festivo'] && !$c['turno']) return ['F', '', $c['festivo']];
    $h = $c['horario_id'] ? ($hors[$c['horario_id']] ?? null) : null;
    if (!$c['turno']) return [$c['laborable'] ? '' : 'D', '', $c['laborable'] ? '' : 'Descanso'];
    $titulo = ($c['horario'] ?? '') . ($c['entrada'] && !$c['flexible'] ? ' ' . substr($c['entrada'], 0, 5) . '–' . substr((string)$c['salida'], 0, 5) : '');
    if ($h && $h['abreviatura'] !== '') return [$h['abreviatura'], $h['color'], $titulo];
    return [rtrim(rtrim(number_format($c['turno'] / 60, 1, ',', ''), '0'), ','), '', $titulo];
}

// ---------- Mensajes de aviso ----------

const PLANTILLAS_AVISO = [
    'sin_entrada' => ['A la persona, si no ha fichado la entrada a tiempo',
        "Hola {nombre}:\n\nHoy {fecha} tu turno ({turno}) empezaba a las {hora} y aún no has fichado la entrada.\nSi ya estás trabajando, ficha ahora desde INDA. Si no vas a venir, avisa a tu responsable cuanto antes."],
    'retraso' => ['Al responsable, si alguien ficha tarde',
        "{nombre} ha fichado la entrada a las {fichado} (su turno {turno} empezaba a las {hora})."],
    'recordar_salida' => ['A la persona, cuando termina su turno y sigue sin fichar la salida',
        "Hola {nombre}:\n\nTu turno ({turno}) terminaba a las {hora}. Ficha la salida en INDA: si no, se cerrará sola dentro de unos minutos."],
    'cierre_auto' => ['A la persona, cuando INDA le cierra la jornada',
        "Hola {nombre}:\n\nNo fichaste la salida. INDA ha cerrado tu jornada a las {fichado} (tu turno {turno} terminaba a las {hora}).\nSi seguiste trabajando, pide una corrección en INDA con la hora real."],
    'sin_salida' => ['A la persona, si se le olvida fichar la salida',
        "Hola {nombre}:\n\nTu jornada sigue abierta desde las {fichado} del {fecha}. ¿Olvidaste fichar la salida?\nSi fue un despiste, ficha ahora o pide una corrección en INDA."],
];

function plantilla_aviso(PDO $db, string $clave): string
{
    $guardadas = json_decode(ajuste($db, 'plantillas_aviso') ?? '[]', true) ?: [];
    return trim($guardadas[$clave] ?? '') !== '' ? $guardadas[$clave] : PLANTILLAS_AVISO[$clave][1];
}

// Sustituye {nombre}, {fecha}, {hora}, {turno}, {fichado}.
function texto_aviso(PDO $db, string $clave, array $v): string
{
    return strtr(plantilla_aviso($db, $clave), array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($v)), array_values($v)));
}

// Minutos tras la hora de salida para cerrar la jornada sola (0 = no se cierra). Por defecto, 5.
function minutos_cierre(PDO $db): int
{
    $v = consulta($db, "SELECT valor FROM ajustes WHERE clave = 'cierre_auto_min'")->fetchColumn(); // ajuste() trata «0» como vacío
    return $v === false ? 5 : max(0, (int)$v);
}

// Cron: al acabar el turno, recordatorio; pasados los minutos de cierre, salida automática a la hora de salida + esos minutos.
// La salida queda marcada como automática (origen) y con la hora real de registro (creado_utc), y se puede corregir.
function cerrar_jornadas(PDO $db, ?string $ahora = null): int
{
    $margen = minutos_cierre($db);
    if (!$margen) return 0;
    $ahora ??= ahora();
    $t = strtotime("$ahora UTC");
    $n = 0;
    $lineas = [];
    foreach ($db->query("SELECT * FROM usuarios WHERE activo = 1 AND rol IN ('empleado','responsable','admin')")->fetchAll() as $u) {
        if (estado_actual($db, (int)$u['id']) === 'fuera') continue;
        $e = consulta($db, "SELECT id, momento_utc FROM fichajes f WHERE usuario_id = ? AND tipo = 'entrada' AND " . NO_ANULADO . ' ORDER BY momento_utc DESC, id DESC LIMIT 1', [$u['id']])->fetch();
        if (!$e) continue;
        $te = strtotime("{$e['momento_utc']} UTC");
        // El turno es el del día en que entró (o el del día anterior si es una noche que empezó ayer).
        $fin = null;
        foreach ([local($e['momento_utc'], 'Y-m-d'), date('Y-m-d', strtotime(local($e['momento_utc'], 'Y-m-d') . ' -1 day'))] as $dia) {
            $c = calendario_teorico($db, $u, $dia, $dia)[$dia];
            if ($c['flexible'] || !$c['entrada'] || !$c['salida'] || !$c['turno']) continue;
            $ini = ts_local($dia, $c['entrada']);
            $f = ts_local($dia, $c['salida']);
            if ($f <= $ini) $f += 86400;
            if ($te >= $ini - 4 * 3600 && $te < $f) { $fin = $f; break; } // entró para este turno
        }
        if (!$fin || $t < $fin) continue;
        $v = ['nombre' => $u['nombre'], 'fecha' => date('d/m', $fin), 'hora' => (new DateTime("@$fin"))->setTimezone(new DateTimeZone(ZONA))->format('H:i'), 'turno' => $c['horario'] ?? '', 'fichado' => ''];
        if ($t < $fin + 60 * $margen) {
            $n += notificar($db, (int)$u['id'], "recordar_salida:{$e['id']}", texto_aviso($db, 'recordar_salida', $v), 'fichar');
            continue;
        }
        $ultimo = (int)strtotime(consulta($db, 'SELECT MAX(momento_utc) FROM fichajes WHERE usuario_id = ?', [$u['id']])->fetchColumn() . ' UTC');
        $momento = gmdate('Y-m-d H:i:s', max($fin + 60 * $margen, $ultimo + 1));
        try {
            $id = registrar_fichaje($db, $u, 'salida', 'automatico', [], $momento);
        } catch (DomainException) {
            continue;
        }
        $v['fichado'] = local($momento, 'H:i');
        notificar($db, (int)$u['id'], "cierre_auto:$id", texto_aviso($db, 'cierre_auto', $v), 'correcciones');
        auditar($db, null, 'salida_automatica', "usuario={$u['id']} fichaje=$id");
        $lineas[] = nombre($u) . ": salida automática a las {$v['fichado']} (turno hasta las {$v['hora']}).";
        $n++;
    }
    if ($lineas) avisar_administracion('INDA: ' . count($lineas) . ' salidas cerradas automáticamente', implode("\n", $lineas) . "\n\n" . rtrim(config()['url'] ?? '', '/') . '/?p=equipo');
    return $n;
}

// Cambia el turno de una persona un día: '' = su horario habitual, 'L' = descanso, número = turno.
// Comprueba el convenio: si algo bloquea, no se guarda y lanza el motivo. Devuelve los avisos (no bloquean).
function cambiar_dia(PDO $db, array $yo, array $u, string $fecha, string $v): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) throw new DomainException('Fecha no válida.');
    if ($v !== '' && $v !== 'L' && !isset(horarios($db)[(int)$v])) throw new DomainException('Turno no válido.');
    $hasta = date('Y-m-d', strtotime("$fecha +1 day")); // también el descanso hasta el día siguiente
    $clave = fn($m) => "$m[0]|$m[1]";
    // Solo bloquea lo que provoca este cambio: un incumplimiento que ya estaba (p. ej. de un cuadrante importado) no impide corregir el día.
    $antes = array_map($clave, array_filter(comprobar_plan($db, $u, $fecha, $hasta), fn($m) => $m[3] === 'bloqueo'));
    $db->beginTransaction();
    try {
        if ($v === '') consulta($db, 'DELETE FROM planificacion WHERE usuario_id = ? AND fecha = ?', [$u['id'], $fecha]);
        else consulta($db, 'REPLACE INTO planificacion (usuario_id, fecha, horario_id) VALUES (?, ?, ?)', [$u['id'], $fecha, $v === 'L' ? null : (int)$v]);
        $mal = comprobar_plan($db, $u, $fecha, $hasta);
        $nuevos = array_values(array_filter($mal, fn($m) => $m[3] === 'bloqueo' && !in_array($clave($m), $antes, true)));
        if ($nuevos) throw new DomainException('No se puede: ' . $nuevos[0][2]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    notificar($db, (int)$u['id'], 'horario:' . ahora() . ":{$u['id']}:$fecha", 'Tu turno del ' . date('d/m', strtotime($fecha)) . ' ha cambiado. Revísalo en el calendario.', 'calendario');
    auditar($db, (int)$yo['id'], 'planificacion', "usuario={$u['id']} fecha=$fecha valor=" . ($v === '' ? 'habitual' : $v));
    return array_column($mal, 2);
}

// Opciones de turno para una persona: primero los de su departamento, luego el resto. [grupo => [id => texto]].
function opciones_turno(array $hors, array $u): array
{
    $suyos = $otros = [];
    foreach ($hors as $id => $h) {
        if (!$h['activo']) continue;
        $d1 = $h['dias'] ? reset($h['dias']) : null;
        $txt = ($h['abreviatura'] !== '' ? $h['abreviatura'] . ' · ' : '') . $h['nombre'] . ($d1 && $d1['entrada'] && !$h['flexible'] ? ' (' . substr($d1['entrada'], 0, 5) . '–' . substr($d1['salida'], 0, 5) . ')' : '');
        if ($h['departamento_id'] && $u['departamento_id'] && $h['departamento_id'] == $u['departamento_id']) $suyos[$id] = $txt;
        else $otros[$id] = $txt;
    }
    return array_filter(['Su departamento' => $suyos, $suyos ? 'Otros turnos' : 'Turnos' => $otros]);
}
