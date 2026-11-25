<?php
// Valoración interna de calidad (1–5 estrellas). Función oculta: solo la ve y la usa el administrador que la activa;
// ni otros administradores, ni responsables, ni el trabajador, ni Inspección. No sale en exportaciones ni en el asistente.

function valoraciones_de(PDO $db): ?int
{
    $v = ajuste($db, 'valoraciones_admin');
    return $v ? (int)$v : null;
}

function ve_valoraciones(PDO $db, array $yo): bool
{
    return $yo['rol'] === 'admin' && valoraciones_de($db) === (int)$yo['id'];
}

// Activar (solo si nadie la tiene) o desactivar (solo quien la activó). Los datos se conservan.
function activar_valoraciones(PDO $db, array $yo, bool $si): void
{
    $dueno = valoraciones_de($db);
    if ($yo['rol'] !== 'admin' || ($dueno && $dueno !== (int)$yo['id'])) throw new DomainException('No disponible.');
    consulta($db, 'REPLACE INTO ajustes VALUES (?, ?)', ['valoraciones_admin', $si ? (string)$yo['id'] : '']);
}

function valorar(PDO $db, array $yo, int $uid, int $estrellas, string $nota): void
{
    if (!ve_valoraciones($db, $yo)) throw new DomainException('No disponible.');
    if ($estrellas < 1 || $estrellas > 5) throw new DomainException('De 1 a 5 estrellas.');
    consulta($db, 'INSERT INTO valoraciones (usuario_id, estrellas, nota, autor_id, creado_utc) VALUES (?, ?, ?, ?, ?)', [$uid, $estrellas, mb_substr(trim($nota), 0, 500), $yo['id'], ahora()]);
}

// Historial de una persona (la más reciente primero) y su media.
function valoraciones(PDO $db, array $yo, int $uid): array
{
    if (!ve_valoraciones($db, $yo)) return ['lista' => [], 'media' => null];
    $l = consulta($db, 'SELECT * FROM valoraciones WHERE usuario_id = ? ORDER BY creado_utc DESC, id DESC', [$uid])->fetchAll();
    return ['lista' => $l, 'media' => $l ? round(array_sum(array_column($l, 'estrellas')) / count($l), 1) : null];
}

function estrellas(?float $n): string
{
    if ($n === null) return '—';
    $r = (int)round($n);
    return str_repeat('★', $r) . str_repeat('☆', 5 - $r);
}

const APARTADOS = ['trato' => 'Trato a los residentes', 'tecnica' => 'Calidad técnica', 'equipo' => 'Trabajo en equipo', 'iniciativa' => 'Iniciativa y responsabilidad', 'actitud' => 'Actitud y comunicación'];
const TIPOS_APUNTE = ['incidencia' => 'Incidencia', 'mala_conducta' => 'Mala conducta', 'amonestacion' => 'Amonestación'];

