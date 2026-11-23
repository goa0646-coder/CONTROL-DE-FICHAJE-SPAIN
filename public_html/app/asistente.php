<?php
// Primera instalación sin config-inda.php: pide los datos de la BD, prueba la conexión y escribe el archivo
// encima de public_html. Solo acepta un servidor MySQL local, así nadie de fuera puede apuntar INDA a su BD.

function requisitos(): array
{
    $ec = function_exists('openssl_pkey_new') && @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $https = !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || PHP_SAPI === 'cli';
    return [
        'PHP 8.1 o superior' => [version_compare(PHP_VERSION, '8.1', '>='), PHP_VERSION, true],
        'Extensión pdo_mysql' => [extension_loaded('pdo_mysql'), '', true],
        'Extensión mbstring' => [extension_loaded('mbstring'), '', true],
        'Extensión openssl (curvas EC)' => [(bool)$ec, 'sin ella no hay avisos push', false],
        'Extensión curl' => [extension_loaded('curl'), 'sin ella no hay push ni entrar con Google/Microsoft', false],
        'HTTPS' => [$https, 'actívalo en hPanel → Seguridad → SSL', false],
    ];
}

function generar_config(array $d): string
{
    return "<?php\n// Creado por el asistente de INDA el " . gmdate('Y-m-d H:i') . " UTC. No lo subas al repositorio.\nreturn " . var_export($d, true) . ";\n";
}

function asistente(string $destino): never
{
    $error = '';
    $manual = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $bd = ['host' => trim($_POST['host'] ?? 'localhost'), 'nombre' => trim($_POST['nombre'] ?? ''), 'usuario' => trim($_POST['usuario'] ?? ''), 'clave' => $_POST['clave'] ?? ''];
        $url = rtrim(trim($_POST['url'] ?? ''), '/');
        if (!in_array($bd['host'], ['localhost', '127.0.0.1'], true)) $error = 'El servidor de la base de datos debe ser localhost.';
        elseif (!preg_match('#^https?://[^/\s]+$#', $url)) $error = 'La dirección debe ser del tipo https://inda.miempresa.com';
        elseif (($_POST['correo'] ?? '') !== '' && !filter_var($_POST['correo'], FILTER_VALIDATE_EMAIL)) $error = 'El email de envío no es válido.';
        else {
            try {
                conectar($bd)->query('SELECT 1');
                $clave = bin2hex(random_bytes(16));
                $cfg = ['bd' => $bd, 'url' => $url, 'correo_desde' => trim($_POST['correo'] ?? ''), 'clave_instalacion' => $clave];
                if (($g = trim($_POST['gemini'] ?? '')) !== '') $cfg['gemini_clave'] = $g;
                $php = generar_config($cfg);
                if (@file_put_contents($destino, $php, LOCK_EX) !== false) {
                    @chmod($destino, 0600);
                    header('Location: ?' . http_build_query(['p' => 'instalar', 'clave' => $clave]));
                    exit;
                }
                $manual = $php;
            } catch (PDOException $e) {
                $error = 'No se puede conectar con esos datos: ' . $e->getMessage();
            }
        }
    }
    $req = requisitos();
    $host = $_SERVER['HTTP_HOST'] ?? 'inda.miempresa.com';
    cabecera('Instalar INDA');
    ?>
<table>
  <tr><th>Requisito</th><th></th></tr>
  <?php foreach ($req as $n => [$ok, $det, $oblig]): ?>
  <tr><td><?= e($n) ?></td><td class="<?= $ok ? '' : 'error' ?>"><?= $ok ? '✔' : ($oblig ? '✘ obligatorio' : '⚠ ' . e($det)) ?> <?= $ok && $oblig && $det ? e($det) : "" ?></td></tr>
  <?php endforeach ?>
</table>
<?php if ($error): ?><p class="aviso error"><?= e($error) ?></p><?php endif ?>
<?php if ($manual): ?>
<div class="tarjeta">
  <p class="aviso error">La conexión funciona, pero no puedo escribir el archivo. Créalo a mano:</p>
  <p>En el Administrador de archivos, en la carpeta <strong>por encima</strong> de public_html, crea <code>config-inda.php</code> con este contenido y recarga esta página:</p>
  <textarea rows="16" readonly><?= e($manual) ?></textarea>
</div>
<?php endif ?>
<form method="post" class="tarjeta">
  <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
  <h2>1. Base de datos</h2>
  <p class="nota">Créala antes en hPanel → Bases de datos → Bases de datos MySQL. Copia aquí los tres datos que te da.</p>
  <label>Nombre de la base de datos <input name="nombre" required placeholder="u123456789_inda" value="<?= e($_POST['nombre'] ?? '') ?>"></label>
  <label>Usuario <input name="usuario" required placeholder="u123456789_inda" value="<?= e($_POST['usuario'] ?? '') ?>"></label>
  <label>Contraseña <input type="password" name="clave" required></label>
  <input type="hidden" name="host" value="localhost">
  <h2>2. Dirección y correo</h2>
  <label>Dirección de INDA <input name="url" required value="<?= e($_POST['url'] ?? "https://$host") ?>"></label>
  <label>Email de envío (de tu dominio) <input type="email" name="correo" placeholder="no-responder@miempresa.com" value="<?= e($_POST['correo'] ?? '') ?>"></label>
  <h2>3. Lectura con IA (opcional)</h2>
  <label>Clave de Google Gemini <input type="password" name="gemini" autocomplete="off" placeholder="Déjalo vacío si no la usas"></label>
  <p class="nota">Sirve para leer DNI, convenios y calendarios. Para DNI usa una cuenta de Google con facturación activada.</p>
  <button>Probar y guardar</button>
</form>
<?php
    pie();
    exit;
}
