<?php
require_once __DIR__ . '/../core/FFmpeg.php';
header('Content-Type: application/json');

$fail = function (string $m, int $c = 400) {
    http_response_code($c); echo json_encode(['error' => $m]); exit;
};

if (empty($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) $fail('Upload falhou');
$ext = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['mp4','mov','mkv','webm','avi','m4v'])) $fail('Formato não suportado');

$id   = bin2hex(random_bytes(8));
$dest = UPLOADS_DIR . "/$id.$ext";
if (!move_uploaded_file($_FILES['video']['tmp_name'], $dest)) $fail('Falha ao salvar', 500);

$info  = FFmpeg::probe($dest);
$video = null;
foreach ($info['streams'] ?? [] as $s) {
    if (($s['codec_type'] ?? '') === 'video') { $video = $s; break; }
}

$fps = 0;
if ($video && !empty($video['r_frame_rate'])) {
    $p = explode('/', $video['r_frame_rate']);
    if (count($p) === 2 && (int)$p[1] > 0) $fps = (int)$p[0] / (int)$p[1];
}

$proxyJobId = bin2hex(random_bytes(8));
file_put_contents(JOBS_DIR . "/$proxyJobId.json", json_encode([
    'id' => $proxyJobId, 'type' => 'proxy', 'status' => 'queued', 'progress' => 0,
    'source_id' => $id, 'source_ext' => $ext, 'created_at' => time(),
]));

$cmd = escapeshellarg(PHP_BIN) . ' '
     . escapeshellarg(BASE_DIR . '/core/proxy_worker.php') . ' '
     . escapeshellarg($proxyJobId);
exec($cmd . ' > /dev/null 2>&1 &');

echo json_encode([
    'id'         => $id,
    'ext'        => $ext,
    'duration'   => FFmpeg::duration($info),
    'width'      => $video['width']  ?? 0,
    'height'     => $video['height'] ?? 0,
    'has_audio'  => FFmpeg::hasAudio($info),
    'fps'        => round($fps, 3),
    'proxy_job'  => $proxyJobId,
]);
