<?php
$yo = requiere('admin');
$gps = ['no' => 'Sin GPS', 'opcional' => 'Opcional', 'obligatorio' => 'Obligatorio'];
$fuera = ['aceptar' => 'Aceptar y marcar «fuera»', 'rechazar' => 'No dejar fichar'];

if (es_post()) {
    $que = $_POST['que'] ?? '';
    if ($que === 'leer_convenio') {
        try {
            $_SESSION['convenio_leido'] = ia_leer('convenio', $_FILES['archivo'] ?? null);
            auditar(db(), (int)$yo['id'], 'ia_convenio', $_SESSION['convenio_leido']['nombre']);
        } catch (DomainException $e) {
            aviso($e->getMessage(), 'error');
        }
        redirigir('admin');
    } elseif ($que === 'aplicar_convenio' && ($cv = $_SESSION['convenio_leido'] ?? null)) {
        if ($cv['horas_anuales']) consulta(db(), 'UPDATE empresa SET horas_anuales = ? WHERE id = 1', [(int)round($cv['horas_anuales'])]);
        if ($cv['vacaciones_dias'] && $cv['vacaciones_tipo'] !== 'no_consta') consulta(db(), "UPDATE tipos_ausencia SET dias_anuales = ?, computo = ? WHERE nombre = 'Vacaciones'", [$cv['vacaciones_dias'], $cv['vacaciones_tipo']]);
        guardar_reglas(db(), reglas_de_convenio($cv) + reglas(db()));
        // Se guarda entero para las reglas de convenio (descansos, horas extra…).
        consulta(db(), 'REPLACE INTO ajustes VALUES (?, ?)', ['convenio', json_encode($cv, JSON_UNESCAPED_UNICODE)]);
        unset($_SESSION['convenio_leido']);
        auditar(db(), (int)$yo['id'], 'convenio_aplicado', $cv['nombre']);
    } elseif ($que === 'mantenimiento') {
        $m = in_array($_POST['modo'] ?? '', ['fichar', 'todo'], true) ? $_POST['modo'] : 'no';
        consulta(db(), 'REPLACE INTO ajustes VALUES (?, ?)', ['mantenimiento', $m]);
        auditar(db(), (int)$yo['id'], 'mantenimiento', $m);
        aviso(['no' => 'INDA vuelve a estar abierto para toda la plantilla.', 'fichar' => 'INDA cerrado a la plantilla: solo pueden fichar.', 'todo' => 'INDA cerrado del todo a la plantilla (tampoco pueden fichar).'][$m]);
        redirigir('admin');
    } elseif ($que === 'vision') {
        $n = (int)($_POST['vision_dias'] ?? 0);
        consulta(db(), 'REPLACE INTO ajustes VALUES (?, ?)', ['vision_dias', (string)(isset(VISION_DIAS[$n]) ? $n : 0)]);
        auditar(db(), (int)$yo['id'], 'vision_dias', (string)$n);
        aviso($n ? "Los trabajadores verán sus turnos hasta $n días en adelante." : 'Los trabajadores ven todos sus turnos publicados.');
        redirigir('admin');
    } elseif ($que === 'avisos_trab') {
        consulta(db(), 'REPLACE INTO ajustes VALUES (?, ?)', ['avisos_trabajadores', isset($_POST['avisar']) ? 'si' : 'no']);
        auditar(db(), (int)$yo['id'], 'avisos_trabajadores', isset($_POST['avisar']) ? 'si' : 'no');
        aviso(isset($_POST['avisar']) ? 'Los trabajadores recibirán los avisos.' : 'Avisos a los trabajadores en pausa: no se envían correos.');
        redirigir('admin');
    } elseif ($que === 'seguridad') {
        try {
            $c = isset($_POST['cifrado']);
            if ($c !== cifrado_activo(db())) {
                $n = cambiar_cifrado(db(), $c);
                auditar(db(), (int)$yo['id'], 'cifrado_datos', ($c ? 'activado' : 'desactivado') . " fichas=$n");
            }
            if (isset($_POST['dos_pasos']) !== (ajuste(db(), 'dos_pasos') === 'si')) {
                cambiar_dos_pasos(db(), isset($_POST['dos_pasos']));
                auditar(db(), (int)$yo['id'], 'dos_pasos', isset($_POST['dos_pasos']) ? 'activado' : 'desactivado');
            }
            aviso('Seguridad guardada.');
        } catch (DomainException $e) {
            aviso($e->getMessage(), 'error');
        }
        redirigir('admin');
    } elseif ($que === 'pausa_si' || $que === 'pausa_no') {
        $si = $que === 'pausa_si';
        $n = consulta(db(), "UPDATE usuarios SET pausa_auto_min = ? WHERE activo = 1 AND rol <> 'inspeccion'", [$si ? 15 : 0])->rowCount();
        auditar(db(), (int)$yo['id'], 'pausa_auto_todos', $si ? 'si' : 'no');
        aviso($si ? "Pausa automática de 15 min puesta a $n personas." : "Pausa automática quitada a $n personas.");
        redirigir('admin');
    } elseif ($que === 'anunciar') {
        $t = trim($_POST['texto'] ?? '');
        $t === '' ? aviso('Escribe el mensaje.', 'error') : aviso('Aviso enviado a ' . anunciar_cambios(db(), $yo, mb_substr($t, 0, 300)) . ' personas.');
        redirigir('admin');
    } elseif ($que === 'plantillas') {
        $t = [];
        foreach (PLANTILLAS_AVISO as $k => $_) $t[$k] = mb_substr(trim(str_replace("\r", '', $_POST["plantilla_$k"] ?? '')), 0, 1500);
        consulta(db(), 'REPLACE INTO ajustes VALUES (?, ?)', ['plantillas_aviso', json_encode($t, JSON_UNESCAPED_UNICODE)]);
        consulta(db(), 'REPLACE INTO ajustes VALUES (?, ?)', ['cierre_auto_min', (string)max(0, min(240, (int)($_POST['cierre_auto_min'] ?? 5)))]);
        auditar(db(), (int)$yo['id'], 'plantillas_aviso_guardadas');
        aviso('Mensajes de aviso guardados.');
        redirigir('admin');
    } elseif ($que === 'correo' || $que === 'correo_prueba') {
        $ant = json_decode(ajuste(db(), 'correo') ?? '[]', true) ?: [];
        $desde = trim($_POST['desde'] ?? '');
        $admin = trim($_POST['admin'] ?? '');
        if ($desde !== '' && !filter_var($desde, FILTER_VALIDATE_EMAIL)) {
            aviso('El email de envío no es válido.', 'error');
            redirigir('admin');
        }
        // La contraseña no se vuelve a mostrar: vacío = mantener la guardada.
        $clave = ($_POST['smtp_clave'] ?? '') !== '' ? $_POST['smtp_clave'] : ($ant['smtp']['clave'] ?? '');
        consulta(db(), 'REPLACE INTO ajustes VALUES (?, ?)', ['correo', json_encode(['desde' => $desde, 'admin' => $admin, 'smtp' => [
            'host' => trim($_POST['smtp_host'] ?? ''), 'puerto' => (int)($_POST['smtp_puerto'] ?? 465), 'usuario' => trim($_POST['smtp_usuario'] ?? '') ?: $desde, 'clave' => $clave]])]);
        consulta(db(), 'REPLACE INTO ajustes VALUES (?, ?)', ['recordatorio_horas', (string)max(0, min(720, (int)($_POST['recordatorio_horas'] ?? 24))) ?: 'no']);
        auditar(db(), (int)$yo['id'], 'correo_configurado');
        if ($que === 'correo_prueba') {
            $c = correo_cfg();
            $para = preg_split('/[\s,;]+/', $c['admin'], -1, PREG_SPLIT_NO_EMPTY)[0] ?? $yo['email'];
            try {
                if ($c['desde'] === '') throw new RuntimeException('Falta el email de envío.');
                if (!empty($c['smtp']['host'])) smtp_enviar($c['smtp'], $c['desde'], $para, 'INDA: correo de prueba', "Si lees esto, INDA ya puede enviar avisos por correo.\n\n" . rtrim(config()['url'] ?? '', '/'));
                elseif (!enviar_correo($para, 'INDA: correo de prueba', 'Si lees esto, INDA ya puede enviar avisos por correo.')) throw new RuntimeException('El servidor no ha aceptado el correo.');
                aviso("Correo de prueba enviado a $para. Mira también en la carpeta de spam.");
            } catch (RuntimeException $e) {
                aviso('No se pudo enviar: ' . $e->getMessage(), 'error');
            }
            redirigir('admin');
        }
    } elseif ($que === 'logo' || $que === 'quitar_logo') {
        if ($que === 'quitar_logo') consulta(db(), "DELETE FROM ajustes WHERE clave = 'logo'");
        else {
            $f = $_FILES['logo'] ?? null;
            $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
            // Sin SVG: podría llevar código. Solo imágenes normales y pequeñas.
            if (!$f || !in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) || $f['size'] > 2 * 1024 * 1024 || !@getimagesize($f['tmp_name'])) {
                aviso('Sube el logo en PNG, JPG o WEBP (máximo 2 MB).', 'error');
                redirigir('admin');
            }
            $nombre = guardar_archivo($f);
            consulta(db(), 'REPLACE INTO ajustes VALUES (?, ?)', ['logo', $nombre]);
            // Los colores de la aplicación salen del logo (se pueden cambiar a mano abajo).
            if ($c = colores_logo(carpeta_archivos() . "/$nombre")) consulta(db(), 'REPLACE INTO ajustes VALUES (?, ?)', ['marca', json_encode($c)]);
        }
        auditar(db(), (int)$yo['id'], 'logo_empresa', $que);
    } elseif ($que === 'marca') {
        $c = fn($k) => preg_match('/^#[0-9a-f]{6}$/i', $_POST[$k] ?? '') ? strtolower($_POST[$k]) : MARCA_POR_DEFECTO[$k];
        if (isset($_POST['del_logo']) && ($l = ajuste(db(), 'logo')) && ($d = colores_logo(carpeta_archivos() . '/' . basename($l)))) $m = $d;
        else $m = ['primario' => $c('primario'), 'secundario' => $c('secundario')];
        consulta(db(), 'REPLACE INTO ajustes VALUES (?, ?)', ['marca', json_encode($m)]);
        auditar(db(), (int)$yo['id'], 'colores_marca', json_encode($m));
    } elseif ($que === 'plantilla_galicia') {
        aplicar_plantilla(db(), PLANTILLA_GALICIA_RESIDENCIAS);
        auditar(db(), (int)$yo['id'], 'plantilla_convenio', 'VI Convenio residencias Galicia');
    } elseif ($que === 'reglas') {
        guardar_reglas(db(), $_POST);
        auditar(db(), (int)$yo['id'], 'reglas_editadas');
    } elseif ($que === 'empresa') {
        $horas = ($_POST['horas_anuales'] ?? '') === '' ? null : max(0, (int)$_POST['horas_anuales']);
        db()->prepare('INSERT INTO empresa (id, nombre, cif, horas_anuales) VALUES (1, ?, ?, ?) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), cif = VALUES(cif), horas_anuales = VALUES(horas_anuales)')
            ->execute([trim($_POST['nombre']), trim($_POST['cif']), $horas]);
        auditar(db(), (int)$yo['id'], 'empresa_editada');
    } elseif ($que === 'departamento') {
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre === '') {
            aviso('Pon un nombre.', 'error');
            redirigir('admin');
        }
        if ($id = (int)($_POST['id'] ?? 0)) consulta(db(), 'UPDATE departamentos SET nombre = ?, activo = ? WHERE id = ?', [$nombre, isset($_POST['activo']) ? 1 : 0, $id]);
        else consulta(db(), 'INSERT INTO departamentos (nombre) VALUES (?)', [$nombre]);
        auditar(db(), (int)$yo['id'], 'departamento_guardado', $nombre);
    } elseif ($que === 'permisos') {
        db()->exec('DELETE FROM permisos_rol');
        foreach (['responsable', 'empleado', 'inspeccion'] as $rol) {
            // Inspección es solo lectura: como mucho, informes.
            foreach (array_keys(PERMISOS) as $p) if (isset($_POST["$rol:$p"]) && ($rol !== 'inspeccion' || $p === 'informes')) consulta(db(), 'INSERT INTO permisos_rol VALUES (?, ?)', [$rol, $p]);
        }
        auditar(db(), (int)$yo['id'], 'permisos_editados');
    } else {
        $num = fn($k, $min, $max) => ($_POST[$k] ?? '') === '' ? null : filter_var($_POST[$k], FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        $d = [trim($_POST['nombre'] ?? ''), trim($_POST['direccion'] ?? ''), $num('lat', -90, 90), $num('lng', -180, 180),
            max(10, (int)($_POST['radio_m'] ?? 150)), isset($gps[$_POST['gps_modo'] ?? '']) ? $_POST['gps_modo'] : 'no',
            isset($fuera[$_POST['fuera_zona'] ?? '']) ? $_POST['fuera_zona'] : 'aceptar', mb_substr(trim($_POST['ips'] ?? ''), 0, 500), isset($_POST['activo']) ? 1 : 0];
        if ($d[0] === '' || $d[2] === false || $d[3] === false) {
            aviso('Revisa el nombre y las coordenadas.', 'error');
            redirigir('admin');
        }
        if ($id = (int)($_POST['id'] ?? 0)) {
            db()->prepare('UPDATE centros SET nombre=?, direccion=?, lat=?, lng=?, radio_m=?, gps_modo=?, fuera_zona=?, ips=?, activo=? WHERE id=?')->execute([...$d, $id]);
        } else {
            db()->prepare('INSERT INTO centros (nombre, direccion, lat, lng, radio_m, gps_modo, fuera_zona, ips, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($d);
        }
        auditar(db(), (int)$yo['id'], 'centro_guardado', $d[0]);
    }
    aviso('Guardado.');
    redirigir('admin');
}

$empresa = db()->query('SELECT * FROM empresa')->fetch() ?: ['nombre' => '', 'cif' => '', 'horas_anuales' => null];
$centros = db()->query('SELECT * FROM centros ORDER BY nombre')->fetchAll();
$deps = db()->query('SELECT * FROM departamentos ORDER BY activo DESC, nombre')->fetchAll();
$permisos = db()->query('SELECT CONCAT(rol, ":", permiso) FROM permisos_rol')->fetchAll(PDO::FETCH_COLUMN);
$c = isset($_GET['c']) ? centro(db(), (int)$_GET['c']) : null;
$c ??= ['id' => '', 'nombre' => '', 'direccion' => '', 'lat' => '', 'lng' => '', 'radio_m' => 150, 'gps_modo' => 'no', 'fuera_zona' => 'aceptar', 'ips' => '', 'activo' => 1];
cabecera('Empresa y centros', $yo);
?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Empresa</h2>
  <label>Nombre <input name="nombre" value="<?= e($empresa['nombre']) ?>" required></label>
  <label>CIF <input name="cif" value="<?= e($empresa['cif']) ?>"></label>
  <label>Horas anuales del convenio <input type="number" name="horas_anuales" min="0" value="<?= e($empresa['horas_anuales']) ?>" placeholder="p. ej. 1780"></label>
  <button name="que" value="empresa">Guardar</button>
</form>

<form method="post" enctype="multipart/form-data" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Logo</h2>
  <?php if ($l = logo_empresa()): ?><p><img src="<?= e($l) ?>" alt="Logo actual" style="max-height:90px;max-width:260px"></p><?php endif ?>
  <p class="nota">Sale en el menú, en la pantalla de entrada, en la tablet del quiosco y en los informes impresos. PNG con fondo transparente queda mejor.</p>
  <label>Imagen <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required></label>
  <button name="que" value="logo">Subir logo</button>
  <?php if ($l): ?><button name="que" value="quitar_logo" class="secundario" formnovalidate>Quitar</button><?php endif ?>
</form>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Colores de la empresa</h2>
  <p class="nota">Tiñen botones, menú y calendario. Al subir el logo se sacan solos de él; aquí puedes ajustarlos.</p>
  <div class="filtros">
    <label>Principal <input type="color" name="primario" value="<?= e(marca()['primario']) ?>"></label>
    <label>Acento <input type="color" name="secundario" value="<?= e(marca()['secundario']) ?>"></label>
  </div>
  <button name="que" value="marca">Guardar colores</button>
  <?php if (logo_empresa()): ?><button name="que" value="marca" class="secundario" onclick="this.form.insertAdjacentHTML('beforeend','<input type=hidden name=del_logo value=1>')">Sacarlos otra vez del logo</button><?php endif ?>
</form>

<?php $cv = $_SESSION['convenio_leido'] ?? null; $guardado = json_decode(ajuste(db(), 'convenio') ?? 'null', true); ?>
<?php if (ia_activa() || $guardado): ?>
<div class="tarjeta">
  <h2>Convenio colectivo</h2>
  <?php if ($guardado && !$cv): ?><p>Aplicado: <strong><?= e($guardado['nombre']) ?></strong> <?= e($guardado['vigencia']) ?></p><?php endif ?>
  <?php if ($cv): ?>
  <p class="aviso ok">Leído por la IA: <strong><?= e($cv['nombre']) ?></strong> <?= e($cv['vigencia']) ?>. Revísalo con el texto oficial antes de aplicarlo.</p>
  <table>
    <tr><td>Horas anuales</td><td><?= e($cv['horas_anuales'] ?? 'No lo regula') ?></td></tr>
    <tr><td>Vacaciones</td><td><?= $cv['vacaciones_dias'] ? e($cv['vacaciones_dias']) . ' días ' . e($cv['vacaciones_tipo']) : 'No lo regula' ?></td></tr>
    <tr><td>Pausa en jornada continuada</td><td><?= $cv['pausa_minutos'] !== null ? e($cv['pausa_minutos']) . ' min' . ($cv['pausa_retribuida'] === null ? '' : ($cv['pausa_retribuida'] ? ' (retribuida)' : ' (no retribuida)')) : 'No lo regula' ?></td></tr>
    <tr><td>Máximo horas extra/año</td><td><?= e($cv['horas_extra_max'] ?? 'No lo regula') ?></td></tr>
    <tr><td>Descanso entre jornadas</td><td><?= $cv['descanso_entre_jornadas_h'] !== null ? e($cv['descanso_entre_jornadas_h']) . ' h' : 'No lo regula' ?></td></tr>
    <?php foreach ($cv['otras_reglas'] as $r): ?><tr><td colspan="2"><?= e($r) ?></td></tr><?php endforeach ?>
  </table>
  <form method="post"><?= csrf_campo() ?><button name="que" value="aplicar_convenio">Aplicar al sistema</button></form>
  <p class="nota">Se aplican las horas anuales, las vacaciones (días y si son laborables o naturales), el descanso entre jornadas, la pausa y el máximo de horas extra. Lo demás queda como consulta.</p>
  <?php endif ?>
  <?php if (ia_activa()): ?>
  <form method="post" enctype="multipart/form-data" class="filtros">
    <?= csrf_campo() ?>
    <label>Leer el convenio (PDF del BOE/BOP) <input type="file" name="archivo" accept="application/pdf,image/*" required></label>
    <button name="que" value="leer_convenio">Leer con IA</button>
  </form>
  <p class="nota">Puede tardar hasta un minuto. El convenio es un texto público; no se envían datos de la plantilla.</p>
  <?php endif ?>
</div>
<?php endif ?>

<?php $cc = json_decode(ajuste(db(), 'correo') ?? '[]', true) ?: []; $cf = correo_cfg(); ?>
<form method="post" class="tarjeta" id="correo">
  <?= csrf_campo() ?>
  <h2>Correo</h2>
  <p class="nota">INDA envía los avisos (no has fichado, solicitudes, turnos ofrecidos, códigos) desde un buzón de vuestro dominio. Créalo en hPanel → Emails (por ejemplo <em>no-responder@vuestrodominio.es</em>) y escribe aquí sus datos.</p>
  <label>Email de envío <input type="email" name="desde" value="<?= e($cc['desde'] ?? $cf['desde']) ?>" placeholder="no-responder@vuestrodominio.es"></label>
  <div class="filtros">
    <label>Servidor SMTP <input name="smtp_host" value="<?= e($cc['smtp']['host'] ?? 'smtp.hostinger.com') ?>"></label>
    <label>Puerto <select name="smtp_puerto"><?= opciones([465 => '465 (SSL, recomendado)', 587 => '587 (STARTTLS)'], $cc['smtp']['puerto'] ?? 465) ?></select></label>
  </div>
  <label>Usuario (normalmente, el mismo email) <input name="smtp_usuario" value="<?= e($cc['smtp']['usuario'] ?? '') ?>" autocomplete="off"></label>
  <label>Contraseña del buzón <input type="password" name="smtp_clave" autocomplete="new-password" placeholder="<?= !empty($cc['smtp']['clave']) ? 'Guardada · déjalo vacío para no cambiarla' : '' ?>"></label>
  <label>Correo de administración (recibe las incidencias de fichaje y las solicitudes; varios separados por comas) <input name="admin" value="<?= e($cc['admin'] ?? '') ?>" placeholder="rrhh@vuestrodominio.es"></label>
  <button name="que" value="correo">Guardar</button>
  <label>Recordatorio de solicitudes pendientes cada <input type="number" name="recordatorio_horas" min="0" max="720" value="<?= e((int)(ajuste(db(), 'recordatorio_horas') ?? 24)) ?>" style="display:inline-block;width:5.5rem"> horas (0 = no enviar). Llega al correo de administración con todo lo que falta por decidir.</label>
  <button name="que" value="correo_prueba" class="secundario">Guardar y enviar correo de prueba</button>
</form>

<?php $r = reglas(db()); $con = (int)db()->query("SELECT COUNT(*) FROM usuarios WHERE activo = 1 AND rol <> 'inspeccion' AND pausa_auto_min > 0")->fetchColumn(); ?>
<?php $mant = ajuste(db(), 'mantenimiento') ?: 'no'; ?>
<form method="post" class="tarjeta<?= $mant !== 'no' ? ' aviso error' : '' ?>">
  <?= csrf_campo() ?>
  <h2>Cerrar INDA a la plantilla (mantenimiento)</h2>
  <p class="nota">Ahora: <strong><?= ['no' => 'abierto', 'fichar' => 'cerrado, solo pueden fichar', 'todo' => 'cerrado del todo'][$mant] ?></strong>. Administración sigue entrando con normalidad. Trabajadores y responsables ven un aviso de mantenimiento.</p>
  <?php if ($mant === 'no'): ?>
  <button name="modo" value="fichar">Cerrar, pero dejar fichar (recomendado)</button>
  <button name="modo" value="todo" class="secundario" data-confirmar="Nadie de la plantilla podrá fichar mientras esté cerrado. Sus fichajes de ese rato tendrán que corregirse después. ¿Cerrar del todo?">Cerrar del todo</button>
  <?php else: ?>
  <button name="modo" value="no">Abrir INDA otra vez</button>
  <?php endif ?>
  <input type="hidden" name="que" value="mantenimiento">
</form>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Hasta dónde ven los trabajadores su calendario</h2>
  <label>Pueden ver sus turnos hasta <select name="vision_dias"><?= opciones(VISION_DIAS, (int)ajuste(db(), 'vision_dias')) ?></select> en adelante</label>
  <p class="nota">Más allá verán los días como «Aún no publicado»: pueden pedir vacaciones en esas fechas, pero no ven el turno ni proponer cambios. Responsables y administración lo ven todo.</p>
  <button name="que" value="vision" class="secundario">Guardar</button>
</form>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Seguridad de los datos</h2>
  <?php if (clave_perdida(db())): ?><p class="aviso error">El cifrado está activo pero falta el archivo <code>clave-datos-inda.key</code> junto a config-inda.php. Súbelo desde tu copia: mientras falte, no se pueden ver ni guardar DNI, Seguridad Social ni fecha de nacimiento.</p><?php endif ?>
  <label><input type="checkbox" name="cifrado" <?= cifrado_activo(db()) ? 'checked' : '' ?>> Cifrar DNI, nº de la Seguridad Social y fecha de nacimiento en la base de datos</label>
  <p class="nota">Aunque alguien copie la base de datos, no podrá leerlos. La clave se guarda en el archivo <code>clave-datos-inda.key</code>, junto a config-inda.php (fuera de public_html). <strong>Haz copia de ese archivo</strong>: sin él, esos tres datos no se pueden recuperar. Se puede desactivar cuando quieras y todo vuelve a quedar en claro.</p>
  <label><input type="checkbox" name="dos_pasos" <?= ajuste(db(), 'dos_pasos') === 'si' ? 'checked' : '' ?>> Verificación en dos pasos para administración</label>
  <p class="nota">Al entrar, cada administrador escribe además un código de su móvil (Google Authenticator, Microsoft Authenticator…). La primera vez INDA le enseña cómo darla de alta. Si alguien pierde el móvil, otro administrador se la quita desde su ficha. Emergencia: <code>'sin_dos_pasos' => true</code> en config-inda.php la desactiva.<?= !empty(config()['sin_dos_pasos']) ? ' <strong>Ahora está desactivada por config-inda.php.</strong>' : '' ?></p>
  <button name="que" value="seguridad" class="secundario">Guardar</button>
</form>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Pausa automática de 15 minutos</h2>
  <p class="nota">Ahora la tienen <?= $con ?> personas. Quien la tiene no ficha la pausa: en cada jornada de más de 6 h sin pausas se apunta sola (computa como trabajo). También se pone o quita en la ficha de cada persona.</p>
  <button name="que" value="pausa_si" class="secundario" data-confirmar="¿Poner la pausa automática a toda la plantilla?">Ponérsela a todos</button>
  <button name="que" value="pausa_no" class="secundario" data-confirmar="¿Quitar la pausa automática a toda la plantilla?">Quitársela a todos</button>
</form>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Avisos a los trabajadores</h2>
  <label><input type="checkbox" name="avisar" <?= avisos_a_trabajadores(db()) ? 'checked' : '' ?>> Enviar correos y avisos al móvil a los trabajadores por cada cambio (cuadrante, recálculos, ausencias…)</label>
  <p class="nota">Quítalo mientras subes o cambias datos: los cambios se guardan y quedan en su campana de avisos, pero no les llega ningún correo. Cuando todo esté listo, mándales un solo aviso:</p>
  <button name="que" value="avisos_trab" class="secundario">Guardar</button>
  <label>Mensaje para toda la plantilla <input name="texto" value="Tu calendario está actualizado. Revísalo en INDA." maxlength="300"></label>
  <button name="que" value="anunciar" data-confirmar="¿Enviar este aviso a todos los trabajadores?">Avisar a todos ahora</button>
</form>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Mensajes de aviso por email</h2>
  <p class="nota">Lo que reciben por email (y en INDA) cuando no fichan a tiempo. Puedes usar {nombre}, {fecha}, {hora} (hora del turno), {turno} y {fichado} (hora a la que fichó). Déjalo vacío para usar el texto de INDA. El margen de cortesía de cada turno se pone en Horarios.</p>
  <label>Cerrar la jornada sola a los <input type="number" name="cierre_auto_min" min="0" max="240" value="<?= minutos_cierre(db()) ?>" style="display:inline-block;width:5.5rem"> minutos de la hora de salida si no ficha (0 = no cerrar). Al acabar su turno recibe un recordatorio; al cerrarse, otro aviso. La salida queda marcada como automática y se puede corregir.</label>
  <?php foreach (PLANTILLAS_AVISO as $k => [$quien, $def]): ?>
  <label><?= e($quien) ?> <textarea name="plantilla_<?= $k ?>" rows="4" placeholder="<?= e($def) ?>"><?= e(plantilla_aviso(db(), $k) === $def ? '' : plantilla_aviso(db(), $k)) ?></textarea></label>
  <?php endforeach ?>
  <button name="que" value="plantillas">Guardar mensajes</button>
</form>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Reglas de jornada</h2>
  <p class="nota">Por defecto, las del Estatuto de los Trabajadores. Pon las de tu convenio solo si son distintas. INDA las comprueba al planificar y en los informes.</p>
  <div class="cifras">
    <?php foreach (REGLAS_TEXTO as $k => $txt): ?>
    <label><span><?= e($txt) ?></span><input type="number" step="0.5" min="0" name="<?= $k ?>" value="<?= e($r[$k]) ?>"></label>
    <?php endforeach ?>
  </div>
  <button name="que" value="reglas">Guardar reglas</button>
  <button name="que" value="plantilla_galicia" class="secundario">Cargar el VI Convenio de residencias de Galicia</button>
  <p class="nota">Carga: 1.762 h/año, 40 h/semana, 9 h/día (ampliable con acuerdo), 12 h entre jornadas, 36 h semanales con domingo cada 4 semanas, 60 h extra, pausa de 20 min retribuida, jornada partida con 1 h mínima, noche de 22:00 a 7:00, 26 días laborables de vacaciones, 6 de libre disposición, lactancia, asambleas y crédito sindical (por horas) y el baremo de puntualidad. Revísalo con el texto oficial.</p>
</form>

<h2>Centros</h2>
<table>
  <tr><th>Nombre</th><th>GPS</th><th>Radio</th><th>Fuera de zona</th><th>Redes</th><th></th></tr>
  <?php foreach ($centros as $x): ?>
  <tr class="<?= $x['activo'] ? '' : 'anulado' ?>"><td><?= e($x['nombre']) ?></td><td><?= $gps[$x['gps_modo']] ?></td><td><?= e($x['radio_m']) ?> m</td>
    <td><?= $fuera[$x['fuera_zona']] ?></td><td><?= e($x['ips'] ?: 'Cualquiera') ?></td>
    <td><a href="?p=admin&c=<?= e($x['id']) ?>">Editar</a> · <a href="?p=cartel&c=<?= e($x['id']) ?>">Cartel QR</a></td></tr>
  <?php endforeach ?>
</table>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>">
  <h2><?= $c['id'] ? 'Editar centro' : 'Nuevo centro' ?></h2>
  <label>Nombre <input name="nombre" value="<?= e($c['nombre']) ?>" required></label>
  <label>Dirección <input name="direccion" value="<?= e($c['direccion']) ?>"></label>
  <label>Latitud <input name="lat" value="<?= e($c['lat']) ?>" inputmode="decimal" placeholder="40.416800"></label>
  <label>Longitud <input name="lng" value="<?= e($c['lng']) ?>" inputmode="decimal" placeholder="-3.703800"></label>
  <label>Radio del centro (metros) <input type="number" name="radio_m" min="10" value="<?= e($c['radio_m']) ?>"></label>
  <label>GPS al fichar <select name="gps_modo"><?= opciones($gps, $c['gps_modo']) ?></select></label>
  <label>Si fichan fuera del radio <select name="fuera_zona"><?= opciones($fuera, $c['fuera_zona']) ?></select></label>
  <label>Redes permitidas (IP o inicio de IP, separadas por comas; vacío = cualquiera) <input name="ips" value="<?= e($c['ips']) ?>" placeholder="88.12.34.56, 10.0."></label>
  <label><input type="checkbox" name="activo" <?= $c['activo'] ? 'checked' : '' ?>> Activo</label>
  <button>Guardar centro</button>
  <p class="nota">Coordenadas: en Google Maps, clic derecho sobre el centro → copia los dos números. Tu IP ahora: <?= e(ip()) ?>. El quiosco no tiene límite de red.</p>
</form>

<h2>Departamentos</h2>
<?php foreach ($deps as $d): ?>
<form method="post" class="filtros">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($d['id']) ?>">
  <label><input name="nombre" value="<?= e($d['nombre']) ?>" required></label>
  <label><input type="checkbox" name="activo" <?= $d['activo'] ? 'checked' : '' ?>> Activo</label>
  <button name="que" value="departamento" class="secundario">Guardar</button>
</form>
<?php endforeach ?>
<form method="post" class="filtros">
  <?= csrf_campo() ?>
  <label><input name="nombre" placeholder="Nuevo departamento" required></label>
  <button name="que" value="departamento">Añadir</button>
</form>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Permisos por rol</h2>
  <p class="nota">El administrador lo puede todo. El responsable solo actúa sobre su equipo.</p>
  <table>
    <tr><th>Permiso</th><th>Responsable</th><th>Empleado</th><th>Inspección</th></tr>
    <?php foreach (PERMISOS as $p => $txt): ?>
    <tr><td><?= e($txt) ?></td>
      <?php foreach (['responsable', 'empleado', 'inspeccion'] as $rol): ?>
      <td><?php if ($rol !== 'inspeccion' || $p === 'informes'): ?><input type="checkbox" name="<?= "$rol:$p" ?>" <?= in_array("$rol:$p", $permisos, true) ? 'checked' : '' ?>><?php endif ?></td>
      <?php endforeach ?></tr>
    <?php endforeach ?>
  </table>
  <button name="que" value="permisos">Guardar permisos</button>
</form>
<?php pie();
