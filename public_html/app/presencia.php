<?php
// Presencia diaria: por persona, lo previsto y lo fichado ese día.

function presencia_dia(PDO $db, array $personas, string $fecha): array
{
    $out = [];
    $ini = a_utc("$fecha 00:00:00");
    $fin = a_utc(date('Y-m-d', strtotime("$fecha +1 day")) . ' 00:00:00');
    foreach ($personas as $p) {
        $c = calendario_teorico($db, $p, $fecha, $fecha)[$fecha];
        // Jornadas que empiezan ese día (una noche que acaba al día siguiente cuenta en el día que empezó).
        $js = array_values(array_filter(jornadas(fichajes_de($db, (int)$p['id'], a_utc(date('Y-m-d', strtotime("$fecha -1 day")) . ' 00:00:00'), a_utc(date('Y-m-d', strtotime("$fecha +2 days")) . ' 00:00:00'))),
            fn($j) => $j['entrada'] >= $ini && $j['entrada'] < $fin));
        $trab = 0;
        $pausas = 0;
        foreach ($js as $j) {
            $trab += $j['trabajado'] + (!empty($j['en_curso']) && $fecha === local(ahora(), 'Y-m-d') ? time() - $j['en_curso'] : 0);
            $pausas += $j['pausas'];
        }
        $teorico = $c['ausencia'] || $c['festivo'] ? 0 : $c['minutos'] * 60;
        $jornada = $c['ausencia'] ? $c['ausencia']['tipo'] : ($c['festivo'] ? 'Festivo: ' . $c['festivo'] : ($c['minutos'] ? ($c['horario'] ?: 'Laborable') : 'Descanso'));
        $out[] = ['persona' => $p, 'jornada' => $jornada, 'ausencia' => (bool)$c['ausencia'], 'horario_entrada' => $c['entrada'] ?? null,
            'entrada' => $js[0]['entrada'] ?? null, 'salida' => $js ? end($js)['salida'] : null, 'abierta' => $js && end($js)['salida'] === null,
            'pausas' => $pausas, 'teorico' => $teorico, 'trabajado' => $trab, 'diferencia' => $trab - $teorico];
    }
    return $out;
}
