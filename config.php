<?php
define('BASE_DIR',    __DIR__);
define('UPLOADS_DIR', BASE_DIR . '/uploads');
define('EXPORTS_DIR', BASE_DIR . '/exports');
define('TEMP_DIR',    BASE_DIR . '/temp');
define('JOBS_DIR',    TEMP_DIR . '/jobs');

define('FFMPEG',  trim(shell_exec('command -v ffmpeg')  ?: 'ffmpeg'));
define('FFPROBE', trim(shell_exec('command -v ffprobe') ?: 'ffprobe'));
define('PHP_BIN', PHP_BINARY);

define('PROXY_HEIGHT', 480);
define('PROXY_CRF',    28);

// NOVO: fonte para drawtext
$__fontCandidates = [
    '/system/fonts/Roboto-Regular.ttf',                                 // Android
    '/data/data/com.termux/files/usr/share/fonts/TTF/DejaVuSans.ttf',   // Termux
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',                  // Linux
];
$__font = '';
foreach ($__fontCandidates as $__f) { if (file_exists($__f)) { $__font = $__f; break; } }
define('FONT_FILE', $__font);
unset($__fontCandidates, $__font, $__f);

foreach ([UPLOADS_DIR, EXPORTS_DIR, TEMP_DIR, JOBS_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0775, true);
}
