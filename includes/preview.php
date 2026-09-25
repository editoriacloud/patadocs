<?php
/**
 * PATADOCS — protected preview generation.
 *
 * The original file is NEVER exposed. Previews are separate, low-quality, page-limited JPEG images
 * with the watermark burned into the pixels (not a CSS overlay), stored in uploads/previews/<random>/pN.jpg.
 *
 * How pages are rasterised (first method that works on the server is used):
 *   PDF   → pdftoppm (poppler)  →  Imagick (+Ghostscript)  →  Ghostscript CLI
 *   DOCX  → LibreOffice → PDF → (as above)   →  text-only fallback rendered with GD
 *   DOC   → LibreOffice → PDF → (as above)   →  otherwise upload preview images manually
 *   Image → resized + watermarked with GD
 * Shared hosting often lacks poppler/LibreOffice — then the admin can attach preview images by hand
 * (Upload → Preview step); they are watermarked the same way.
 */

function preview_settings(): array
{
    return [
        'pages'   => max(1, min(20, (int)setting('default_preview_pages', 3))),
        'quality' => max(30, min(90, (int)setting('preview_quality', 60))),
        'opacity' => max(5, min(90, (int)setting('preview_opacity', 35))),
        'width'   => max(400, min(1600, (int)setting('preview_width', 900))),
        'text'    => trim((string)setting('preview_watermark', 'PATADOCS PREVIEW')) ?: 'PATADOCS PREVIEW',
        'text2'   => trim((string)setting('preview_watermark2', 'PREVIEW ONLY — NOT FOR DISTRIBUTION')),
    ];
}

function preview_font(bool $bold = true): string
{
    if (!function_exists('imagettftext')) { return ''; }
    foreach ($bold ? ['DejaVuSans-Bold.ttf', 'DejaVuSans.ttf'] : ['DejaVuSans.ttf', 'DejaVuSans-Bold.ttf'] as $f) {
        $p = ROOT_DIR . '/assets/fonts/' . $f;
        if (is_file($p)) { return $p; }
    }
    return '';
}

function preview_exec_ok(): bool
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return function_exists('exec') && !in_array('exec', $disabled, true);
}
function preview_which(string $bin): string
{
    if (!preview_exec_ok()) { return ''; }
    $out = []; @exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $out);
    return isset($out[0]) && $out[0] !== '' ? $out[0] : '';
}
/** Runs a command with an optional `timeout` prefix. Returns the exit code. */
function preview_run(string $cmd, int $timeout = 60): int
{
    static $to = null;
    if ($to === null) { $to = preview_which('timeout'); }
    $out = []; $rc = 1;
    @exec(($to ? escapeshellarg($to) . ' ' . (int)$timeout . ' ' : '') . $cmd . ' 2>&1', $out, $rc);
    return (int)$rc;
}
function preview_rrmdir(string $dir): void
{
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $p = $dir . '/' . $f;
        is_dir($p) ? preview_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
/** Deletes a previews/<dir> folder (name is validated so nothing outside previews/ can be touched). */
function preview_delete_dir(?string $name): void
{
    if ($name && preg_match('/^[a-z]\d+-[a-f0-9]{12}$/', $name)) { preview_rrmdir(rtrim(UPLOAD_DIR, '/\\') . '/previews/' . $name); }
}

/** GD's built-in bitmap fonts only know Latin-1: convert UTF-8 text (used only when no TrueType font is available). */
function preview_latin1(string $s): string
{
    $s = strtr($s, ["\xC2\xA0" => ' ', '—' => '-', '–' => '-', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '…' => '...', '•' => '*', '·' => '-']);
    if (function_exists('iconv') && ($r = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s)) !== false) { return $r; }
    return function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8') : $s;
}

// ---------------------------------------------------------------------------
// Rasterisers
// ---------------------------------------------------------------------------

/** Number of pages in a PDF (best effort). */
function preview_pdf_pages(string $pdf): ?int
{
    if (($bin = preview_which('pdfinfo')) !== '') {
        $out = []; @exec(escapeshellarg($bin) . ' ' . escapeshellarg($pdf) . ' 2>/dev/null', $out);
        foreach ($out as $l) { if (preg_match('/^Pages:\s+(\d+)/', $l, $m)) { return (int)$m[1]; } }
    }
    if (class_exists('Imagick')) {
        try { $im = new Imagick(); $im->pingImage($pdf); $n = $im->getNumberImages(); $im->clear(); if ($n > 0) { return (int)$n; } } catch (Throwable $e) { }
    }
    $data = @file_get_contents($pdf, false, null, 0, 20 * 1048576);
    if ($data !== false && preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $data, $m) && count($m[0]) > 0) { return count($m[0]); }
    return null;
}

