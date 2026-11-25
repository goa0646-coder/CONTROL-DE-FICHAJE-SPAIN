<?php
// Asistente (Gemini) para administración: preguntas sobre fichajes, horas, ausencias y ratios. Solo lectura.
$yo = requiere('admin');
$hist = $_SESSION['chat_ia'] ?? [];
if (es_post()) {
    if (isset($_POST['borrar'])) {
        unset($_SESSION['chat_ia']);
        redirigir('preguntar');
    }
    $q = mb_substr(trim($_POST['pregunta'] ?? ''), 0, 500);
    if ($q !== '') {
        try {
            [$r, $hechas] = preguntar_ia(db(), $yo, $q, array_map(fn($x) => [$x[0], $x[1]], $hist));
            $hist[] = ['user', $q, []];
            $hist[] = ['model', $r, $hechas];
            auditar(db(), (int)$yo['id'], 'asistente_ia', mb_substr($q, 0, 200) . ' · ' . implode(' | ', array_map(fn($x) => strtok($x, ' '), $hechas)));
        } catch (DomainException $e) {
            aviso($e->getMessage(), 'error');
        }
        $_SESSION['chat_ia'] = array_slice($hist, -20);
    }
    redirigir('preguntar');
}
$ejemplos = ['¿Quién fichó mal esta semana?', '¿Quién trabajó todos los domingos de este mes?', '¿Cuántas horas ha trabajado cada auxiliar este mes?', '¿Quién está trabajando ahora?', '¿Qué días de la semana que viene no se cumple el ratio?', '¿Quién está de vacaciones en agosto?', '¿Qué solicitudes tengo pendientes?'];
// Texto de la respuesta: escapado, con **negrita** y saltos de línea.
$formato = fn(string $t) => nl2br(preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', e($t)));
cabecera('Asistente', $yo);
?>
<?php if (!ia_activa()): ?><p class="aviso error"><?= icono('alerta') ?><span>Falta la clave de Gemini en <code>config-inda.php</code> («gemini_clave»). Sin ella el asistente no puede responder.</span></p><?php endif ?>
<p class="nota">Pregunta en lenguaje normal. El asistente solo consulta (no cambia nada) y responde con los datos de INDA: fichajes, horas, ausencias, ratios y solicitudes. A Google solo se envían nombres y cifras, nunca DNI ni emails.</p>
<section class="tarjeta chat-ia">
  <?php if (!$hist): ?><p class="nota">Por ejemplo:</p><div class="ejemplos-ia"><?php foreach ($ejemplos as $x): ?><button type="button" class="secundario pequeno" data-ejemplo><?= e($x) ?></button><?php endforeach ?></div><?php endif ?>
  <?php foreach ($hist as [$rol, $t, $hechas]): ?>
  <div class="msg-ia <?= $rol === 'user' ? 'yo' : 'ia' ?>"><?= $rol === 'user' ? e($t) : $formato($t) ?>
    <?php if ($hechas): ?><details><summary>Consultas hechas (<?= count($hechas) ?>)</summary><small><?= e(implode("\n", $hechas)) ?></small></details><?php endif ?></div>
  <?php endforeach ?>
  <form method="post" class="filtros preguntar-ia"><?= csrf_campo() ?>
    <label><input name="pregunta" maxlength="500" placeholder="Escribe tu pregunta…" required autofocus></label>
    <button>Preguntar</button>
    <?php if ($hist): ?><button name="borrar" value="1" class="secundario" formnovalidate>Nueva conversación</button><?php endif ?>
  </form>
</section>
<p class="nota pensando" hidden>Pensando… (Gemini consulta los datos de INDA y redacta la respuesta; suele tardar entre 3 y 10 segundos)</p>
<script>
// Ejemplos y aviso mientras responde (el botón se desactiva para no enviar dos veces).
const fp = document.querySelector('.preguntar-ia');
fp.addEventListener('submit', ev => { if (ev.submitter && ev.submitter.name === 'borrar') return; document.querySelector('.pensando').hidden = false; for (const b of fp.querySelectorAll('button')) b.disabled = true; });
for (const b of document.querySelectorAll('[data-ejemplo]')) b.addEventListener('click', () => { fp.querySelector('[name=pregunta]').value = b.textContent; fp.requestSubmit(); });
</script>
<?php pie();
