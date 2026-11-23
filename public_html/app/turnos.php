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
            $datos = [mb_substr(mb_strtoupper(trim($letra)), 0, 3), preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '', $did];
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
