<?php
require_once __DIR__ . '/../config.php';

$id = preg_replace('/[^a-f0-9]/', '', $_GET['id'] ?? '');
if (!$id) { http_response_code(400); exit; }

$file = UPLOADS_DIR . '/' . $id . '_proxy.mp4';
if (!file_exists($file)) { http_response_code(404); exit; }

$size  = filesize($file);
$start = 0;
$end   = $size - 1;

if (isset($_SERVER['HTTP_RANGE']) &&
    preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    $start = $m[1] === '' ? 0 : (int)$m[1];
    $end   = $m[2] === '' ? $size - 1 : (int)$m[2];
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}

header('Content-Type: video/mp4');
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
header('Cache-Control: public, max-age=3600');

// Libera o lock da sessão (não temos sessão aqui, mas garante streaming)
while (ob_get_level()) ob_end_clean();

$fp = fopen($file, 'rb');
fseek($fp, $start);
$remaining = $end - $start + 1;
$chunk = 256 * 1024;
while ($remaining > 0 && !feof($fp)) {
    $read = min($chunk, $remaining);
    echo fread($fp, $read);
    flush();
    $remaining -= $read;
}
fclose($fp);
