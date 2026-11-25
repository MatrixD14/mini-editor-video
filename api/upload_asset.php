<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$fail = function (string $m, int $c = 400) {
    http_response_code($c); echo json_encode(['error' => $m]); exit;
};

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) $fail('Upload falhou');
$ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['png','jpg','jpeg','gif','webp'])) $fail('Formato não suportado');

$id   = bin2hex(random_bytes(8));
$dest = UPLOADS_DIR . "/$id.$ext";
if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) $fail('Falha ao salvar', 500);

$w = 0; $h = 0;
$sz = @getimagesize($dest);
if ($sz) { $w = $sz[0]; $h = $sz[1]; }

echo json_encode(['id' => $id, 'ext' => $ext, 'w' => $w, 'h' => $h]);
