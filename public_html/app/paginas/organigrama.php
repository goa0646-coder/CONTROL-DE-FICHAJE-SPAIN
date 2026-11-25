<?php
// Organigrama: quién depende de quién (responsables) y el resto agrupado por departamento y puesto.
// Solo nombre, puesto y departamento; las fotos, solo de quien ya puedes ver.
$yo = requiere(...ROLES);
$gente = db()->query("SELECT * FROM usuarios WHERE activo = 1 AND rol <> 'inspeccion' ORDER BY nombre, apellidos")->fetchAll();
$deps = db()->query('SELECT id, nombre FROM departamentos')->fetchAll(PDO::FETCH_KEY_PAIR);
$pts = puestos(db());
$pp = puestos_por_persona(db());
$por_id = array_column($gente, null, 'id');
$hijos = [];
foreach ($gente as $p) if ($p['responsable_id'] && isset($por_id[$p['responsable_id']])) $hijos[$p['responsable_id']][] = $p;
$cargo = fn($p) => implode(', ', array_intersect_key($pts, array_flip($pp[$p['id']] ?? []))) ?: ($deps[$p['departamento_id']] ?? '');
$ficha = function (array $p) use ($yo, $cargo, $hijos) {
    if (!puede_ver($yo, $p)) $p['foto'] = null;
    $n = count($hijos[$p['id']] ?? []);
    return '<div class="org-ficha' . ($p['id'] == $yo['id'] ? ' yo' : '') . '">' . avatar($p) . '<span><b>' . e(nombre($p)) . '</b><small>' . e($cargo($p) ?: ucfirst($p['rol'])) . '</small>'
        . ($n ? '<small class="org-n">' . $n . ' ' . ($n === 1 ? 'persona' : 'personas') . ' a cargo</small>' : '') . '</span></div>';
};
$arbol = function (array $p) use (&$arbol, $hijos, $ficha) {
    $h = $hijos[$p['id']] ?? [];
    return '<li>' . $ficha($p) . ($h ? '<ul>' . implode('', array_map($arbol, $h)) . '</ul>' : '') . '</li>';
};
// Arriba: quien no tiene responsable y tiene gente a su cargo (o es administración/responsable).
$raices = array_filter($gente, fn($p) => !($p['responsable_id'] && isset($por_id[$p['responsable_id']])) && (isset($hijos[$p['id']]) || in_array($p['rol'], ['admin', 'responsable'], true)));
$sueltos = array_filter($gente, fn($p) => !($p['responsable_id'] && isset($por_id[$p['responsable_id']])) && !isset($hijos[$p['id']]) && !in_array($p['rol'], ['admin', 'responsable'], true));
$grupos = [];
foreach ($sueltos as $p) $grupos[$deps[$p['departamento_id']] ?? 'Sin departamento'][$cargo($p) ?: 'Sin puesto'][] = $p;
ksort($grupos);
cabecera('Organigrama', $yo);
?>
<p class="nota"><?= count($gente) ?> personas. Para cambiar quién depende de quién, edita el «Responsable» en la ficha de cada persona.</p>
<?php if ($raices): ?>
<section class="tarjeta org">
  <h2>Dirección y responsables</h2>
  <ul class="org-arbol"><?= implode('', array_map($arbol, $raices)) ?></ul>
</section>
<?php endif ?>
<div class="org-deps">
<?php foreach ($grupos as $dep => $puestos_dep): ksort($puestos_dep); ?>
<section class="tarjeta org">
  <h2><?= e($dep) ?> <small><?= array_sum(array_map('count', $puestos_dep)) ?></small></h2>
  <?php foreach ($puestos_dep as $pu => $ps): ?>
  <h3><?= e($pu) ?> <small><?= count($ps) ?></small></h3>
  <div class="org-lista"><?= implode('', array_map($ficha, $ps)) ?></div>
  <?php endforeach ?>
</section>
<?php endforeach ?>
</div>
<?php pie();
