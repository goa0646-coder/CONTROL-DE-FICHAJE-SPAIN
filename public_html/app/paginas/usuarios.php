<?php
$yo = requiere('admin');
$roles = ['empleado' => 'Empleado', 'responsable' => 'Responsable', 'admin' => 'Administrador', 'inspeccion' => 'Inspección (solo lectura)'];
$gps = ['' => 'Lo que diga su centro', 'no' => 'Sin GPS', 'opcional' => 'Opcional', 'obligatorio' => 'Obligatorio'];

// Dar de baja / reactivar con un botón. La baja no borra nada: el registro se conserva 4 años.
if (es_post() && isset($_POST['cambiar_estado'])) {
    $id = (int)$_POST['cambiar_estado'];
    $p = usuario(db(), $id);
    if ($p && $id != $yo['id']) {
        $activar = !$p['activo'];
        consulta(db(), 'UPDATE usuarios SET activo = ?, baja_utc = ? WHERE id = ?', [(int)$activar, $activar ? null : ahora(), $id]);
        if (!$activar) cerrar_dispositivos(db(), $id);
        auditar(db(), (int)$yo['id'], $activar ? 'usuario_reactivado' : 'usuario_baja', "usuario=$id");
        aviso(nombre($p) . ($activar ? ' vuelve a estar activo.' : ' queda de baja. Ya no puede entrar ni fichar; su registro se conserva.'));
    }
    redirigir('usuarios', array_intersect_key($_GET, ['ver' => 1, 'q' => 1]));
}

// Enviar por email el acceso (elegir contraseña) o un enlace para cambiarla; o el acceso a todos los que no han entrado nunca.
if (es_post() && isset($_POST['enviar_acceso'])) {
    $ids = $_POST['enviar_acceso'] === 'pendientes'
        ? db()->query("SELECT id FROM usuarios WHERE activo = 1 AND ultimo_acceso_utc IS NULL AND rol <> 'inspeccion'")->fetchAll(PDO::FETCH_COLUMN)
        : [(int)$_POST['enviar_acceso']];
    $n = 0;
    foreach ($ids as $id) if (($p = usuario(db(), (int)$id)) && $p['activo'] && $p['email']) $n += (int)enviar_bienvenida(db(), $p, isset($_POST['cambio']));
    auditar(db(), (int)$yo['id'], 'acceso_enviado', 'personas=' . count($ids) . " enviados=$n");
    aviso($n ? "Enviado a $n " . ($n === 1 ? 'persona' : 'personas') . '. El enlace vale 7 días.' : 'No se ha podido enviar: revisa Administración → Correo y Diagnóstico.', $n ? 'ok' : 'error');
    redirigir('usuarios', count($ids) === 1 && $_POST['enviar_acceso'] !== 'pendientes' ? ['u' => (int)$ids[0]] : []);
}

// El admin puede retirar una foto de perfil inadecuada.
if (es_post() && isset($_POST['quitar_foto'])) {
    $id = (int)$_POST['quitar_foto'];
    consulta(db(), 'UPDATE usuarios SET foto = NULL WHERE id = ?', [$id]);
    auditar(db(), (int)$yo['id'], 'foto_retirada', "usuario=$id");
    aviso('Foto retirada.');
    redirigir('usuarios', ['u' => $id]);
}