/** Renders the first $limit pages of a PDF to JPEG files in $tmp. Returns the file list (possibly empty). */
function preview_pdf_to_images(string $pdf, int $limit, string $tmp): array
{
    $files = [];
    if (($bin = preview_which('pdftoppm')) !== '') {
        preview_run(escapeshellarg($bin) . ' -jpeg -r 100 -f 1 -l ' . (int)$limit . ' ' . escapeshellarg($pdf) . ' ' . escapeshellarg($tmp . '/pg'), 90);
        $files = glob($tmp . '/pg-*.jpg') ?: [];
    }
    if (!$files && class_exists('Imagick')) {
        try {
            for ($i = 0; $i < $limit; $i++) {
                $im = new Imagick(); $im->setResolution(100, 100); $im->readImage($pdf . '[' . $i . ']');
                $im->setImageBackgroundColor('white'); $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $im->setImageFormat('jpeg'); $f = $tmp . '/im-' . ($i + 1) . '.jpg'; $im->writeImage($f); $im->clear(); $files[] = $f;
            }
        } catch (Throwable $e) { /* fewer pages than $limit, or no Ghostscript → keep what we have */ }
    }
    if (!$files && ($bin = preview_which('gs')) !== '') {
        preview_run(escapeshellarg($bin) . ' -dQUIET -dNOPAUSE -dBATCH -sDEVICE=jpeg -dJPEGQ=80 -r100 -dFirstPage=1 -dLastPage=' . (int)$limit . ' -sOutputFile=' . escapeshellarg($tmp . '/gs-%d.jpg') . ' ' . escapeshellarg($pdf), 90);
        $files = glob($tmp . '/gs-*.jpg') ?: [];
    }
    natsort($files);
    return array_values($files);
}

/** DOC/DOCX → PDF using LibreOffice when available. Returns the PDF path or ''. */
function preview_office_to_pdf(string $src, string $tmp): string
{
    $bin = '';
    foreach (['soffice', 'libreoffice'] as $b) { if (($bin = preview_which($b)) !== '') { break; } }
    if ($bin === '') { return ''; }
    $copy = $tmp . '/src.' . strtolower(pathinfo($src, PATHINFO_EXTENSION));
    if (!@copy($src, $copy)) { return ''; }
    @mkdir($tmp . '/lo', 0755, true);
    preview_run('env HOME=' . escapeshellarg($tmp) . ' ' . escapeshellarg($bin) . ' --headless --norestore -env:UserInstallation=file://' . $tmp . '/lo --convert-to pdf --outdir ' . escapeshellarg($tmp) . ' ' . escapeshellarg($copy), 120);
    return is_file($tmp . '/src.pdf') ? $tmp . '/src.pdf' : '';
}

/** Fallback for .docx without LibreOffice: renders the document TEXT onto A4-like pages. */
function preview_docx_text_images(string $docx, int $limit, string $tmp): array
{
    if (!class_exists('ZipArchive') || !function_exists('imagecreatetruecolor')) { return []; }
    $z = new ZipArchive();
    if ($z->open($docx) !== true) { return []; }
    $xml = $z->getFromName('word/document.xml'); $z->close();
    if ($xml === false) { return []; }
    $xml = preg_replace(['/<w:tab\s*\/>/', '/<w:br[^>]*\/>/', '/<\/w:p>/'], ['    ', "\n", "\n"], $xml);
    $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    $lines = []; $blank = 0;
    foreach (preg_split('/\R/u', $text) as $para) {
        $para = trim($para);
        if ($para === '') { if (++$blank <= 1) { $lines[] = ''; } continue; }
        $blank = 0;
        foreach (explode("\n", wordwrap($para, 78, "\n", true)) as $l) { $lines[] = $l; }
    }
    if (!$lines) { return []; }
    $font = preview_font(false);
    $W = 794; $H = 1123; $mx = 70; $my = 80; $lh = 21; $per = (int)floor(($H - 2 * $my) / $lh);
    $files = [];
    foreach (array_slice(array_chunk($lines, $per), 0, $limit) as $n => $chunk) {
        $im = imagecreatetruecolor($W, $H);
        $white = imagecolorallocate($im, 255, 255, 255); $ink = imagecolorallocate($im, 34, 34, 34); $grey = imagecolorallocate($im, 140, 140, 140);
        imagefilledrectangle($im, 0, 0, $W, $H, $white);
        $y = $my;
        foreach ($chunk as $l) {
            if ($l !== '') { $font ? imagettftext($im, 10.5, 0, $mx, $y, $ink, $font, $l) : imagestring($im, 3, $mx, $y - 12, preview_latin1($l), $ink); }
            $y += $lh;
        }
        $note = 'Text preview - original layout and images are not shown';
        $font ? imagettftext($im, 8, 0, $mx, 44, $grey, $font, $note) : imagestring($im, 2, $mx, 32, $note, $grey);
        $f = $tmp . '/tp' . ($n + 1) . '.png'; imagepng($im, $f); imagedestroy($im); $files[] = $f;
    }
    return $files;
}

