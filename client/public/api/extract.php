<?php
/**
 * Plain-text extraction from uploaded files. PHP 5.3 compatible.
 * - text/markdown/code/config  → read directly
 * - .docx                       → pure-PHP via ZipArchive (word/document.xml)
 * - .pdf                        → pdftotext if installed on the server, else null
 * Returns the extracted text, or null to signal "skip this file".
 */

function extract_text($tmpPath, $originalName) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $textExts = array(
        'md','txt','json','yml','yaml','env','ini','cfg','csv',
        'js','jsx','ts','tsx','mjs','cjs',
        'py','rb','php','java','go','rs','c','cpp','h','cs',
        'html','css','scss','sql','sh','xml','vue','svelte',
    );

    if ($ext === 'docx') { return extract_docx($tmpPath); }
    if ($ext === 'xlsx') { return extract_xlsx($tmpPath); }
    if ($ext === 'pdf')  { return extract_pdf($tmpPath); }
    if (in_array($ext, $textExts) || $ext === '') {
        $c = @file_get_contents($tmpPath);
        return ($c === false) ? null : $c;
    }
    return null;
}

/** Return the image MIME type for a filename, or null if it isn't an image. */
function image_mime($name) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = array(
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
    );
    return isset($map[$ext]) ? $map[$ext] : null;
}

/** MIME for files we send to the AI as inline_data (read natively, not text-
 *  extracted): images + PDF. Gemini reads PDFs directly, so no pdftotext needed. */
function inline_doc_mime($name) {
    $img = image_mime($name);
    if ($img !== null) { return $img; }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'pdf') { return 'application/pdf'; }
    return null;
}

/** True for mime types we attach to the AI as inline_data (images + PDF). */
function is_inline_doc_mime($mime) {
    return $mime && (strpos($mime, 'image/') === 0 || $mime === 'application/pdf');
}

/** Extract sheet text from an .xlsx (pure PHP via ZipArchive). Rows tab-separated. */
function extract_xlsx($path) {
    if (!class_exists('ZipArchive')) { return null; }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { return null; }

    // Shared strings table.
    $shared = array();
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false && preg_match_all('/<si>(.*?)<\/si>/s', $ss, $sm)) {
        foreach ($sm[1] as $si) {
            $t = '';
            if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm)) {
                foreach ($tm[1] as $piece) { $t .= $piece; }
            }
            $shared[] = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
        }
    }

    $out = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (strpos($name, 'xl/worksheets/sheet') !== 0) { continue; }
        $xml = $zip->getFromName($name);
        if ($xml === false) { continue; }
        $out .= "\n# " . basename($name) . "\n";
        if (preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $xml, $rm)) {
            foreach ($rm[1] as $row) {
                $cells = array();
                if (preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $row, $cm, PREG_SET_ORDER)) {
                    foreach ($cm as $c) {
                        $attrs = $c[1];
                        $inner = isset($c[2]) ? $c[2] : '';
                        $val = '';
                        if (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) { $val = $vm[1]; }
                        if (preg_match('/\bt="s"/', $attrs) && $val !== '') {
                            $idx = (int) $val;
                            $val = isset($shared[$idx]) ? $shared[$idx] : '';
                        } else if (preg_match('/\bt="inlineStr"/', $attrs)) {
                            if (preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $im)) { $val = $im[1]; }
                        } else {
                            $val = html_entity_decode($val, ENT_QUOTES, 'UTF-8');
                        }
                        $cells[] = $val;
                    }
                }
                $out .= implode("\t", $cells) . "\n";
            }
        }
    }
    $zip->close();
    return trim($out);
}

function extract_docx($path) {
    if (!class_exists('ZipArchive')) { return null; }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { return null; }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) { return null; }

    // Preserve paragraph / line breaks, then strip the remaining XML tags.
    $xml = preg_replace('/<\/w:p>/', "\n", $xml);
    $xml = preg_replace('/<w:br[^>]*\/>/', "\n", $xml);
    $xml = preg_replace('/<w:tab[^>]*\/>/', "\t", $xml);
    $text = strip_tags($xml);
    return html_entity_decode($text, ENT_QUOTES, 'UTF-8');
}

function extract_pdf($path) {
    // PHP 5.3 has no usable PDF library; shell out to pdftotext if present.
    $bin = trim(@shell_exec('which pdftotext 2>/dev/null'));
    if ($bin !== '') {
        $out = @shell_exec('pdftotext -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
        if ($out !== null && trim($out) !== '') { return $out; }
    }
    return null; // not extractable on this server
}
?>
