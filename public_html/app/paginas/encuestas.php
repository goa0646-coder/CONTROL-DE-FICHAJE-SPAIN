<?php
// Encuestas y evaluaciones: responder lo pendiente; administración y responsables crean y ven resultados.
$u = requiere('empleado', 'responsable', 'admin');
$gestor = in_array($u['rol'], ['admin', 'responsable'], true);
if (es_post()) {
    try {
        if ($a = (int)($_POST['responder'] ?? 0)) {
            responder_encuesta(db(), $u, $a, (array)($_POST['r'] ?? []));
            aviso('¡Gracias! Respuesta enviada.');
        } elseif ($gestor && ($_POST['que'] ?? '') === 'crear') {
            $pr = [];
            foreach ((array)($_POST['pregunta'] ?? []) as $i => $t) $pr[] = [$t, $_POST['tipo_p'][$i] ?? 'escala', $_POST['opciones'][$i] ?? ''];
            $para = ($_POST['para'] ?? '') === 'todos' ? array_column(array_filter(equipo_de(db(), $u), fn($p) => $p['activo'] && $p['rol'] !== 'inspeccion'), 'id') : (array)($_POST['personas'] ?? []);
            $id = crear_encuesta(db(), $u, $_POST, $pr, $para);
            aviso('Creada y enviada.');
            redirigir('encuestas', ['res' => $id]);
        } elseif ($gestor && ($id = (int)($_POST['cerrar'] ?? 0))) {
            consulta(db(), 'UPDATE encuestas SET activa = 0 WHERE id = ?' . ($u['rol'] === 'admin' ? '' : ' AND autor_id = ' . (int)$u['id']), [$id]);
            aviso('Cerrada: ya no se puede responder.');
        }
    } catch (DomainException $e) {
        if (db()->inTransaction()) db()->rollBack();
        aviso($e->getMessage(), 'error');
    }
    redirigir('encuestas');
}
$pend = pendientes_encuesta(db(), $u);
$ver = (int)($_GET['id'] ?? 0);
cabecera('Encuestas y evaluaciones', $u);

// Responder
foreach ($pend as $a): if ($ver && $a['encuesta_id'] != $ver) continue; $e = encuesta(db(), (int)$a['encuesta_id']); $sobre = $a['sobre_id'] ? usuario(db(), (int)$a['sobre_id']) : null; ?>
<form method="post" class="tarjeta encuesta">
  <?= csrf_campo() ?><input type="hidden" name="responder" value="<?= e($a['id']) ?>">
  <h2><?= e($e['titulo']) ?><?= $sobre ? ' · ' . e(nombre($sobre)) : '' ?></h2>
  <?php if ($e['descripcion'] !== ''): ?><p class="nota"><?= nl2br(e($e['descripcion'])) ?></p><?php endif ?>
  <p class="nota"><?= $e['anonima'] ? 'Anónima: nadie podrá saber qué has respondido.' : ($sobre ? 'Evaluación de ' . e(nombre($sobre)) . '.' : 'No es anónima.') ?><?= $e['cierra'] ? ' Hasta el ' . date('d/m/Y', strtotime($e['cierra'])) . '.' : '' ?></p>
  <?php foreach (preguntas(db(), (int)$e['id']) as $i => $p): $n = "r[{$p['id']}]"; ?>
  <fieldset><legend><?= $i + 1 ?>. <?= e($p['texto']) ?></legend>
    <?php if ($p['tipo'] === 'escala'): ?><div class="escala"><?php for ($k = 1; $k <= 5; $k++): ?><label><input type="radio" name="<?= $n ?>" value="<?= $k ?>" required> <?= $k ?></label><?php endfor ?><small>1 = muy mal · 5 = muy bien</small></div>
    <?php elseif ($p['tipo'] === 'si_no'): ?><label><input type="radio" name="<?= $n ?>" value="si" required> Sí</label> <label><input type="radio" name="<?= $n ?>" value="no"> No</label>
    <?php elseif ($p['tipo'] === 'opciones'): foreach (opciones_de($p['opciones']) as $op): ?><label><input type="radio" name="<?= $n ?>" value="<?= e($op) ?>" required> <?= e($op) ?></label><?php endforeach ?>
    <?php else: ?><textarea name="<?= $n ?>" rows="3" maxlength="3000"></textarea><?php endif ?>
  </fieldset>
  <?php endforeach ?>
  <button>Enviar respuesta</button>
</form>
<?php endforeach;
if (!$pend): ?><p class="aviso ok"><?= icono('ok') ?><span>No tienes encuestas ni evaluaciones pendientes.</span></p><?php endif;

if ($gestor):
    // Resultados
    if ($res = encuesta(db(), (int)($_GET['res'] ?? 0))):
        $sobre = (int)($_GET['sobre'] ?? 0) ?: null;
        $pa = participacion(db(), (int)$res['id']); ?>
