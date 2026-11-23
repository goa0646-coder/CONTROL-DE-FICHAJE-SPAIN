<?php
// Alta masiva de personas desde Excel (.xlsx) o CSV, incluida la exportación de usuarios de Woffu.
// Primero se prepara y se enseña (nada se guarda); después se aplica en una sola transacción.

// Filas de la primera hoja como listas de texto. .xlsx sin librerías (ZIP + XML) o .csv con ; o ,.
function leer_hoja(string $ruta, string $ext): array
{
    if ($ext === 'csv' || $ext === 'txt') {
        $txt = str_replace("\xEF\xBB\xBF", '', (string)file_get_contents($ruta));
        if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
        $sep = substr_count(strtok($txt, "\n") ?: '', ';') >= substr_count(strtok($txt, "\n") ?: '', ',') ? ';' : ',';
        return array_map(fn($l) => str_getcsv($l, $sep), array_values(array_filter(preg_split('/\r?\n/', $txt), fn($l) => trim($l) !== '')));
    }
    if ($ext !== 'xlsx') throw new DomainException('Sube un Excel (.xlsx) o un CSV.');
    if (!class_exists('ZipArchive')) throw new DomainException('Este servidor no puede abrir .xlsx: guárdalo como CSV en Excel y súbelo.');
    $z = new ZipArchive();
    if ($z->open($ruta) !== true) throw new DomainException('El archivo no es un Excel válido.');
    $x = fn(string $n) => ($c = $z->getFromName($n)) === false ? null : simplexml_load_string($c, 'SimpleXMLElement', LIBXML_NONET);
    // Algunos programas (p. ej. la exportación de Woffu) escriben las etiquetas con prefijo (x:row): se lee por espacio de nombres.
    $m = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $ss = [];
    // Ojo: un SimpleXMLElement cuyas etiquetas llevan prefijo cuenta como «falso»; por eso se compara con null.
    if (($s = $x('xl/sharedStrings.xml')) !== null) foreach ($s->children($m)->si as $si) $ss[] = trim(implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]'))));
    // Primera hoja según el libro (no siempre es sheet1.xml).
    $hoja = 'xl/worksheets/sheet1.xml';
    if (($wb = $x('xl/workbook.xml')) !== null && ($rels = $x('xl/_rels/workbook.xml.rels')) !== null) {
        $rid = (string)$wb->children($m)->sheets->children($m)->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        foreach ($rels->Relationship as $r) if ((string)$r['Id'] === $rid) $hoja = 'xl/' . ltrim(preg_replace('#^/?xl/#', '', (string)$r['Target']), '/');
    }
    $sh = $x($hoja) ?? throw new DomainException('No encuentro la hoja de datos en el Excel.');
    $filas = [];
    foreach ($sh->children($m)->sheetData->children($m)->row as $row) {
        $f = [];
        foreach ($row->children($m)->c as $c) {
            $at = $c->attributes(); // con prefijo, $c['r'] no ve el atributo
            preg_match('/^([A-Z]+)/', (string)$at['r'], $mm);
            $col = 0;
            foreach (str_split($mm[1]) as $l) $col = $col * 26 + ord($l) - 64;
            $t = (string)$at['t'];
            $v = $t === 's' ? ($ss[(int)$c->children($m)->v] ?? '') : ($t === 'inlineStr' ? implode('', array_map('strval', $c->xpath('.//*[local-name()="t"]'))) : (string)$c->children($m)->v);
            $f[$col - 1] = trim($v);
        }
        if ($f) {
            $max = max(array_keys($f));
            $filas[] = array_replace(array_fill(0, $max + 1, ''), $f);
        }
    }
    $z->close();
    return $filas;
}

