<?php
// Lectura con IA (Google Gemini): DNI, convenios y calendarios laborales.
// Clave en config-inda.php: 'gemini_clave' => '…' (nunca en el repo). Modelo opcional: 'gemini_modelo'.
// Los archivos se envían a Google solo cuando el admin lo pide y no se guardan en INDA.

const IA_ESQUEMAS = [
    'dni' => ['type' => 'OBJECT', 'properties' => [
        'nombre' => ['type' => 'STRING'], 'apellidos' => ['type' => 'STRING'],
        'dni' => ['type' => 'STRING', 'description' => 'Número de DNI o NIE con su letra, sin espacios'],
        'fecha_nacimiento' => ['type' => 'STRING', 'description' => 'AAAA-MM-DD'], 'caducidad' => ['type' => 'STRING', 'description' => 'AAAA-MM-DD'],
    ], 'required' => ['nombre', 'apellidos', 'dni']],
    'convenio' => ['type' => 'OBJECT', 'properties' => [
        'nombre' => ['type' => 'STRING', 'description' => 'Nombre del convenio y ámbito'],
        'vigencia' => ['type' => 'STRING'],
        'horas_anuales' => ['type' => 'NUMBER', 'nullable' => true, 'description' => 'Jornada máxima anual en horas'],
        'vacaciones_dias' => ['type' => 'NUMBER', 'nullable' => true],
        'vacaciones_tipo' => ['type' => 'STRING', 'enum' => ['laborables', 'naturales', 'no_consta']],
        'pausa_minutos' => ['type' => 'NUMBER', 'nullable' => true, 'description' => 'Descanso en jornada continuada'],
        'pausa_retribuida' => ['type' => 'BOOLEAN', 'nullable' => true, 'description' => 'Si la pausa cuenta como trabajo efectivo'],
        'horas_extra_max' => ['type' => 'NUMBER', 'nullable' => true],
        'descanso_entre_jornadas_h' => ['type' => 'NUMBER', 'nullable' => true],
        'otras_reglas' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Otras reglas de jornada, permisos o turnos, con su artículo'],
    ], 'required' => ['nombre', 'vacaciones_tipo', 'otras_reglas']],
    'nomina' => ['type' => 'OBJECT', 'properties' => [
        'nombre' => ['type' => 'STRING', 'description' => 'Nombre y apellidos del trabajador'],
        'dni' => ['type' => 'STRING', 'description' => 'DNI o NIE del trabajador (no el CIF de la empresa)'],
        'periodo' => ['type' => 'STRING', 'description' => 'Mes de la nómina en formato AAAA-MM'],
        'es_nomina' => ['type' => 'BOOLEAN', 'description' => 'true si la página es una nómina o recibo de salarios'],
    ], 'required' => ['nombre', 'dni', 'periodo', 'es_nomina']],
    'festivos' => ['type' => 'OBJECT', 'properties' => [
        'festivos' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
            'fecha' => ['type' => 'STRING', 'description' => 'AAAA-MM-DD'], 'nombre' => ['type' => 'STRING'],
            'ambito' => ['type' => 'STRING', 'enum' => ['nacional', 'autonomico', 'local']],
        ], 'required' => ['fecha', 'nombre', 'ambito']]],
    ], 'required' => ['festivos']],
];

const IA_INSTRUCCIONES = [
    'dni' => 'Lee este documento de identidad español (DNI o NIE). Devuelve solo los datos que veas con claridad; si un dato no se lee, déjalo vacío. No inventes nada.',
    'convenio' => 'Eres experto en derecho laboral español. Lee este convenio colectivo y extrae las reglas de jornada. Usa null si el convenio no lo regula. No apliques el Estatuto de los Trabajadores por tu cuenta: solo lo que diga el texto.',
    'nomina' => 'Esta página es parte de un PDF de nóminas españolas. Devuelve el nombre, el DNI/NIE del TRABAJADOR (no el CIF de la empresa) y el mes de liquidación. Si la página no es una nómina (portada, resumen, continuación sin datos), es_nomina = false. No inventes datos.',
    'festivos' => 'Lee este calendario laboral oficial y lista todos los días festivos que aparezcan, con su fecha y ámbito. No añadas festivos que no estén en el documento.',
];

function ia_activa(): bool
{
    return !empty(config()['gemini_clave']);
}

// Cuerpo de la petición a Gemini: instrucción + archivo (o texto) + salida JSON con esquema.
function ia_peticion(string $tarea, ?string $datos, ?string $mime, string $texto = ''): array
{
    $partes = [['text' => IA_INSTRUCCIONES[$tarea] ?? throw new DomainException('Tarea de IA no válida.')]];
    if ($datos !== null) $partes[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($datos)]];
    if ($texto !== '') $partes[] = ['text' => $texto];
    return ['contents' => [['role' => 'user', 'parts' => $partes]],
        'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json', 'responseSchema' => IA_ESQUEMAS[$tarea]]];
}

