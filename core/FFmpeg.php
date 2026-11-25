<?php
require_once __DIR__ . '/../config.php';

class FFmpeg
{
    public static function probe(string $file): array
    {
        $p = proc_open(
            [FFPROBE, '-v', 'quiet', '-print_format', 'json',
             '-show_format', '-show_streams', $file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($p);
        return json_decode($out, true) ?: [];
    }

    public static function run(array $args): array
    {
        $p = proc_open(
            array_merge([FFMPEG, '-hide_banner', '-y'], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        stream_get_contents($pipes[1]);
        $log = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return ['code' => proc_close($p), 'log' => $log];
    }

    public static function hasAudio(array $info): bool
    {
        foreach ($info['streams'] ?? [] as $s) {
            if (($s['codec_type'] ?? '') === 'audio') return true;
        }
        return false;
    }

    public static function duration(array $info): float
    {
        return (float)($info['format']['duration'] ?? 0);
    }
}