// Cabeceras reconocidas (en minúsculas y sin acentos) → campo de INDA.
const COLUMNAS = [
    'nombre' => 'nombre', 'name' => 'nombre', 'nombre completo' => 'completo', 'usuario' => 'completo', 'empleado' => 'completo', 'persona' => 'completo',
    'apellidos' => 'apellidos', 'apellido' => 'apellidos', 'e-mail' => 'email', 'email' => 'email', 'correo' => 'email', 'correo electronico' => 'email',
    'dni' => 'dni', 'nie' => 'dni', 'dni/nie' => 'dni', 'documento' => 'dni', 'nif' => 'dni',
    'responsable de primer nivel' => 'responsable', 'responsable' => 'responsable', 'manager' => 'responsable',
    'centro' => 'centro', 'centro de trabajo' => 'centro', 'cargo' => 'puesto', 'puesto' => 'puesto', 'categoria' => 'puesto',
    'departamento' => 'departamento', 'horario' => 'horario', 'turno' => 'horario', 'rol' => 'rol', 'perfil' => 'rol',
    'usuario suspendido' => 'suspendido', 'suspendido' => 'suspendido', 'activo' => 'activo', 'baja' => 'suspendido',
    'n de empleado' => 'num_empleado', 'numero de empleado' => 'num_empleado', 'num empleado' => 'num_empleado', 'codigo' => 'num_empleado', 'id empleado' => 'num_empleado',
];

function normalizar(string $s): string
{
    $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'º' => '', 'ª' => '', '.' => '']);
    return trim(preg_replace('#\s*/\s*#', '/', $s));
}

// "ANA FERNANDEZ PELETEIRO" → "Ana Fernandez Peleteiro" (de, del, la… en minúscula).
function nombre_propio(string $s): string
{
    $s = mb_convert_case(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s))), MB_CASE_TITLE);
    return preg_replace_callback('/(?<=\s)(De|Del|La|Las|Los|Y|E|Da|Do|Das|Dos)(?=\s)/u', fn($m) => mb_strtolower($m[1]), $s);
}

// Devuelve [filas como arrays con campos, columnas reconocidas, columnas ignoradas].
function filas_importables(array $hoja): array
{
    foreach ($hoja as $i => $f) {
        $mapa = [];
        foreach ($f as $j => $v) if (isset(COLUMNAS[normalizar($v)])) $mapa[$j] = COLUMNAS[normalizar($v)];
        if (count($mapa) >= 2 && in_array('email', $mapa, true)) {
            $ignoradas = array_values(array_filter(array_diff_key($f, $mapa), fn($v) => trim($v) !== ''));
            $out = [];
            foreach (array_slice($hoja, $i + 1) as $fila) {
                $d = [];
                foreach ($mapa as $j => $campo) $d[$campo] = trim($fila[$j] ?? '');
                if (array_filter($d)) $out[] = $d;
            }
            return [$out, array_values(array_unique($mapa)), $ignoradas];
        }
    }
    throw new DomainException('No encuentro la fila de cabeceras: hace falta al menos una columna «Email» y otra con el nombre.');
}

