<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$fail = function (string $m, int $c = 400) {
    http_response_code($c); echo json_encode(['error' => $m]); exit;
};

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) $fail('Upload falhou');
$ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));

$imageExts = ['png','jpg','jpeg','gif','webp'];
$audioExts = ['mp3','m4a','aac','wav','ogg','opus','flac'];

if (!in_array($ext, array_merge($imageExts, $audioExts))) $fail('Formato não suportado');

$id   = bin2hex(random_bytes(8));
$dest = UPLOADS_DIR . "/$id.$ext";
if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) $fail('Falha ao salvar', 500);

if (in_array($ext, $audioExts)) {
    require_once __DIR__ . '/../core/FFmpeg.php';
    $info = FFmpeg::probe($dest);
    echo json_encode([
        'id'       => $id,
        'ext'      => $ext,
        'type'     => 'audio',
        'duration' => FFmpeg::duration($info),
    ]);
} else {
    $sz = @getimagesize($dest);
    echo json_encode([
        'id'   => $id,
        'ext'  => $ext,
        'type' => 'image',
        'w'    => $sz ? $sz[0] : 0,
        'h'    => $sz ? $sz[1] : 0,
    ]);
}
