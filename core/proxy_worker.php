<?php
require_once __DIR__ . '/FFmpeg.php';

$jobId = $argv[1] ?? null;
if (!$jobId) exit(1);

$jobFile = JOBS_DIR . "/$jobId.json";
$job = json_decode(file_get_contents($jobFile), true);
if (!$job) exit(1);

$update = function (array $patch) use ($jobFile, &$job) {
    $job = array_merge($job, $patch);
    file_put_contents($jobFile . '.tmp', json_encode($job));
    rename($jobFile . '.tmp', $jobFile);
};

$update(['status' => 'processing']);

try {
    $src = UPLOADS_DIR . '/' . $job['source_id'] . '.' . $job['source_ext'];
    $out = UPLOADS_DIR . '/' . $job['source_id'] . '_proxy.mp4';

    $info = FFmpeg::probe($src);
    $hasA = FFmpeg::hasAudio($info);
    $dur  = FFmpeg::duration($info);

    // Proxy adaptativo: quanto mais longo, menor a resolução
    if      ($dur > 20 * 60) { $h = 240; $crf = 32; }   // > 20 min
    elseif  ($dur > 5  * 60) { $h = 360; $crf = 30; }   // 5-20 min
    else                     { $h = 480; $crf = 28; }   // < 5 min

    $args = [
        '-i', $src,
        '-vf', "scale=-2:$h",
        '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', (string)$crf,
        '-pix_fmt', 'yuv420p',
        '-movflags', '+faststart',
        '-g', '48',          // keyframe a cada ~2s @ 24fps → seek preciso
    ];
    $args = array_merge($args, $hasA ? ['-c:a', 'aac', '-b:a', '48k'] : ['-an']);
    $args[] = $out;

    $r = FFmpeg::run($args);
    if ($r['code'] !== 0) {
        $update(['status' => 'error', 'error' => 'FFmpeg proxy falhou', 'log' => $r['log']]);
        exit(1);
    }

    $update([
        'status'    => 'done',
        'progress'  => 100,
        'proxy_url' => "api/proxy.php?id={$job['source_id']}",
    ]);
} catch (Throwable $e) {
    $update(['status' => 'error', 'error' => $e->getMessage()]);
    exit(1);
}
