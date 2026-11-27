<?php
require_once __DIR__ . '/../core/FFmpeg.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); exit(json_encode(['error' => 'JSON inválido'])); }

$sourceId  = preg_replace('/[^a-f0-9]/', '', $in['source_id'] ?? '');
$sourceExt = preg_replace('/[^a-z0-9]/', '', strtolower($in['source_ext'] ?? ''));
$clips     = $in['clips'] ?? [];
$overlays  = $in['overlays'] ?? [];
$music     = $in['music'] ?? [];
$muted     = !empty($in['muted']);

if (!$sourceId || !file_exists(UPLOADS_DIR . "/$sourceId.$sourceExt")) {
    http_response_code(400); exit(json_encode(['error' => 'Arquivo não encontrado']));
}
if (!is_array($clips) || !$clips) {
    http_response_code(400); exit(json_encode(['error' => 'Nenhum clipe']));
}

$cleanClips = [];
foreach ($clips as $c) {
    $ss = (float)($c['sourceStart'] ?? -1);
    $se = (float)($c['sourceEnd']   ?? -1);
    if ($ss < 0 || $se <= $ss) { http_response_code(400); exit(json_encode(['error' => 'Clipe inválido'])); }
    $cleanClips[] = ['start' => $ss, 'end' => $se];
}

$cleanOverlays = [];
if (is_array($overlays)) {
    foreach ($overlays as $o) {
        $type = $o['type'] ?? '';
        if (!in_array($type, ['text', 'image'])) continue;

        $tStart = (float)($o['tStart'] ?? 0);
        $tEnd   = (float)($o['tEnd']   ?? 0);
        if ($tEnd <= $tStart) continue;

        $base = [
            'type'   => $type,
            'tStart' => $tStart,
            'tEnd'   => $tEnd,
            'x'      => max(0, min(1, (float)($o['x'] ?? 0))),
            'y'      => max(0, min(1, (float)($o['y'] ?? 0))),
            'w'      => max(0.01, min(1, (float)($o['w'] ?? 0.3))),
            'h'      => max(0.01, min(1, (float)($o['h'] ?? 0.2))),
        ];

        if ($type === 'text') {
            $text = (string)($o['text'] ?? '');
            if ($text === '') continue;
            if (mb_strlen($text) > 200) $text = mb_substr($text, 0, 200);
            $base['text']     = $text;
            $base['fontSize'] = max(0.01, min(0.5, (float)($o['fontSize'] ?? 0.08)));
            $color = (string)($o['color'] ?? '#ffffff');
            if (!preg_match('/^#[0-9a-fA-F]{3,8}$|^[a-zA-Z]+$/', $color)) $color = '#ffffff';
            $base['color'] = $color;
            $bg = (string)($o['bg'] ?? '');
            if ($bg !== '' && !preg_match('/^rgba?\([0-9.,\s]+\)$/', $bg)) $bg = '';
            $base['bg'] = $bg;
            $base['rotation'] = ((int)($o['rotation'] ?? 0)) % 360;
        } else {
            $srcId = preg_replace('/[^a-f0-9]/', '', (string)($o['srcId'] ?? ''));
            $ext   = preg_replace('/[^a-z0-9]/', '', strtolower((string)($o['ext'] ?? '')));
            if (!$srcId || !in_array($ext, ['png','jpg','jpeg','gif','webp'])) continue;
            if (!file_exists(UPLOADS_DIR . "/$srcId.$ext")) continue;
            $base['srcId'] = $srcId;
            $base['ext']   = $ext;

            $cx = max(0, min(0.99, (float)($o['cropX'] ?? 0)));
            $cy = max(0, min(0.99, (float)($o['cropY'] ?? 0)));
            $cw = max(0.01, min(1 - $cx, (float)($o['cropW'] ?? 1)));
            $ch = max(0.01, min(1 - $cy, (float)($o['cropH'] ?? 1)));
            $base['cropX'] = $cx;
            $base['cropY'] = $cy;
            $base['cropW'] = $cw;
            $base['cropH'] = $ch;
        }
        $cleanOverlays[] = $base;
    }
}

$cleanMusic = [];
if (is_array($music)) {
    foreach ($music as $m) {
        $srcId = preg_replace('/[^a-f0-9]/', '', (string)($m['srcId'] ?? ''));
        $ext   = preg_replace('/[^a-z0-9]/', '', strtolower((string)($m['ext'] ?? '')));
        if (!$srcId || !in_array($ext, ['mp3','m4a','aac','wav','ogg','opus','flac'])) continue;
        if (!file_exists(UPLOADS_DIR . "/$srcId.$ext")) continue;

        $tStart = max(0, (float)($m['tStart'] ?? 0));
        $tEnd   = (float)($m['tEnd'] ?? 0);
        if ($tEnd <= $tStart + 0.05) continue;

        $cleanMusic[] = [
            'srcId'       => $srcId,
            'ext'         => $ext,
            'tStart'      => $tStart,
            'tEnd'        => $tEnd,
            'sourceStart' => max(0, (float)($m['sourceStart'] ?? 0)),
            'volume'      => max(0, min(2, (float)($m['volume'] ?? 1))),
            'fadeIn'      => max(0, min(10, (float)($m['fadeIn'] ?? 0))),
            'fadeOut'     => max(0, min(10, (float)($m['fadeOut'] ?? 0))),
        ];
    }
}

$jobId = bin2hex(random_bytes(8));
file_put_contents(JOBS_DIR . "/$jobId.json", json_encode([
    'id'         => $jobId,
    'type'       => 'render',
    'status'     => 'queued',
    'progress'   => 0,
    'source_id'  => $sourceId,
    'source_ext' => $sourceExt,
    'clips'      => $cleanClips,
    'overlays'   => $cleanOverlays,
    'music'      => $cleanMusic,
    'muted'      => $muted,
    'crf'        => max(18, min(30, (int)($in['crf'] ?? 23))),
    'created_at' => time(),
]));

$cmd = escapeshellarg(PHP_BIN) . ' '
     . escapeshellarg(BASE_DIR . '/core/render_worker.php') . ' '
     . escapeshellarg($jobId);
exec($cmd . ' > /dev/null 2>&1 &');

echo json_encode(['job_id' => $jobId]);
