<?php
// Horario teórico de cada día, horas trabajadas, extra, nocturnas y bolsa de horas. Fechas locales (Y-m-d).

const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
const MESES = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];

function horarios(PDO $db): array
{
    $h = [];
    foreach ($db->query('SELECT * FROM horarios ORDER BY nombre') as $x) $h[$x['id']] = $x + ['dias' => []];
    foreach ($db->query('SELECT * FROM horario_dias') as $d) $h[$d['horario_id']]['dias'][$d['dia']] = $d;
    return $h;
}

function rango_fechas(string $desde, string $hasta): array
{
    $out = [];
    for ($d = new DateTime($desde); $d->format('Y-m-d') <= $hasta; $d->modify('+1 day')) $out[] = $d->format('Y-m-d');
    return $out;
}

// Por fecha: horario que toca, minutos teóricos, festivo y ausencia. Orden: planificación > festivo > horario de la persona.
function calendario_teorico(PDO $db, array $u, string $desde, string $hasta): array
{
    $h = horarios($db);
    $plan = consulta($db, 'SELECT fecha, horario_id, extra, fuerza_mayor, puesto_id FROM planificacion WHERE usuario_id = ? AND fecha BETWEEN ? AND ?', [$u['id'], $desde, $hasta])->fetchAll(PDO::FETCH_UNIQUE);
    $fest = consulta($db, 'SELECT fecha, nombre FROM festivos WHERE (centro_id IS NULL OR centro_id = ?) AND fecha BETWEEN ? AND ?', [$u['centro_id'], $desde, $hasta])->fetchAll(PDO::FETCH_KEY_PAIR);
    $aus = ausencias_por_dia($db, (int)$u['id'], $desde, $hasta);
    $alta = local($u['alta_utc'], 'Y-m-d');
    $baja = $u['baja_utc'] ? local($u['baja_utc'], 'Y-m-d') : '9999-12-31';
    $out = [];
    foreach (rango_fechas($desde, $hasta) as $f) {
        $p = $plan[$f] ?? null;
        $hid = $p ? $p['horario_id'] : (isset($fest[$f]) ? null : $u['horario_id']);
        // Un turno planificado vale cualquier día: si su horario no define ese día, se usa su primer día definido.
        $dia = $hid ? ($h[$hid]['dias'][(int)date('N', strtotime($f))] ?? ($p && $h[$hid]['dias'] ? reset($h[$hid]['dias']) : null)) : null;
        $turno = ($dia && $f >= $alta && $f <= $baja) ? (int)$dia['minutos'] : 0;
        $a = $aus[$f] ?? null;
        $min = $a ? ($a['minutos'] === null ? 0 : max(0, $turno - $a['minutos'])) : $turno;
        $extra = $p && $p['extra'] && !$a;
        $out[$f] = [
            'horario' => $hid ? $h[$hid]['nombre'] : null, 'horario_id' => $hid, 'puesto_id' => $p['puesto_id'] ?? null,
            // minutos = teóricos (no incluyen turnos aceptados como horas extra); turno = duración del turno que toca.
            'minutos' => $extra ? 0 : $min, 'turno' => $a && $a['minutos'] === null ? 0 : $turno,
            'extra' => $extra, 'fuerza_mayor' => $extra && $p['fuerza_mayor'],
            'entrada' => $dia['entrada'] ?? null, 'salida' => $dia['salida'] ?? null,
            'flexible' => $hid ? (bool)$h[$hid]['flexible'] : true, 'margen' => $hid ? (int)$h[$hid]['margen_min'] : 0,
            'festivo' => $fest[$f] ?? null, 'ausencia' => $a,
            'laborable' => $dia !== null || (!$hid && !$u['horario_id'] && !isset($fest[$f]) && date('N', strtotime($f)) < 6),
        ];
    }
    return $out;
}

// Segundos de los tramos que caen en horario nocturno (hora de Madrid). ET art. 36: 22:00–6:00; el convenio puede ampliarlo.
function segundos_nocturnos(array $tramos, int $desde_h = 22, int $hasta_h = 6): int
{
    $n = 0;
    $z = new DateTimeZone(ZONA);
    foreach ($tramos as [$a, $b]) {
        $d = (new DateTime('@' . $a))->setTimezone($z)->modify('-1 day')->setTime($desde_h, 0);
        while ($d->getTimestamp() < $b) {
            $ini = $d->getTimestamp();
            $fin = (clone $d)->modify('+1 day')->setTime($hasta_h, 0)->getTimestamp();
            $n += max(0, min($b, $fin) - max($a, $ini));
            $d->modify('+1 day');
        }
    }
    return $n;
}