// ---------------------------------------------------------------------------
// Watermark (burned into the image)
// ---------------------------------------------------------------------------

function preview_apply_watermark($im, int $w, int $h, array $s): void
{
    imagealphablending($im, true);
    $alpha = (int)round(127 - ($s['opacity'] / 100) * 127);        // GD: 0 = opaque … 127 = transparent
    $navy = imagecolorallocatealpha($im, 0, 0, 128, $alpha);
    $font = preview_font(true);
    $text = $s['text'];
    if ($font) {
        $size = max(14, (int)round($w / 19)); $angle = 30;
        $b = imagettfbbox($size, 0, $font, $text); $tw = abs($b[2] - $b[0]);
        $rad = deg2rad($angle); $c = cos($rad); $sn = sin($rad);
        $ax = ($tw + $size * 1.6) * $c;  $ay = -($tw + $size * 1.6) * $sn;   // step along the text direction
        $bx = $size * 4.2 * $sn;         $by = $size * 4.2 * $c;             // step between text lines (perpendicular)
        for ($i = -14; $i <= 14; $i++) {
            for ($j = -14; $j <= 14; $j++) {
                $x = $w / 2 + $i * $ax + $j * $bx; $y = $h / 2 + $i * $ay + $j * $by;
                if ($x < -$tw || $x > $w + $tw || $y < -$size * 2 || $y > $h + $tw) { continue; }
                imagettftext($im, $size, $angle, (int)$x, (int)$y, $navy, $font, $text);
            }
        }
    } else {                                                          // no FreeType: enlarge GD's bitmap font
        $text = preview_latin1($text);
        $cw = imagefontwidth(5) * strlen($text); $ch = imagefontheight(5); $sc = max(2, (int)round($w / 260));
        $t = imagecreatetruecolor($cw, $ch); imagealphablending($t, false); imagesavealpha($t, true);
        imagefill($t, 0, 0, imagecolorallocatealpha($t, 0, 0, 0, 127)); imagestring($t, 5, 0, 0, $text, imagecolorallocatealpha($t, 0, 0, 128, $alpha));
        $big = imagecreatetruecolor($cw * $sc, $ch * $sc); imagealphablending($big, false); imagesavealpha($big, true);
        imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127)); imagecopyresized($big, $t, 0, 0, 0, 0, $cw * $sc, $ch * $sc, $cw, $ch);
        $rot = imagerotate($big, 30, imagecolorallocatealpha($big, 0, 0, 0, 127)); imagealphablending($rot, false); imagesavealpha($rot, true);
        $rw = imagesx($rot); $rh = imagesy($rot);
        for ($y = -$rh; $y < $h; $y += (int)($rh * 1.1)) { for ($x = -$rw; $x < $w; $x += (int)($rw * 1.05)) { imagecopy($im, $rot, $x, $y, 0, 0, $rw, $rh); } }
        imagedestroy($t); imagedestroy($big); imagedestroy($rot);
    }
    // Footer strip with the second line
    if ($s['text2'] !== '') {
        $bh = max(26, (int)round($h * 0.045));
        imagefilledrectangle($im, 0, $h - $bh, $w, $h, imagecolorallocatealpha($im, 0, 0, 90, 45));
        $white = imagecolorallocate($im, 255, 255, 255);
        if ($font) {
            $fs = max(9, (int)round($bh * 0.42)); $b = imagettfbbox($fs, 0, $font, $s['text2']); $tw = abs($b[2] - $b[0]);
            imagettftext($im, $fs, 0, max(4, (int)(($w - $tw) / 2)), $h - (int)(($bh - $fs) / 2) - 2, $white, $font, $s['text2']);
        } else { $t2 = preview_latin1($s['text2']); imagestring($im, 3, max(4, (int)(($w - imagefontwidth(3) * strlen($t2)) / 2)), $h - $bh + 6, $t2, $white); }
    }
}

/** Resizes (never upscales), flattens transparency, watermarks and saves as a low-quality JPEG. */
function preview_watermark_image(string $src, string $dest, ?array $s = null): bool
{
    $s = $s ?: preview_settings();
    $info = @getimagesize($src);
    if (!$info || $info[0] * $info[1] > 40000000) { return false; }
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg($src); break;
        case IMAGETYPE_PNG:  $im = @imagecreatefrompng($src); break;
        case IMAGETYPE_WEBP: $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false; break;
        default: $im = false;
    }
    if (!$im) { return false; }
    $w = imagesx($im); $h = imagesy($im);
    if ($w > $s['width']) { $nh = (int)round($h * $s['width'] / $w); $r = imagescale($im, $s['width'], $nh, IMG_BILINEAR_FIXED); imagedestroy($im); $im = $r; $w = $s['width']; $h = $nh; }
    $canvas = imagecreatetruecolor($w, $h);
    imagefilledrectangle($canvas, 0, 0, $w, $h, imagecolorallocate($canvas, 255, 255, 255));
    imagecopy($canvas, $im, 0, 0, 0, 0, $w, $h); imagedestroy($im);
    preview_apply_watermark($canvas, $w, $h, $s);
    imageinterlace($canvas, true);
    $ok = imagejpeg($canvas, $dest, $s['quality']);
    imagedestroy($canvas);
    if ($ok) { @chmod($dest, 0644); }
    return (bool)$ok;
}