// Valoración con los 5 apartados (la nota de la valoración es su media).
function valorar_apartados(PDO $db, array $yo, int $uid, array $notas, string $nota): void
{
    if (!ve_valoraciones($db, $yo)) throw new DomainException('No disponible.');
    $v = [];
    foreach (APARTADOS as $k => $_) {
        $n = (int)($notas[$k] ?? 0);
        if ($n < 1 || $n > 5) throw new DomainException('Pon de 1 a 5 estrellas en cada apartado.');
        $v[$k] = $n;
    }
    consulta($db, 'INSERT INTO valoraciones (usuario_id, estrellas, nota, autor_id, creado_utc, trato, tecnica, equipo, iniciativa, actitud) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$uid, (int)round(array_sum($v) / 5), mb_substr(trim($nota), 0, 500), $yo['id'], ahora(), ...array_values($v)]);
}

function apuntar(PDO $db, array $yo, int $uid, string $tipo, string $fecha, string $texto): void
{
    if (!ve_valoraciones($db, $yo)) throw new DomainException('No disponible.');
    if (!isset(TIPOS_APUNTE[$tipo]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || trim($texto) === '') throw new DomainException('Pon el tipo, la fecha y qué pasó.');
    consulta($db, 'INSERT INTO apuntes (usuario_id, tipo, texto, fecha, autor_id, creado_utc) VALUES (?, ?, ?, ?, ?, ?)', [$uid, $tipo, mb_substr(trim($texto), 0, 1000), $fecha, $yo['id'], ahora()]);
}

function apuntes_de(PDO $db, array $yo, int $uid): array
{
    return ve_valoraciones($db, $yo) ? consulta($db, 'SELECT * FROM apuntes WHERE usuario_id = ? ORDER BY fecha DESC, id DESC', [$uid])->fetchAll() : [];
}

// Escala: el primer umbral que se cumple da 5, el siguiente 4…; si no cumple ninguno, 1.
function escala(float $v, array $umbrales, bool $menos_mejor = true): int
{
    foreach ($umbrales as $i => $u) if ($menos_mejor ? $v <= $u : $v >= $u) return 5 - $i;
    return 1;
}

// Parte objetiva (últimos 6 meses), de 1 a 5 cada criterio. Las ausencias aprobadas (bajas, vacaciones, permisos…) no
// cuentan nunca: solo lo que depende de la persona. Devuelve [criterio => nota|null].
function evaluacion_objetiva(PDO $db, array $u, ?string $hasta = null): array
{
    $hasta ??= date('Y-m-d', strtotime(local(ahora(), 'Y-m-d') . ' -1 day'));
    $desde = max(date('Y-m-d', strtotime("$hasta -180 days")), local($u['alta_utc'], 'Y-m-d'));
    // Solo desde que empezó a fichar en INDA (antes fichaba en otro sistema: no son faltas).
    $primero = consulta($db, "SELECT MIN(momento_utc) FROM fichajes WHERE usuario_id = ? AND origen <> 'automatico'", [$u['id']])->fetchColumn();
    if (empty($u['sin_fichaje']) && empty($u['fichaje_auto'])) $desde = $primero ? max($desde, local($primero, 'Y-m-d')) : '9999-12-31';
    $out = ['puntualidad' => null, 'fichaje' => null, 'confianza' => null, 'colaboracion' => null];
    if ($desde > $hasta) return $out;
    $cal = calendario_teorico($db, $u, $desde, $hasta);
    $entradas = [];
    foreach (fichajes_de($db, (int)$u['id'], a_utc("$desde 00:00:00"), a_utc(date('Y-m-d', strtotime("$hasta +1 day")) . ' 00:00:00')) as $f) {
        if ($f['tipo'] === 'entrada' && empty($f['anulado'])) $entradas[local($f['momento_utc'], 'Y-m-d')] ??= $f;
    }
    $ficha = empty($u['sin_fichaje']) && empty($u['fichaje_auto']);
    $turnos = $puntuales = $faltas = 0;
    foreach ($cal as $f => $c) {
        if (!$c['turno'] || $c['ausencia'] || $c['festivo']) continue;
        $turnos++;
        if (!$ficha) continue;
        if (!isset($entradas[$f])) { $faltas++; continue; }
        if ($c['entrada'] && !$c['flexible'] && strtotime($entradas[$f]['momento_utc'] . ' UTC') <= ts_local($f, $c['entrada']) + 60 * max(5, (int)$c['margen'])) $puntuales++;
    }
    $ini = a_utc("$desde 00:00:00");
    if ($ficha && $turnos >= 5) {
        $out['puntualidad'] = escala($puntuales / max(1, $turnos - $faltas), [0.95, 0.9, 0.8, 0.65], false);
        $olvidos = (int)consulta($db, "SELECT COUNT(*) FROM fichajes WHERE usuario_id = ? AND origen = 'automatico' AND tipo = 'salida' AND momento_utc >= ?", [$u['id'], $ini])->fetchColumn();
        $corr = (int)consulta($db, 'SELECT COUNT(*) FROM correcciones WHERE usuario_id = ? AND pedida_utc >= ?', [$u['id'], $ini])->fetchColumn();
        $out['fichaje'] = escala(($olvidos + $corr) / $turnos, [0.02, 0.05, 0.1, 0.2]);
    }
    if ($turnos >= 5) {
        // Confianza: faltas sin ausencia que las justifique y pausas sin su justificante.
        $sinj = count(pausas_sin_justificante($db, (int)$u['id'], $ini));
        $out['confianza'] = escala($faltas + $sinj, [0, 1, 2, 4]);
        $ayuda = (int)consulta($db, "SELECT COUNT(*) FROM cambios_turno WHERE companero_id = ? AND estado = 'aprobado' AND pedido_utc >= ?", [$u['id'], $ini])->fetchColumn()
            + (int)consulta($db, "SELECT COUNT(*) FROM ofertas_turno WHERE usuario_id = ? AND estado = 'aceptada' AND fecha >= ?", [$u['id'], $desde])->fetchColumn();
        $out['colaboracion'] = $ayuda >= 3 ? 5 : ($ayuda ? 4 : 3);
    }
    return $out;
}

// Evaluación completa: 40 % objetiva + 60 % subjetiva (valoraciones de los últimos 12 meses), menos 1 estrella por cada
// 2 apuntes del último año. De 1 a 5, o null si no hay datos. Solo para quien ve las valoraciones.
function evaluacion(PDO $db, array $yo, array $u): ?array
{
    if (!ve_valoraciones($db, $yo)) return null;
    $obj = evaluacion_objetiva($db, $u);
    $o = array_filter($obj, fn($x) => $x !== null);
    $hace = gmdate('Y-m-d H:i:s', strtotime('-365 days'));
    $vals = consulta($db, 'SELECT * FROM valoraciones WHERE usuario_id = ? AND creado_utc >= ?', [$u['id'], $hace])->fetchAll();
    $aps = (int)consulta($db, 'SELECT COUNT(*) FROM apuntes WHERE usuario_id = ? AND fecha >= ?', [$u['id'], substr($hace, 0, 10)])->fetchColumn();
    $media_o = $o ? array_sum($o) / count($o) : null;
    $media_s = $vals ? array_sum(array_column($vals, 'estrellas')) / count($vals) : null;
    $apart = [];
    foreach (APARTADOS as $k => $_) {
        $xs = array_filter(array_column($vals, $k), fn($x) => $x !== null);
        $apart[$k] = $xs ? round(array_sum($xs) / count($xs), 1) : null;
    }
    $base = $media_o === null ? $media_s : ($media_s === null ? $media_o : 0.4 * $media_o + 0.6 * $media_s);
    $final = $base === null ? null : max(1.0, min(5.0, round($base - intdiv($aps, 2), 1)));
    return ['objetiva' => $obj, 'media_objetiva' => $media_o === null ? null : round($media_o, 1), 'subjetiva' => $media_s === null ? null : round($media_s, 1),
        'apartados' => $apart, 'apuntes' => $aps, 'final' => $final];
}

// Notas finales de varias personas para el cuadrante: [uid => nota]; quien no tiene datos cuenta como 3.
function notas_calidad(PDO $db, array $yo, array $personas): array
{
    $out = [];
    foreach ($personas as $p) $out[(int)$p['id']] = evaluacion($db, $yo, $p)['final'] ?? 3.0;
    return $out;
}

// Lectura con IA (DNI, nóminas, convenio, festivos) y asistente: si hay propietario de las funciones privadas, solo él.
function ia_permitida(?array $yo): bool
{
    $d = valoraciones_de(db());
    return !$d || ($yo && (int)$yo['id'] === $d);
}