// Resumen por día y total. Las diferencias (bolsa) solo cuentan días ya pasados.
function resumen(PDO $db, array $u, string $desde, string $hasta): array
{
    $cal = calendario_teorico($db, $u, $desde, $hasta);
    $hoy = local(ahora(), 'Y-m-d');
    $dias = [];
    foreach ($cal as $f => $c) $dias[$f] = $c + ['trabajado' => 0, 'nocturno' => 0, 'jornadas' => []];
    $todos = fichajes_de($db, (int)$u['id'], a_utc("$desde -1 day"), a_utc("$hasta +2 day"));
    $r = reglas($db);
    foreach (jornadas($todos) as $j) {
        $f = local($j['entrada'], 'Y-m-d');
        if (!isset($dias[$f])) continue;
        $dias[$f]['trabajado'] += $j['trabajado'];
        $dias[$f]['nocturno'] += segundos_nocturnos($j['tramos'], (int)$r['noche_desde_h'], (int)$r['noche_hasta_h']);
        $dias[$f]['jornadas'][] = $j;
    }
    $t = ['teorico' => 0, 'trabajado' => 0, 'extra' => 0, 'nocturno' => 0, 'festivo' => 0, 'diferencia' => 0];
    foreach ($dias as $f => &$d) {
        $teo = $d['minutos'] * 60;
        $d['extra'] = max(0, $d['trabajado'] - $teo);
        $d['diferencia'] = $f < $hoy ? $d['trabajado'] - $teo : 0;
        $t['teorico'] += $teo;
        $t['trabajado'] += $d['trabajado'];
        $t['extra'] += $d['extra'];
        $t['nocturno'] += $d['nocturno'];
        if ($d['festivo'] || !$d['laborable']) $t['festivo'] += $d['trabajado'];
        $t['diferencia'] += $d['diferencia'];
    }
    return ['dias' => $dias, 'total' => $t];
}

// Bolsa de horas en segundos: días pasados desde el alta + ajustes manuales.
function saldo_bolsa(PDO $db, array $u): int
{
    $ayer = local(gmdate('Y-m-d H:i:s', time() - 86400), 'Y-m-d');
    $alta = local($u['alta_utc'], 'Y-m-d');
    $dif = $alta <= $ayer ? resumen($db, $u, $alta, $ayer)['total']['diferencia'] : 0;
    return $dif + 60 * (int)consulta($db, 'SELECT COALESCE(SUM(minutos), 0) FROM ajustes_saldo WHERE usuario_id = ?', [$u['id']])->fetchColumn();
}

// Signo incluido: -1:30, +2:05.
function horas_signo(int $s): string
{
    return ($s < 0 ? '-' : '+') . horas(abs($s));
}

// Festivos desde un archivo: calendario .ics (Google, Outlook…) o CSV/Excel con "fecha;nombre"
// (fecha dd/mm/aaaa o aaaa-mm-dd). Devuelve [[fecha Y-m-d, nombre], …].
function leer_festivos(string $txt): array
{
    $out = [];
    $txt = preg_replace("/\r?\n[ \t]/", '', str_replace("\xEF\xBB\xBF", '', $txt)); // ICS: líneas partidas
    if (str_contains($txt, 'BEGIN:VEVENT')) {
        preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $txt, $ev);
        foreach ($ev[1] as $e) {
            if (!preg_match('/^DTSTART[^:\n]*:(\d{8})/m', $e, $d)) continue;
            $nombre = preg_match('/^SUMMARY[^:\n]*:(.*)$/m', $e, $s) ? trim(str_replace(['\\,', '\\;', '\\n'], [',', ';', ' '], $s[1])) : 'Festivo';
            $ini = DateTime::createFromFormat('!Ymd', $d[1]);
            $fin = preg_match('/^DTEND[^:\n]*:(\d{8})/m', $e, $h) ? DateTime::createFromFormat('!Ymd', $h[1]) : (clone $ini)->modify('+1 day');
            for ($f = clone $ini; $f < $fin && count($out) < 1000; $f->modify('+1 day')) $out[] = [$f->format('Y-m-d'), mb_substr($nombre, 0, 100)];
        }
        return $out;
    }
    foreach (preg_split('/\r?\n/', $txt) as $linea) {
        $c = str_getcsv($linea, str_contains($linea, ';') ? ';' : ',');
        $f = trim($c[0] ?? '');
        $fecha = preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $f, $m) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1])
            : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) ? $f : null);
        if ($fecha && checkdate((int)substr($fecha, 5, 2), (int)substr($fecha, 8, 2), (int)substr($fecha, 0, 4))) {
            $out[] = [$fecha, mb_substr(trim($c[1] ?? '') ?: 'Festivo', 0, 100)];
        }
    }
    return $out;
}