if (es_post() && (isset($_POST['valorar']) || isset($_POST['apuntar']))) {
    $id = (int)($_POST['valorar'] ?? $_POST['apuntar']);
    try {
        if (isset($_POST['apuntar'])) apuntar(db(), $yo, $id, $_POST['tipo'] ?? '', $_POST['fecha'] ?? '', $_POST['texto'] ?? '');
        else valorar_apartados(db(), $yo, $id, (array)($_POST['a'] ?? []), $_POST['nota'] ?? '');
        aviso(isset($_POST['apuntar']) ? 'Apunte guardado.' : 'Valoración guardada.');
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('usuarios', ['u' => $id]);
}

if (es_post() && isset($_POST['saldos'])) {
    $id = (int)$_POST['id'];
    $anio = (int)$_POST['anio'];
    $p = usuario(db(), $id) ?? redirigir('usuarios');
    try {
        if ($tipo = (int)($_POST['arrastrar'] ?? 0)) {
            $n = arrastrar_saldo(db(), $p, $tipo, $anio);
            aviso("Pasados $n días a " . ($anio + 1) . '.');
        } else {
            foreach ($_POST['dias'] ?? [] as $tipo => $dias) {
                if ($dias === '') consulta(db(), 'DELETE FROM saldos_ausencia WHERE usuario_id = ? AND tipo_id = ? AND anio = ?', [$id, (int)$tipo, $anio]);
                else consulta(db(), 'REPLACE INTO saldos_ausencia VALUES (?, ?, ?, ?)', [$id, (int)$tipo, $anio, max(0, (float)str_replace(',', '.', $dias))]);
            }
            aviso('Días guardados.');
        }
        auditar(db(), (int)$yo['id'], 'saldos_ausencia', "usuario=$id anio=$anio");
    } catch (DomainException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('usuarios', ['u' => $id, 'anio' => $anio]);
}

if (es_post()) {
    $id = (int)($_POST['id'] ?? 0);
    $p = array_map(fn($v) => is_string($v) ? trim($v) : $v, $_POST);
    $error = match (true) {
        $p['nombre'] === '' => 'El nombre es obligatorio.',
        !filter_var($p['email'], FILTER_VALIDATE_EMAIL) => 'El email no es válido.',
        !isset($roles[$p['rol']]) => 'Rol no válido.',
        !$id && strlen($p['clave']) < 8, $p['clave'] !== '' && strlen($p['clave']) < 8 => 'La contraseña debe tener al menos 8 caracteres.',
        $p['pin'] !== '' && !preg_match('/^\d{4,8}$/', $p['pin']) => 'El PIN debe tener de 4 a 8 cifras.',
        $p['pin'] !== '' && $p['num_empleado'] === '' => 'Para usar el quiosco hace falta nº de empleado.',
        $id && $id == $yo['id'] && ($p['rol'] !== 'admin' || !isset($p['activo'])) => 'No puedes quitarte el rol de administrador ni darte de baja.',
        $id && $id == (int)$p['responsable_id'] => 'Una persona no puede ser su propia responsable.',
        default => null,
    };
    if ($error) {
        aviso($error, 'error');
        redirigir('usuarios', $id ? ['u' => $id] : ['nuevo' => 1]);
    }
    $antes = $id ? usuario(db(), $id) : null;
    $activo = isset($p['activo']) ? 1 : 0;
    $campos = [
        'num_empleado' => $p['num_empleado'] ?: null, 'nombre' => $p['nombre'], 'apellidos' => $p['apellidos'], 'dni' => strtoupper($p['dni']),
        'email' => mb_strtolower($p['email']), 'rol' => $p['rol'],
        'telefono' => mb_substr($p['telefono'] ?? '', 0, 30) ?: null, 'nss' => mb_substr(preg_replace('/\s+/', '', $p['nss'] ?? ''), 0, 20) ?: null,
        'fecha_nacimiento' => fecha_iso($p['fecha_nacimiento'] ?? ''), 'centro_id' => (int)$p['centro_id'] ?: null,
        'responsable_id' => (int)$p['responsable_id'] ?: null,
        'departamento_id' => (int)($p['departamento_id'] ?? 0) ?: null, 'horario_id' => (int)($p['horario_id'] ?? 0) ?: null, 'gps_modo' => isset($gps[$p['gps_modo']]) && $p['gps_modo'] !== '' ? $p['gps_modo'] : null,
        'acceso_hasta' => $p['rol'] === 'inspeccion' ? ($p['acceso_hasta'] ?: gmdate('Y-m-d', strtotime('+30 days'))) : null,
        'activo' => $activo, 'sin_fichaje' => ($p['modo_fichaje'] ?? '') === 'sin' ? 1 : 0, 'fichaje_auto' => ($p['modo_fichaje'] ?? '') === 'auto' ? 1 : 0,
        'pausa_auto_min' => isset($p['pausa_auto']) ? 15 : 0,
        'baja_utc' => $activo ? null : ($antes['baja_utc'] ?? ahora()),
    ];
    if ($p['clave'] !== '') $campos['password_hash'] = password_hash($p['clave'], PASSWORD_DEFAULT);
    if ($p['pin'] !== '') $campos['pin_hash'] = password_hash($p['pin'], PASSWORD_DEFAULT);
    try {
        if ($id) {
            db()->prepare('UPDATE usuarios SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos))) . ' WHERE id = ?')
                ->execute([...array_values($campos), $id]);
        } else {
            $campos['alta_utc'] = ahora();
            db()->prepare('INSERT INTO usuarios (' . implode(', ', array_keys($campos)) . ') VALUES (' . rtrim(str_repeat('?, ', count($campos)), ', ') . ')')
                ->execute(array_values($campos));
            $id = (int)db()->lastInsertId();
        }
    } catch (PDOException $e) {
        aviso($e->getCode() == 23000 ? 'Ese email o nº de empleado ya existe.' : 'No se pudo guardar.', 'error');
        redirigir('usuarios', $id ? ['u' => $id] : ['nuevo' => 1]);
    }
    // Contraseña nueva puesta por el admin o baja: fuera las sesiones guardadas de esa persona.
    if (isset($campos['password_hash']) || !$activo) cerrar_dispositivos(db(), $id);
    consulta(db(), 'DELETE FROM usuarios_puestos WHERE usuario_id = ?', [$id]);
    foreach (array_intersect(array_map('intval', (array)($_POST['puestos'] ?? [])), array_keys(puestos(db()))) as $pid) consulta(db(), 'INSERT INTO usuarios_puestos VALUES (?, ?)', [$id, $pid]);
    auditar(db(), (int)$yo['id'], 'usuario_guardado', "usuario=$id rol={$campos['rol']} activo=$activo");
    aviso('Guardado.');
    redirigir('usuarios');
}

$todos = db()->query('SELECT u.*, c.nombre AS centro FROM usuarios u LEFT JOIN centros c ON c.id = u.centro_id ORDER BY u.activo DESC, u.nombre, u.apellidos')->fetchAll();
$ver = in_array($_GET['ver'] ?? '', ['activos', 'bajas', 'todos'], true) ? $_GET['ver'] : 'activos';
$q = normalizar($_GET['q'] ?? '');
$lista = array_values(array_filter($todos, fn($x) => ($ver === 'todos' || ($ver === 'activos') === (bool)$x['activo'])
    && ($q === '' || str_contains(normalizar(nombre($x) . ' ' . $x['email'] . ' ' . $x['dni']), $q))));
$cuantos = ['activos' => count(array_filter($todos, fn($x) => $x['activo'])), 'bajas' => count(array_filter($todos, fn($x) => !$x['activo']))];
$centros = db()->query('SELECT id, nombre FROM centros WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
$deps = db()->query('SELECT id, nombre FROM departamentos WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
$hors = db()->query('SELECT id, nombre FROM horarios WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);
$jefes = [];
foreach ($todos as $x) if (in_array($x['rol'], ['responsable', 'admin'], true) && $x['activo']) $jefes[$x['id']] = nombre($x);
$u = isset($_GET['u']) ? usuario(db(), (int)$_GET['u']) : null;
$u ??= ['id' => '', 'num_empleado' => '', 'nombre' => '', 'apellidos' => '', 'dni' => '', 'email' => '', 'rol' => 'empleado',
    'centro_id' => '', 'responsable_id' => '', 'departamento_id' => '', 'horario_id' => '', 'gps_modo' => '', 'acceso_hasta' => '', 'activo' => 1, 'pin_hash' => null];
$editando = isset($_GET['u']) || isset($_GET['nuevo']);
cabecera($editando ? ($u['id'] ? nombre($u) : 'Nueva persona') : 'Usuarios', $yo);
?>
<?php if ($editando): ?>
<p class="volver-arriba"><a href="?p=usuarios"><?= icono('flecha') ?> Volver a la plantilla</a></p>
<?php if (!empty($u['foto'])): ?>
<form method="post" class="tarjeta con-avatar"><?= csrf_campo() ?><?= avatar($u, 'grande') ?> <button name="quitar_foto" value="<?= e($u['id']) ?>" class="secundario" data-confirmar="¿Retirar la foto de perfil de <?= e(nombre($u)) ?>?">Retirar foto</button></form>
<?php endif ?>
<?php if ($u['id']): ?>
<form method="post" class="tarjeta filtros"><?= csrf_campo() ?>
  <span><b>Acceso a INDA</b><br><small><?= $u['ultimo_acceso_utc'] ? 'Último acceso: ' . local($u['ultimo_acceso_utc']) : 'Todavía no ha entrado nunca' ?><?= $u['acceso_enviado_utc'] ? ' · acceso enviado el ' . local($u['acceso_enviado_utc']) : '' ?></small></span>
  <button name="enviar_acceso" value="<?= e($u['id']) ?>" class="secundario pequeno">Enviar acceso por email</button>
  <button name="enviar_acceso" value="<?= e($u['id']) ?>" class="secundario pequeno" onclick="this.form.insertAdjacentHTML('beforeend','<input type=hidden name=cambio value=1>')">Enviar enlace para cambiar contraseña</button>
  <a class="boton secundario pequeno" href="?p=historial&u=<?= e($u['id']) ?>">Ver sus fichajes</a>
</form>
<?php endif ?>
<form method="post" class="tarjeta ficha-persona">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($u['id']) ?>">
  <h2><?= $u['id'] ? 'Datos de ' . e(nombre($u)) : 'Datos de la nueva persona' ?></h2>
  <?php if (ia_activa()): ?>
  <div class="tarjeta" data-ia-dni>
    <label>Rellenar con la foto del DNI/NIE <input type="file" accept="image/*,application/pdf" capture="environment"></label>
    <button type="button">Leer con IA</button> <span class="nota" data-ia-estado></span>
    <p class="nota">Se envía a Google Gemini solo para leerlo y no se guarda en INDA. Con datos personales usa una cuenta de Google con facturación activada (así Google no usa los datos). Revisa siempre lo que lea la IA.</p>
  </div>
  <?php endif ?>
  <label>Nombre <input name="nombre" value="<?= e($u['nombre']) ?>" required></label>
  <label>Apellidos <input name="apellidos" value="<?= e($u['apellidos']) ?>"></label>
  <label>DNI/NIE <input name="dni" value="<?= e($u['dni']) ?>"></label>
  <label>Email <input type="email" name="email" value="<?= e($u['email']) ?>" required></label>
  <div class="filtros">
    <label>Teléfono <input type="tel" name="telefono" value="<?= e($u['telefono'] ?? '') ?>" maxlength="30"></label>
    <label>Nº Seguridad Social <input name="nss" value="<?= e($u['nss'] ?? '') ?>" maxlength="20"></label>
    <label>Fecha de nacimiento <input type="date" name="fecha_nacimiento" value="<?= e($u['fecha_nacimiento'] ?? '') ?>"></label>
  </div>
  <label>Rol <select name="rol"><?= opciones($roles, $u['rol']) ?></select></label>
  <label>Centro <select name="centro_id"><?= opciones(['' => '—'] + $centros, $u['centro_id']) ?></select></label>
  <label>Departamento <select name="departamento_id"><?= opciones(['' => '—'] + $deps, $u['departamento_id']) ?></select></label>
  <label>Horario <select name="horario_id"><?= opciones(['' => 'Sin horario'] + $hors, $u['horario_id']) ?></select></label>
  <label>Responsable <select name="responsable_id"><?= opciones(['' => '—'] + $jefes, $u['responsable_id']) ?></select></label>
  <?php if ($pts = puestos(db())): $mios = puestos_por_persona(db())[$u['id'] ?: 0] ?? []; ?>
  <fieldset><legend>Puestos que sabe cubrir</legend>
    <?php foreach ($pts as $pid => $pn): ?><label><input type="checkbox" name="puestos[]" value="<?= e($pid) ?>" <?= in_array($pid, $mios, true) ? 'checked' : '' ?>> <?= e($pn) ?></label><?php endforeach ?>
  </fieldset>
  <?php endif ?>
  <label>GPS al fichar <select name="gps_modo"><?= opciones($gps, $u['gps_modo'] ?? '') ?></select></label>
  <label>Fichaje <select name="modo_fichaje"><?= opciones(['' => 'Normal: ficha la persona', 'sin' => 'Sin fichaje: su registro es su horario (si no ficha)', 'auto' => 'Automático: INDA ficha entrada y salida a la hora de su turno'], !empty($u['fichaje_auto']) ? 'auto' : (!empty($u['sin_fichaje']) ? 'sin' : '')) ?></select></label>
  <label><input type="checkbox" name="pausa_auto" value="1" <?= !empty($u['pausa_auto_min']) ? 'checked' : '' ?>> Pausa automática de 15 min (no la tiene que fichar; en jornadas de más de 6 h sin pausa se apunta sola y computa como trabajo)</label>
  <label>Nº de empleado (quiosco) <input name="num_empleado" value="<?= e($u['num_empleado']) ?>"></label>
  <label>PIN de quiosco (4–8 cifras) <input name="pin" inputmode="numeric" autocomplete="off" placeholder="<?= $u['pin_hash'] ? 'Dejar vacío para no cambiarlo' : '' ?>"></label>
  <label>Contraseña <input type="password" name="clave" autocomplete="new-password" placeholder="<?= $u['id'] ? 'Dejar vacío para no cambiarla' : 'Mínimo 8 caracteres' ?>"></label>
  <label>Acceso hasta (solo Inspección) <input type="date" name="acceso_hasta" value="<?= e($u['acceso_hasta']) ?>"></label>
  <label><input type="checkbox" name="activo" <?= $u['activo'] ? 'checked' : '' ?>> Activo (desmarcar = baja; su registro se conserva)</label>
  <div class="pie-form"><button>Guardar</button> <a class="boton secundario" href="?p=usuarios">Cancelar</a></div>
</form>

<?php if ($u['id'] && $u['rol'] !== 'inspeccion' && ve_valoraciones(db(), $yo)): $ev = evaluacion(db(), $yo, $u); $va = valoraciones(db(), $yo, (int)$u['id']); $ap = apuntes_de(db(), $yo, (int)$u['id']);
    $sel = fn($n) => '<select name="a[' . $n . ']">' . opciones([5 => '★★★★★', 4 => '★★★★☆', 3 => '★★★☆☆', 2 => '★★☆☆☆', 1 => '★☆☆☆☆'], 3) . '</select>'; ?>
<div class="tarjeta">
  <h2>Evaluación <span class="estrellas"><?= estrellas($ev['final']) ?></span> <small class="nota"><?= $ev['final'] ?? 'sin datos' ?> · solo lo ves tú</small></h2>
  <p class="nota">40 % objetiva (últimos 6 meses) + 60 % tu valoración (último año), menos 1★ por cada 2 apuntes del último año (<?= $ev['apuntes'] ?>). Las bajas, vacaciones y permisos aprobados no cuentan.</p>
  <table>
    <tr><th colspan="2">Objetiva <?= $ev['media_objetiva'] ?? '—' ?></th><th colspan="2">Tu valoración <?= $ev['subjetiva'] ?? '—' ?></th></tr>
    <?php $ob = ['puntualidad' => 'Puntualidad', 'fichaje' => 'Fichaje correcto', 'confianza' => 'Confianza (faltas sin justificar, justificantes)', 'colaboracion' => 'Colaboración (cubre y cambia turnos)'];
    $ks = array_keys($ob); $as = array_keys(APARTADOS);
    for ($i = 0; $i < 5; $i++): ?>
    <tr><td><?= isset($ks[$i]) ? e($ob[$ks[$i]]) : '' ?></td><td><?= isset($ks[$i]) ? estrellas($ev['objetiva'][$ks[$i]] === null ? null : (float)$ev['objetiva'][$ks[$i]]) : '' ?></td>
      <td><?= e(APARTADOS[$as[$i]]) ?></td><td><?= estrellas($ev['apartados'][$as[$i]]) ?></td></tr>
    <?php endfor ?>
  </table>
  <form method="post"><?= csrf_campo() ?>
    <h3>Valorar ahora</h3>
    <div class="filtros"><?php foreach (APARTADOS as $k => $n): ?><label><?= e($n) ?> <?= $sel($k) ?></label><?php endforeach ?></div>
    <label>Nota (opcional) <input name="nota" maxlength="500"></label>
    <button name="valorar" value="<?= e($u['id']) ?>">Guardar valoración</button>
  </form>
  <form method="post"><?= csrf_campo() ?>
    <h3>Apunte</h3>
    <div class="filtros">
      <label>Tipo <select name="tipo"><?= opciones(TIPOS_APUNTE, 'incidencia') ?></select></label>
      <label>Fecha <input type="date" name="fecha" value="<?= e(local(ahora(), 'Y-m-d')) ?>"></label>
      <label>Qué pasó <input name="texto" maxlength="1000" required></label>
      <button name="apuntar" value="<?= e($u['id']) ?>">Apuntar</button>
    </div>
  </form>
  <?php foreach (array_slice($ap, 0, 20) as $x): ?><p class="nota"><strong><?= e(TIPOS_APUNTE[$x['tipo']]) ?></strong> · <?= date('d/m/Y', strtotime($x['fecha'])) ?> · <?= e($x['texto']) ?></p><?php endforeach ?>
  <?php foreach (array_slice($va['lista'], 0, 10) as $x): ?><p class="nota"><?= estrellas((float)$x['estrellas']) ?> · <?= e(local($x['creado_utc'], 'd/m/Y')) ?><?= $x['nota'] !== '' ? ' · ' . e($x['nota']) : '' ?></p><?php endforeach ?>
</div>
<?php endif ?>
<?php if ($u['id'] && $u['rol'] !== 'inspeccion'): $docs = consulta(db(), 'SELECT * FROM documentos WHERE usuario_id = ? AND activo = 1 ORDER BY subido_utc DESC', [$u['id']])->fetchAll(); ?>
<form method="post" action="?p=documentos" enctype="multipart/form-data" class="tarjeta">
  <?= csrf_campo() ?><input type="hidden" name="que" value="subir"><input type="hidden" name="para" value="personas"><input type="hidden" name="personas[]" value="<?= e($u['id']) ?>"><input type="hidden" name="volver_u" value="<?= e($u['id']) ?>">
  <h2>Contrato y documentos</h2>
  <?php foreach ($docs as $d): ?><p><a href="?p=archivo&d=<?= e($d['id']) ?>"><?= e($d['titulo']) ?></a> <small class="nota"><?= local($d['subido_utc'], 'd/m/Y') ?></small></p><?php endforeach ?>
  <?php if (!$docs): ?><p class="nota">Aún no tiene documentos.</p><?php endif ?>
  <div class="filtros">
    <label>Título <input name="titulo" value="Contrato de trabajo" maxlength="150"></label>
    <label>Archivo <input type="file" name="archivo" required></label>
    <label><input type="checkbox" name="firma"> Que lo firme</label>
    <label><input type="checkbox" name="por_email"> Avisarle por email</label>
    <button>Subir</button>
  </div>
  <p class="nota">Lo ve la persona en «Documentos» y cualquier administrador.</p>
</form>
<?php endif ?>
<?php if ($u['id'] && $u['rol'] !== 'inspeccion'):
    $anio = (int)($_GET['anio'] ?? local(ahora(), 'Y'));
    $saldos = saldos_ausencia(db(), $u, $anio);
    $propios = consulta(db(), 'SELECT tipo_id, dias FROM saldos_ausencia WHERE usuario_id = ? AND anio = ?', [$u['id'], $anio])->fetchAll(PDO::FETCH_KEY_PAIR); ?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= e($u['id']) ?>"><input type="hidden" name="anio" value="<?= $anio ?>"><input type="hidden" name="saldos" value="1">
  <h2>Días de <?= e($u['nombre']) ?> en <?= $anio ?> <a class="nota" href="?p=usuarios&u=<?= e($u['id']) ?>&anio=<?= $anio - 1 ?>">‹ <?= $anio - 1 ?></a> <a class="nota" href="?p=usuarios&u=<?= e($u['id']) ?>&anio=<?= $anio + 1 ?>"><?= $anio + 1 ?> ›</a></h2>
  <button>Guardar días</button>
  <table>
    <tr><th>Tipo</th><th>Asignados</th><th>Disfrutados</th><th>Pedidos</th><th>Quedan</th><th></th></tr>
    <?php foreach ($saldos as $tid => $x): ?>
    <tr><td><?= e($x['tipo']) ?></td>
      <td><input name="dias[<?= e($tid) ?>]" value="<?= e(isset($propios[$tid]) ? (float)$propios[$tid] : '') ?>" placeholder="<?= e((float)$x['asignado']) ?>" size="4"></td>
      <td><?= e($x['consumido']) ?></td><td><?= e($x['comprometido']) ?></td><td><?= e((float)$x['disponible']) ?></td>
      <td><button name="arrastrar" value="<?= e($tid) ?>" class="secundario">Pasar sobrante a <?= $anio + 1 ?></button></td></tr>
    <?php endforeach ?>
  </table>
  <p class="nota">Vacío = lo que diga el tipo de ausencia. Pon aquí la parte proporcional si entra a mitad de año.</p>
</form>
<?php endif ?>
<?php else: ?>
<div class="barra-acciones">
  <form class="filtros buscador">
    <input type="hidden" name="p" value="usuarios">
    <label><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Buscar por nombre, email o DNI" aria-label="Buscar"></label>
    <label><select name="ver" onchange="this.form.submit()" aria-label="Mostrar"><?= opciones(['activos' => "Activos ({$cuantos['activos']})", 'bajas' => "De baja ({$cuantos['bajas']})", 'todos' => 'Todos'], $ver) ?></select></label>
  </form>
  <span class="botones-derecha"><?php $sin = (int)db()->query("SELECT COUNT(*) FROM usuarios WHERE activo = 1 AND ultimo_acceso_utc IS NULL AND rol <> 'inspeccion'")->fetchColumn(); if ($sin): ?><form method="post" style="display:inline"><?= csrf_campo() ?><button name="enviar_acceso" value="pendientes" class="secundario" data-confirmar="¿Enviar el email de acceso a las <?= $sin ?> personas que no han entrado nunca?">Enviar acceso a quien no ha entrado (<?= $sin ?>)</button></form><?php endif ?>
    <a class="boton secundario" href="?p=importar">Importar desde Excel</a> <a class="boton" href="?p=usuarios&nuevo=1">+ Nueva persona</a></span>
</div>
<table>
  <tr><th>Nombre</th><th>Rol</th><th>Centro</th><th>Email</th><th>Último acceso</th><th>Estado</th><th></th></tr>
  <?php foreach ($lista as $x): ?>
  <tr class="<?= $x['activo'] ? '' : 'anulado' ?>">
    <td><a class="con-avatar" href="?p=usuarios&u=<?= e($x['id']) ?>"><?= avatar($x, 'mini') ?> <?= e(nombre($x)) ?></a></td><td><?= $roles[$x['rol']] ?></td><td><?= e($x['centro'] ?? '') ?></td><td><?= e($x['email']) ?></td>
    <td><small><?= !empty($x['ultimo_acceso_utc']) ? local($x['ultimo_acceso_utc'], 'd/m/Y H:i') : '<span class="error">Nunca</span>' ?></small></td>
    <td><span class="estado <?= $x['activo'] ? 'dentro' : 'fuera' ?>"><?= $x['activo'] ? 'Activo' : 'De baja' ?></span></td>
    <td class="acciones"><a href="?p=usuarios&u=<?= e($x['id']) ?>">Editar</a>
      <?php if ($x['id'] != $yo['id']): ?>
      <form method="post" style="display:inline"><?= csrf_campo() ?><button name="cambiar_estado" value="<?= e($x['id']) ?>" class="secundario pequeno"
        data-confirmar="<?= $x['activo'] ? e('¿Dar de baja a ' . nombre($x) . '? No podrá entrar ni fichar. Su registro se conserva.') : '' ?>"><?= $x['activo'] ? 'Dar de baja' : 'Reactivar' ?></button></form>
      <?php endif ?></td>
  </tr>
  <?php endforeach ?>
</table>
<?php if (!$lista): ?><p class="nota">Nadie con ese filtro.</p><?php endif ?>
<?php endif;
pie();
