<?php
require_once __DIR__ . '/../core/FFmpeg.php';
header('Content-Type: application/json');

$id = preg_replace('/[^a-f0-9]/', '', $_GET['id'] ?? '');
$f  = JOBS_DIR . "/$id.json";
if (!$id || !file_exists($f)) {
    http_response_code(404);
    exit(json_encode(['error' => 'Job não encontrado']));
}
echo file_get_contents($f);