<section class="tarjeta">
  <h2>Resultados · <?= e($res['titulo']) ?></h2>
  <p class="nota"><?= $res['tipo'] === 'evaluacion' ? 'Evaluación' : 'Encuesta' ?><?= $res['anonima'] ? ' anónima' : '' ?> · respondidas <?= (int)$pa['hechas'] ?> de <?= (int)$pa['total'] ?><?= $res['activa'] ? '' : ' · cerrada' ?></p>
  <?php if ($res['tipo'] === 'evaluacion'): $ev = consulta(db(), 'SELECT DISTINCT sobre_id FROM encuesta_asignaciones WHERE encuesta_id = ?', [$res['id']])->fetchAll(PDO::FETCH_COLUMN); ?>
  <form class="filtros"><input type="hidden" name="p" value="encuestas"><input type="hidden" name="res" value="<?= e($res['id']) ?>">
    <label>Persona <select name="sobre" onchange="this.form.submit()"><option value="">Todas (media)</option><?php foreach ($ev as $sid): $sp = usuario(db(), (int)$sid); ?><option value="<?= e($sid) ?>" <?= $sobre == $sid ? 'selected' : '' ?>><?= e(nombre($sp)) ?></option><?php endforeach ?></select></label></form>
  <?php endif ?>
  <?php foreach (resultados_encuesta(db(), (int)$res['id'], $sobre) as $x): $p = $x['p']; $tot = array_sum($x['cuentas']); ?>
  <h3><?= e($p['texto']) ?></h3>
  <?php if ($p['tipo'] === 'escala'): ?><p class="cifra-grande"><?= $x['media'] !== null ? number_format($x['media'], 1, ',', '') : '–' ?> <small>de 5 · <?= $x['n'] ?> respuestas</small></p><?php endif ?>
  <?php if ($p['tipo'] !== 'texto'): $vals = $p['tipo'] === 'escala' ? ['1', '2', '3', '4', '5'] : ($p['tipo'] === 'si_no' ? ['si', 'no'] : opciones_de($p['opciones'])); ?>
  <div class="barras-res"><?php foreach ($vals as $v): $c = $x['cuentas'][$v] ?? 0; ?><div><span><?= e(['si' => 'Sí', 'no' => 'No'][$v] ?? $v) ?></span><i style="--w:<?= $tot ? round(100 * $c / $tot) : 0 ?>%"></i><b><?= $c ?></b></div><?php endforeach ?></div>
  <?php else: ?><?php foreach ($x['textos'] as $t): ?><blockquote><?= nl2br(e($t)) ?></blockquote><?php endforeach ?><?= $x['textos'] ? '' : '<p class="nota">Sin comentarios.</p>' ?><?php endif ?>
  <?php endforeach ?>
  <?php if ($res['activa']): ?><form method="post"><?= csrf_campo() ?><button name="cerrar" value="<?= e($res['id']) ?>" class="secundario" data-confirmar="¿Cerrar? Ya no se podrá responder.">Cerrar</button></form><?php endif ?>
</section>
<?php endif ?>

<h2>Encuestas y evaluaciones enviadas</h2>
<?php $mias = consulta(db(), 'SELECT * FROM encuestas' . ($u['rol'] === 'admin' ? '' : ' WHERE autor_id = ' . (int)$u['id']) . ' ORDER BY id DESC LIMIT 50')->fetchAll(); ?>
<?php if (!$mias): ?><p class="nota">Todavía ninguna.</p><?php else: ?>
<table><tr><th>Título</th><th>Tipo</th><th>Respondidas</th><th>Estado</th></tr>
  <?php foreach ($mias as $m): $pa = participacion(db(), (int)$m['id']); ?>
  <tr><td><a href="?p=encuestas&res=<?= e($m['id']) ?>"><?= e($m['titulo']) ?></a></td><td><?= $m['tipo'] === 'evaluacion' ? 'Evaluación' : 'Encuesta' . ($m['anonima'] ? ' anónima' : '') ?></td><td><?= (int)$pa['hechas'] ?> / <?= (int)$pa['total'] ?></td><td><?= $m['activa'] ? '<span class="pildora ok">Abierta</span>' : '<span class="pildora">Cerrada</span>' ?></td></tr>
  <?php endforeach ?>
</table>
<?php endif ?>

<form method="post" class="tarjeta" data-encuesta-nueva>
  <?= csrf_campo() ?><input type="hidden" name="que" value="crear">
  <h2>Crear encuesta o evaluación</h2>
  <div class="filtros">
    <label>Título <input name="titulo" required maxlength="150" placeholder="Clima laboral 2027"></label>
    <label>Tipo <select name="tipo"><option value="encuesta">Encuesta (responde cada persona)</option><option value="evaluacion">Evaluación del desempeño (responde su responsable)</option></select></label>
    <label>Cierra el <input type="date" name="cierra"></label>
  </div>
  <label>Explicación <textarea name="descripcion" rows="2"></textarea></label>
  <label><input type="checkbox" name="anonima" checked> Anónima (solo encuestas: no se guarda quién respondió qué)</label>
  <h3>Preguntas</h3>
  <?php for ($i = 0; $i < 10; $i++): ?>
  <div class="filtros pregunta-nueva"<?= $i >= 3 ? ' hidden' : '' ?>>
    <label><?= $i + 1 ?>. <input name="pregunta[<?= $i ?>]" maxlength="300"<?= $i === 0 ? ' required' : '' ?>></label>
    <label>Tipo <select name="tipo_p[<?= $i ?>]"><?php foreach (TIPOS_PREGUNTA as $k => $t): ?><option value="<?= $k ?>"><?= $t ?></option><?php endforeach ?></select></label>
    <label>Opciones (si es «Opciones», una por línea o separadas por ;) <input name="opciones[<?= $i ?>]" placeholder="Mañana; Tarde; Noche"></label>
  </div>
  <?php endfor ?>
  <button type="button" class="secundario pequeno" onclick="const h=this.form.querySelector('.pregunta-nueva[hidden]');if(h)h.hidden=false;else this.remove()">+ Otra pregunta</button>
  <h3>A quién</h3>
  <label><input type="radio" name="para" value="todos" checked> Todo mi equipo</label>
  <label><input type="radio" name="para" value="elegir"> Elegir personas:</label>
  <details><summary>Personas</summary><?php foreach (array_filter(equipo_de(db(), $u), fn($p) => $p['activo']) as $p): ?><label><input type="checkbox" name="personas[]" value="<?= e($p['id']) ?>"> <?= e(nombre($p)) ?></label><?php endforeach ?></details>
  <button>Crear y enviar</button>
</form>
<?php endif;
pie();