function ia_respuesta(array $r): array
{
    if (isset($r['error'])) throw new DomainException('Gemini: ' . ($r['error']['message'] ?? 'error'));
    $txt = $r['candidates'][0]['content']['parts'][0]['text'] ?? null;
    $d = $txt !== null ? json_decode($txt, true) : null;
    if (!is_array($d)) throw new DomainException('La IA no ha podido leer el documento. Prueba con una foto más nítida.');
    return $d;
}

// $f: entrada de $_FILES. PDF o imagen, máx. 15 MB. Devuelve los datos ya comprobados.
function ia_leer(string $tarea, ?array $f): array
{
    if (!ia_activa()) throw new DomainException('Falta la clave de Gemini en config-inda.php.');
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 15 * 1024 * 1024) throw new DomainException('Elige un PDF o una foto (máximo 15 MB).');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'], true)) throw new DomainException('Solo PDF o fotos (JPG, PNG, WEBP, HEIC).');
    $c = config();
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($c['gemini_modelo'] ?? 'gemini-flash-latest') . ':generateContent');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $c['gemini_clave']],
        CURLOPT_POSTFIELDS => json_encode(ia_peticion($tarea, file_get_contents($f['tmp_name']), $mime))]);
    $txt = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($txt === false) throw new DomainException("No se pudo conectar con Gemini ($err).");
    return ia_validar($tarea, ia_respuesta(json_decode($txt, true) ?: []));
}

// La IA puede equivocarse: se limpian y comprueban los datos antes de enseñarlos.
function ia_validar(string $tarea, array $d): array
{
    $fecha = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : '';
    if ($tarea === 'dni') {
        $dni = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string)($d['dni'] ?? '')));
        return ['nombre' => trim((string)($d['nombre'] ?? '')), 'apellidos' => trim((string)($d['apellidos'] ?? '')),
            'dni' => $dni, 'dni_valido' => dni_valido($dni), 'caducidad' => $fecha($d['caducidad'] ?? ''), 'fecha_nacimiento' => $fecha($d['fecha_nacimiento'] ?? '')];
    }
    if ($tarea === 'nomina') {
        $dni = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string)($d['dni'] ?? '')));
        $per = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($d['periodo'] ?? '')) ? $d['periodo'] : '';
        return ['nombre' => trim((string)($d['nombre'] ?? '')), 'dni' => dni_valido($dni) ? $dni : '', 'periodo' => $per, 'es_nomina' => !empty($d['es_nomina'])];
    }
    if ($tarea === 'festivos') {
        $out = [];
        foreach ($d['festivos'] ?? [] as $x) if ($f = $fecha($x['fecha'] ?? '')) $out[] = [$f, mb_substr(trim((string)($x['nombre'] ?? '')) ?: 'Festivo', 0, 100), $x['ambito'] ?? 'local'];
        return $out;
    }
    $num = fn($k, $min, $max) => is_numeric($d[$k] ?? null) && $d[$k] >= $min && $d[$k] <= $max ? $d[$k] + 0 : null;
    return ['nombre' => mb_substr(trim((string)($d['nombre'] ?? '')), 0, 200), 'vigencia' => mb_substr(trim((string)($d['vigencia'] ?? '')), 0, 100),
        'horas_anuales' => $num('horas_anuales', 1000, 2200), 'vacaciones_dias' => $num('vacaciones_dias', 1, 60),
        'vacaciones_tipo' => in_array($d['vacaciones_tipo'] ?? '', ['laborables', 'naturales'], true) ? $d['vacaciones_tipo'] : 'no_consta',
        'pausa_minutos' => $num('pausa_minutos', 0, 120), 'pausa_retribuida' => is_bool($d['pausa_retribuida'] ?? null) ? $d['pausa_retribuida'] : null,
        'horas_extra_max' => $num('horas_extra_max', 0, 200), 'descanso_entre_jornadas_h' => $num('descanso_entre_jornadas_h', 0, 48),
        'otras_reglas' => array_slice(array_map(fn($x) => mb_substr((string)$x, 0, 300), (array)($d['otras_reglas'] ?? [])), 0, 30)];
}

// Letra de control del DNI/NIE español.
function dni_valido(string $d): bool
{
    $n = strtr(substr($d, 0, 1), ['X' => '0', 'Y' => '1', 'Z' => '2']) . substr($d, 1); // NIE: solo la primera letra
    return (bool)preg_match('/^\d{8}[A-Z]$/', $n) && 'TRWAGMYFPDXBNJZSQVHLCKE'[(int)substr($n, 0, 8) % 23] === $n[8];
}