// ---------------------------------------------------------------------------
// Main entry point
// ---------------------------------------------------------------------------

/**
 * Generates (or regenerates) the protected preview of a document.
 * $manual = list of already-validated image file paths to use instead of auto-rendering.
 * Returns ['ok' => bool, 'pages' => int, 'message' => string].
 */
function preview_generate(int $docId, array $manual = []): array
{
    @set_time_limit(240); @ini_set('memory_limit', '512M');
    $fail = function ($m) { return ['ok' => false, 'pages' => 0, 'message' => $m]; };
    $doc = doc_get($docId);
    if (!$doc || empty($doc['file_name'])) { return $fail('Document or file not found.'); }
    if (!function_exists('imagecreatetruecolor')) { return $fail('The PHP GD extension is required to generate previews.'); }
    $src = doc_private_path($doc);
    if (!is_file($src)) { return $fail('The original file is missing on the server.'); }
    $s = preview_settings();
    $limit = (int)($doc['preview_limit'] ?: $s['pages']);
    $tmp = rtrim(UPLOAD_DIR, '/\\') . '/temporary/pv' . bin2hex(random_bytes(6));
    if (!@mkdir($tmp, 0755, true)) { return $fail('Temporary folder is not writable (uploads/temporary).'); }
    try {
        $ext = strtolower((string)$doc['file_ext']); $images = []; $method = '';
        if ($manual) { $images = $manual; $method = 'manual images'; }
        elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { $images = [$src]; $method = 'image'; }
        elseif ($ext === 'pdf') { $images = preview_pdf_to_images($src, $limit, $tmp); $method = 'PDF'; }
        else {
            $pdf = preview_office_to_pdf($src, $tmp);
            if ($pdf !== '') { $images = preview_pdf_to_images($pdf, $limit, $tmp); $method = 'Word → PDF'; }
            if (!$images && $ext === 'docx') { $images = preview_docx_text_images($src, $limit, $tmp); $method = 'text-only'; }
        }
        if (!$images) {
            db_exec("UPDATE documents SET preview_status = 'failed' WHERE id = ?", [$docId]);
            return $fail('This server could not render the file automatically (needs poppler-utils, Imagick+Ghostscript or LibreOffice). Upload preview images manually in the Preview step.');
        }
        $dir = 'p' . $docId . '-' . bin2hex(random_bytes(6));
        $out = rtrim(UPLOAD_DIR, '/\\') . '/previews/' . $dir;
        if (!@mkdir($out, 0755, true)) { return $fail('uploads/previews is not writable.'); }
        $n = 0;
        foreach (array_slice($images, 0, $limit) as $img) { if (preview_watermark_image($img, $out . '/p' . ($n + 1) . '.jpg', $s)) { $n++; } }
        if ($n === 0) { preview_rrmdir($out); db_exec("UPDATE documents SET preview_status = 'failed' WHERE id = ?", [$docId]); return $fail('Rendering produced no usable pages.'); }
        preview_delete_dir($doc['preview_dir']);
        db_exec("UPDATE documents SET preview_status = 'ready', preview_dir = ?, preview_pages = ? WHERE id = ?", [$dir, $n, $docId]);
        if (empty($doc['pages']) && $ext === 'pdf') { $pc = preview_pdf_pages($src); if ($pc) { db_exec('UPDATE documents SET pages = ? WHERE id = ?', [$pc, $docId]); } }
        return ['ok' => true, 'pages' => $n, 'message' => 'Preview generated: ' . $n . ' page' . ($n > 1 ? 's' : '') . ' (' . $method . ').'];
    } finally {
        preview_rrmdir($tmp);
    }
}

/** Detects the number of pages of a stored original (PDF/DOCX/image). */
function doc_detect_pages(string $path, string $ext): ?int
{
    $ext = strtolower($ext);
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { return 1; }
    if ($ext === 'pdf') { return preview_pdf_pages($path); }
    if ($ext === 'docx' && class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($path) === true) { $x = $z->getFromName('docProps/app.xml'); $z->close(); if ($x && preg_match('#<Pages>(\d+)</Pages>#', $x, $m) && (int)$m[1] > 0) { return (int)$m[1]; } }
    }
    return null;
}
