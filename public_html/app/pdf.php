<?php
// PDF mínimo sin dependencias (A4, Helvetica, texto con saltos de línea y una imagen JPEG). Lo usa el certificado de acuse.
// $bloques: [['t' => texto, 's' => tamaño, 'b' => negrita], ['img' => n]] — la imagen n de $jpegs (JPEG) se pinta donde va su bloque.
function pdf_documento(array $bloques, array $jpegs = []): string
{
    $txt = fn(string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string)iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s));
    $tam = [];
    foreach ($jpegs as $n => $j) if ($j !== '' && ($t = getimagesizefromstring($j))) $tam[$n] = [$t[0], $t[1]];
    $paginas = [];
    $c = '';
    $y = 800;
    $salto = function (float $alto) use (&$c, &$y, &$paginas) {
        if ($y - $alto < 50) { $paginas[] = $c; $c = ''; $y = 800; }
    };
    foreach ($bloques as $b) {
        if (array_key_exists('img', $b)) {
            if (!isset($tam[$b['img']])) continue;
            [$jw, $jh] = $tam[$b['img']];
            $w = 220; $h = round($jh * $w / $jw, 1);
            $salto($h + 10);
            $y -= $h;
            $c .= "q $w 0 0 $h 50 $y cm /Im{$b['img']} Do Q\n";
            $y -= 10;
            continue;
        }
        $s = $b['s'] ?? 10;
        $max = (int)floor(495 / ($s * 0.52));
        foreach (preg_split('/\R/u', (string)$b['t']) as $parrafo) {
            $lineas = $parrafo === '' ? [''] : explode("\n", wordwrap($parrafo, $max, "\n"));
            foreach ($lineas as $l) {
                $salto($s * 1.4);
                $y -= $s * 1.4;
                $c .= 'BT /' . (!empty($b['b']) ? 'F2' : 'F1') . " $s Tf 50 $y Td (" . $txt($l) . ") Tj ET\n";
            }
        }
        $y -= $s * 0.6;
    }
    $paginas[] = $c;
    // Objetos: 1 catálogo, 2 páginas, 3-4 fuentes, 5… imágenes, y luego página + contenido por cada página.
    $obj = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
    $xo = '';
    $k = 5;
    foreach ($tam as $n => [$jw, $jh]) {
        $obj[$k] = "<< /Type /XObject /Subtype /Image /Width $jw /Height $jh /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpegs[$n]) . " >>\nstream\n{$jpegs[$n]}\nendstream";
        $xo .= " /Im$n $k 0 R";
        $k++;
    }
    $kids = [];
    foreach ($paginas as $i => $contenido) {
        $p = $k + 2 * $i;
        $kids[] = "$p 0 R";
        $obj[$p] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>' . ($xo ? " /XObject <<$xo >>" : '') . ' >> /Contents ' . ($p + 1) . ' 0 R >>';
        $obj[$p + 1] = '<< /Length ' . strlen($contenido) . " >>\nstream\n$contenido\nendstream";
    }
    $obj[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($obj);
    $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $pos = [];
    foreach ($obj as $n => $o) { $pos[$n] = strlen($out); $out .= "$n 0 obj\n$o\nendobj\n"; }
    $xref = strlen($out);
    $out .= "xref\n0 " . (count($obj) + 1) . "\n0000000000 65535 f \n";
    foreach ($pos as $p) $out .= sprintf("%010d 00000 n \n", $p);
    return $out . 'trailer << /Size ' . (count($obj) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}