// Plan de importación sin tocar nada: cada fila con su estado y lo que habrá que crear.
function preparar_importacion(PDO $db, array $filas): array
{
    $vacio = fn($v) => in_array(normalizar((string)$v), ['', 'n/a', 'na', '-', 'ninguno'], true);
    $existentes = [];
    foreach ($db->query('SELECT * FROM usuarios') as $u) $existentes[mb_strtolower($u['email'])] = $u;
    $nombres = [];
    foreach ($existentes as $u) $nombres[normalizar(nombre($u))] = $u['email'];
    $centros = array_flip(array_map('normalizar', $db->query('SELECT id, nombre FROM centros')->fetchAll(PDO::FETCH_KEY_PAIR)));
    $deps = array_flip(array_map('normalizar', $db->query('SELECT id, nombre FROM departamentos')->fetchAll(PDO::FETCH_KEY_PAIR)));
    $pts = array_flip(array_map('normalizar', $db->query('SELECT id, nombre FROM puestos')->fetchAll(PDO::FETCH_KEY_PAIR)));
    $hors = array_flip(array_map('normalizar', $db->query('SELECT id, nombre FROM horarios')->fetchAll(PDO::FETCH_KEY_PAIR)));
    $plan = ['filas' => [], 'crear' => ['centros' => [], 'departamentos' => [], 'puestos' => []], 'horarios_desconocidos' => [], 'responsables_desconocidos' => []];
    $vistos = [];
    foreach ($filas as $n => $d) {
        $err = [];
        $email = mb_strtolower(trim($d['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err[] = 'email no válido';
        if (isset($vistos[$email])) $err[] = 'email repetido en el archivo';
        $vistos[$email] = true;
        if (isset($d['nombre']) && !$vacio($d['nombre'])) {
            $nombre = nombre_propio($d['nombre']);
            $apellidos = nombre_propio($d['apellidos'] ?? '');
        } else {
            $partes = explode(' ', nombre_propio($d['completo'] ?? ''), 2);
            [$nombre, $apellidos] = [$partes[0], $partes[1] ?? ''];
        }
        if ($nombre === '') $err[] = 'sin nombre';
        $dni = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $d['dni'] ?? ''));
        if ($dni !== '' && !dni_valido($dni)) $err[] = 'la letra del DNI no cuadra';
        $rol = match (true) {
            str_contains(normalizar($d['rol'] ?? ''), 'admin') => 'admin',
            (bool)preg_match('/respons|manager|supervis|coordin|encarg/', normalizar($d['rol'] ?? '')) => 'responsable',
            default => 'empleado',
        };
        $suspendido = isset($d['suspendido']) ? in_array(normalizar($d['suspendido']), ['si', 'yes', 'true', '1', 'x'], true)
            : (isset($d['activo']) && in_array(normalizar($d['activo']), ['no', 'false', '0'], true));
        $f = ['fila' => $n + 1, 'email' => $email, 'nombre' => $nombre, 'apellidos' => $apellidos, 'dni' => $dni, 'rol' => $rol, 'activo' => !$suspendido,
            'num_empleado' => $vacio($d['num_empleado'] ?? '') ? null : mb_substr($d['num_empleado'], 0, 20)];
        foreach (['centro' => 'centros', 'departamento' => 'departamentos', 'puesto' => 'puestos'] as $campo => $grupo) {
            $v = $vacio($d[$campo] ?? '') ? null : trim(preg_replace('/\s+/u', ' ', $d[$campo]));
            $f[$campo] = $v;
            $existe = $v !== null && isset(['centros' => $centros, 'departamentos' => $deps, 'puestos' => $pts][$grupo][normalizar($v)]);
            if ($v !== null && !$existe) $plan['crear'][$grupo][normalizar($v)] = $v;
        }
        $h = $vacio($d['horario'] ?? '') ? null : trim($d['horario']);
        $f['horario_id'] = $h !== null ? ($hors[normalizar($h)] ?? null) : null;
        if ($h !== null && !$f['horario_id']) $plan['horarios_desconocidos'][normalizar($h)] = $h;
        $f['responsable'] = $vacio($d['responsable'] ?? '') ? null : trim($d['responsable']);
        $f['estado'] = $err ? 'error' : (isset($existentes[$email]) ? 'actualizar' : 'nuevo');
        $f['errores'] = $err;
        $plan['filas'][] = $f;
        if (!$err) $nombres[normalizar(trim("$nombre $apellidos"))] = $email;
    }
    // Responsables por nombre completo (de INDA o del propio archivo).
    foreach ($plan['filas'] as &$f) {
        if (!$f['responsable']) continue;
        $f['responsable_email'] = $nombres[normalizar($f['responsable'])] ?? null;
        if ($f['responsable_email'] === $f['email']) $f['responsable_email'] = null; // no puede ser su propio responsable
        elseif (!$f['responsable_email']) $plan['responsables_desconocidos'][normalizar($f['responsable'])] = $f['responsable'];
    }
    unset($f);
    $plan['crear'] = array_map('array_values', $plan['crear']);
    $plan['horarios_desconocidos'] = array_values($plan['horarios_desconocidos']);
    $plan['responsables_desconocidos'] = array_values($plan['responsables_desconocidos']);
    return $plan;
}

// Aplica el plan. Devuelve ['nuevos' => [uid…], 'actualizados' => n, 'ascendidos' => [nombre…]].
function aplicar_importacion(PDO $db, array $plan, bool $actualizar): array
{
    $db->beginTransaction();
    try {
        $ids = [];
        foreach (['centros', 'departamentos', 'puestos'] as $t) {
            foreach ($plan['crear'][$t] as $n) consulta($db, "INSERT INTO $t (nombre) VALUES (?)", [mb_substr($n, 0, 80)]);
            $ids[$t] = array_flip(array_map('normalizar', $db->query("SELECT id, nombre FROM $t")->fetchAll(PDO::FETCH_KEY_PAIR)));
        }
        $res = ['nuevos' => [], 'actualizados' => 0, 'ascendidos' => []];
        $por_email = [];
        foreach ($plan['filas'] as $f) {
            if ($f['estado'] === 'error') continue;
            $c = ['nombre' => $f['nombre'], 'apellidos' => $f['apellidos'],
                'centro_id' => $f['centro'] ? $ids['centros'][normalizar($f['centro'])] : null,
                'departamento_id' => $f['departamento'] ? $ids['departamentos'][normalizar($f['departamento'])] : null,
                'horario_id' => $f['horario_id'], 'activo' => (int)$f['activo']];
            if ($f['dni'] !== '') $c['dni'] = $f['dni'];
            if ($f['num_empleado']) $c['num_empleado'] = $f['num_empleado'];
            $antes = consulta($db, 'SELECT * FROM usuarios WHERE email = ?', [$f['email']])->fetch();
            if ($antes) {
                if (!$actualizar) {
                    $por_email[$f['email']] = (int)$antes['id'];
                    continue;
                }
                // Nunca se quita el rol de admin ni se da de baja a un admin desde una importación.
                if ($antes['rol'] !== 'admin') $c['rol'] = $f['rol'];
                if ($antes['rol'] === 'admin') $c['activo'] = 1;
                if (!$c['activo'] && $antes['activo']) $c['baja_utc'] = ahora();
                consulta($db, 'UPDATE usuarios SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($c))) . ' WHERE id = ?', [...array_values($c), $antes['id']]);
                $uid = (int)$antes['id'];
                $res['actualizados']++;
            } else {
                $c += ['email' => $f['email'], 'rol' => $f['rol'], 'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'alta_utc' => ahora()];
                if (!$c['activo']) $c['baja_utc'] = ahora();
                consulta($db, 'INSERT INTO usuarios (' . implode(', ', array_keys($c)) . ') VALUES (' . rtrim(str_repeat('?, ', count($c)), ', ') . ')', array_values($c));
                $uid = (int)$db->lastInsertId();
                if ($f['activo']) $res['nuevos'][] = $uid;
            }
            $por_email[$f['email']] = $uid;
            if ($f['puesto']) consulta($db, 'INSERT IGNORE INTO usuarios_puestos VALUES (?, ?)', [$uid, $ids['puestos'][normalizar($f['puesto'])]]);
        }
        // Responsables, cuando ya existen todos. Quien tiene equipo a su cargo pasa a «responsable» si era empleado.
        foreach ($plan['filas'] as $f) {
            if ($f['estado'] === 'error' || empty($f['responsable_email']) || !isset($por_email[$f['email']])) continue;
            $jefe = consulta($db, 'SELECT * FROM usuarios WHERE email = ?', [$f['responsable_email']])->fetch();
            if (!$jefe) continue;
            consulta($db, 'UPDATE usuarios SET responsable_id = ? WHERE id = ?', [$jefe['id'], $por_email[$f['email']]]);
            if ($jefe['rol'] === 'empleado') {
                consulta($db, "UPDATE usuarios SET rol = 'responsable' WHERE id = ?", [$jefe['id']]);
                $res['ascendidos'][nombre($jefe)] = true;
            }
        }
        $res['ascendidos'] = array_keys($res['ascendidos']);
        $db->commit();
        return $res;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// Email de bienvenida con enlace para elegir contraseña (válido 7 días).
function enviar_bienvenida(PDO $db, array $u): bool
{
    $t = bin2hex(random_bytes(32));
    consulta($db, 'UPDATE usuarios SET reset_hash = ?, reset_expira_utc = ? WHERE id = ?', [hash('sha256', $t), gmdate('Y-m-d H:i:s', time() + 7 * 86400), $u['id']]);
    $url = rtrim(config()['url'] ?? '', '/');
    $empresa = $db->query('SELECT nombre FROM empresa WHERE id = 1')->fetchColumn() ?: 'tu empresa';
    return enviar_correo($u['email'], "Bienvenida a INDA · $empresa", "Hola, {$u['nombre']}:\n\n$empresa usa INDA para el registro de jornada.\n"
        . "Elige tu contraseña aquí (el enlace vale 7 días):\n$url/?p=recuperar&t=$t\n\n"
        . "Después entra en $url desde tu móvil, marca «Mantener la sesión abierta» y pulsa «Instalar INDA» para tenerla como una app.");
}
