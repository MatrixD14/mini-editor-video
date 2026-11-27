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

function escDrawtext(string $s): string {
    return str_replace(
        ['\\', '\'', ':', '%', ',', ';', '[', ']', "\n", "\r", "\t"],
        ['\\\\', "\\'", '\\:', '\\%', '\\,', '\\;', '\\[', '\\]', ' ',  ' ',  ' '],
        $s
    );
}

function rgbaToFFmpeg(string $rgba): ?string {
    if (!preg_match('/rgba?\((\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+))?\)/', $rgba, $m)) return null;
    $r = (int)$m[1]; $g = (int)$m[2]; $b = (int)$m[3];
    $a = isset($m[4]) && $m[4] !== '' ? (float)$m[4] : 1.0;
    return sprintf('0x%02x%02x%02x@%.2f', $r, $g, $b, $a);
}

try {
    $source = UPLOADS_DIR . '/' . $job['source_id'] . '.' . $job['source_ext'];
    $info   = FFmpeg::probe($source);
    $hasA   = FFmpeg::hasAudio($info) && empty($job['muted']);
    $dur    = FFmpeg::duration($info);

    $vw = 1920; $vh = 1080;
    foreach ($info['streams'] ?? [] as $s) {
        if (($s['codec_type'] ?? '') === 'video') {
            $vw = (int)($s['width']  ?? 1920);
            $vh = (int)($s['height'] ?? 1080);
            break;
        }
    }

    $clips = []; $clipVirtualStart = []; $cum = 0;
    foreach ($job['clips'] as $c) {
        $clipVirtualStart[] = $cum;
        $ss = max(0.0, min((float)$c['start'], $dur));
        $se = max($ss + 0.05, min((float)$c['end'], $dur));
        $clips[] = ['start' => $ss, 'end' => $se];
        $cum += $se - $ss;
    }

    $overlays = $job['overlays'] ?? [];

    $update(['status' => 'processing', 'progress' => 0]);

    $parts = [];
    $total = count($clips);

    foreach ($clips as $i => $c) {
        $out = TEMP_DIR . "/{$jobId}_part{$i}.mp4";
        $clipDur = $c['end'] - $c['start'];
        $vStart = $clipVirtualStart[$i];
        $vEnd   = $vStart + $clipDur;

        $active = [];
        foreach ($overlays as $ov) {
            $iStart = max((float)$ov['tStart'], $vStart);
            $iEnd   = min((float)$ov['tEnd'],   $vEnd);
            if ($iEnd <= $iStart) continue;
            $active[] = ['ov' => $ov, 'ls' => $iStart - $vStart, 'le' => $iEnd - $vStart];
        }

        $args = ['-ss', (string)$c['start'], '-i', $source];

        $imgInputIndexes = [];
        $inputIdx = 1;
        foreach ($active as $k => $a) {
            $ov = $a['ov'];
            if ($ov['type'] !== 'image') continue;
            $imgPath = UPLOADS_DIR . '/' . $ov['srcId'] . '.' . $ov['ext'];
            if (!file_exists($imgPath)) continue;

            if ($ov['ext'] === 'gif') {
                $args[] = '-ignore_loop'; $args[] = '1';
            } else {
                $args[] = '-loop'; $args[] = '1';
                $args[] = '-framerate'; $args[] = '30';
            }
            $args[] = '-i'; $args[] = $imgPath;
            $imgInputIndexes[$k] = $inputIdx;
            $inputIdx++;
        }

        if (!empty($active)) {
            $filters = [];
            $lastV = '0:v';
            foreach ($active as $k => $a) {
                $ov = $a['ov'];
                $enable = sprintf("between(t,%.3f,%.3f)", $a['ls'], $a['le']);

                if ($ov['type'] === 'text') {
                    $text = escDrawtext($ov['text']);
                    $fs = max(8, (int)round($ov['fontSize'] * $vh));
                    $xpx = (int)round($ov['x'] * $vw);
                    $ypx = (int)round($ov['y'] * $vh);
                    $color = $ov['color'];
                    $rot = (float)($ov['rotation'] ?? 0);

                    if (abs($rot) > 0.5) {
                        $textW_est = max(20, (int)round(mb_strlen($ov['text']) * $fs * 0.6));
                        $textH_est = max(20, (int)round($fs * 1.2));
                        $canvasW = $textW_est + 40;
                        $canvasH = $textH_est + 24;
                        $durS = number_format($clipDur, 3, '.', '');
                        $args[] = '-f'; $args[] = 'lavfi';
                        $args[] = '-t'; $args[] = $durS;
                        $args[] = '-i'; $args[] = "color=c=black@0:s={$canvasW}x{$canvasH}:r=30";
                        $canvasIdx = $inputIdx++;

                        $dt = "drawtext=text='{$text}'";
                        if (FONT_FILE) $dt .= ':fontfile=' . FONT_FILE;
                        $dt .= ":fontsize={$fs}:fontcolor={$color}:x=(w-text_w)/2:y=(h-text_h)/2";
                        if (!empty($ov['bg'])) {
                            $bgc = rgbaToFFmpeg($ov['bg']);
                            if ($bgc) $dt .= ":box=1:boxcolor={$bgc}:boxborderw=8";
                        }
                        $angle_rad = number_format($rot * M_PI / 180, 6, '.', '');
                        $filters[] = "[{$canvasIdx}:v]{$dt}[dt{$k}]";
                        $filters[] = "[dt{$k}]rotate={$angle_rad}:c=none:ow=rotw(iw):oh=roth(ih)[rot{$k}]";
                        $cx_px = (int)round($ov['x'] * $vw + $textW_est / 2);
                        $cy_px = (int)round($ov['y'] * $vh + $textH_est / 2);
                        $filters[] = "[{$lastV}][rot{$k}]overlay=x={$cx_px}-w/2:y={$cy_px}-h/2:enable='{$enable}':shortest=1[o{$k}]";
                        $lastV = "o{$k}";
                    } else {
                        $dt = "drawtext=text='{$text}'";
                        if (FONT_FILE) $dt .= ':fontfile=' . FONT_FILE;
                        $dt .= ":fontsize={$fs}:fontcolor={$color}:x={$xpx}:y={$ypx}:enable='{$enable}'";
                        if (!empty($ov['bg'])) {
                            $bgc = rgbaToFFmpeg($ov['bg']);
                            if ($bgc) $dt .= ":box=1:boxcolor={$bgc}:boxborderw=8";
                        }
                        $filters[] = "[{$lastV}]{$dt}[t{$k}]";
                        $lastV = "t{$k}";
                    }
                } else {
                    $inIdx = $imgInputIndexes[$k] ?? null;
                    if ($inIdx === null) continue;
                    $wpx = max(8, (int)round($ov['w'] * $vw));
                    $hpx = max(8, (int)round($ov['h'] * $vh));
                    $xpx = (int)round($ov['x'] * $vw);
                    $ypx = (int)round($ov['y'] * $vh);

                    $cx = (float)($ov['cropX'] ?? 0);
                    $cy = (float)($ov['cropY'] ?? 0);
                    $cw = (float)($ov['cropW'] ?? 1);
                    $ch = (float)($ov['cropH'] ?? 1);
                    $needCrop = ($cx > 0.001 || $cy > 0.001 || $cw < 0.999 || $ch < 0.999);

                    if ($needCrop) {
                        $cwS = number_format($cw, 4, '.', '');
                        $chS = number_format($ch, 4, '.', '');
                        $cxS = number_format($cx, 4, '.', '');
                        $cyS = number_format($cy, 4, '.', '');
                        $filters[] = "[{$inIdx}:v]crop=iw*{$cwS}:ih*{$chS}:iw*{$cxS}:ih*{$cyS}[c{$k}]";
                        $filters[] = "[c{$k}]scale={$wpx}:{$hpx}[img{$k}]";
                    } else {
                        $filters[] = "[{$inIdx}:v]scale={$wpx}:{$hpx}[img{$k}]";
                    }
                    $filters[] = "[{$lastV}][img{$k}]overlay={$xpx}:{$ypx}:enable='{$enable}':shortest=1[o{$k}]";
                    $lastV = "o{$k}";
                }
            }

            if (!empty($filters)) {
                $args[] = '-filter_complex'; $args[] = implode(';', $filters);
                $args[] = '-map'; $args[] = "[{$lastV}]";
                if ($hasA) { $args[] = '-map'; $args[] = '0:a?'; $args[] = '-c:a'; $args[] = 'aac'; $args[] = '-b:a'; $args[] = '128k'; }
                else { $args[] = '-an'; }
                $args[] = '-t'; $args[] = (string)$clipDur;
                $args[] = '-c:v'; $args[] = 'libx264';
                $args[] = '-preset'; $args[] = 'veryfast';
                $args[] = '-crf'; $args[] = (string)$job['crf'];
                $args[] = '-pix_fmt'; $args[] = 'yuv420p';
                $args[] = '-movflags'; $args[] = '+faststart';
                $args[] = '-shortest';
                $args[] = $out;
            } else {
                $args[] = '-t'; $args[] = (string)$clipDur;
                $args[] = '-c:v'; $args[] = 'libx264';
                $args[] = '-preset'; $args[] = 'veryfast';
                $args[] = '-crf'; $args[] = (string)$job['crf'];
                $args[] = '-pix_fmt'; $args[] = 'yuv420p';
                $args[] = '-movflags'; $args[] = '+faststart';
                if ($hasA) { $args[] = '-c:a'; $args[] = 'aac'; $args[] = '-b:a'; $args[] = '128k'; }
                else { $args[] = '-an'; }
                $args[] = $out;
            }
        } else {
            $args[] = '-t'; $args[] = (string)$clipDur;
            $args[] = '-c:v'; $args[] = 'libx264';
            $args[] = '-preset'; $args[] = 'veryfast';
            $args[] = '-crf'; $args[] = (string)$job['crf'];
            $args[] = '-pix_fmt'; $args[] = 'yuv420p';
            $args[] = '-movflags'; $args[] = '+faststart';
            if ($hasA) { $args[] = '-c:a'; $args[] = 'aac'; $args[] = '-b:a'; $args[] = '128k'; }
            else { $args[] = '-an'; }
            $args[] = $out;
        }

        $r = FFmpeg::run($args);
        if ($r['code'] !== 0) {
            $update(['status' => 'error', 'error' => "Falha no clipe $i", 'log' => $r['log']]);
            exit(1);
        }
        $parts[] = $out;
        $update(['progress' => (int)(($i + 1) / $total * 80)]);
    }

    // concat -> arquivo intermediário
    $list = TEMP_DIR . "/{$jobId}_list.txt";
    file_put_contents($list, implode("\n", array_map(
        fn($f) => "file '" . str_replace("'", "'\\''", $f) . "'", $parts
    )));
    $concatFile = TEMP_DIR . "/{$jobId}_concat.mp4";
    $r = FFmpeg::run(['-f', 'concat', '-safe', '0', '-i', $list, '-c', 'copy', $concatFile]);
    if ($r['code'] !== 0) {
        $update(['status' => 'error', 'error' => 'Falha na concatenação', 'log' => $r['log']]);
        exit(1);
    }
    foreach ($parts as $p) @unlink($p);
    @unlink($list);

    $update(['progress' => 85]);

    // Mistura de música
    $final = EXPORTS_DIR . "/{$jobId}.mp4";
    $music = $job['music'] ?? [];
    $muted = !empty($job['muted']);

    $concatInfo = FFmpeg::probe($concatFile);
    $concatHasAudio = FFmpeg::hasAudio($concatInfo);
    $concatDur = FFmpeg::duration($concatInfo);

    if (empty($music)) {
        if ($muted && $concatHasAudio) {
            $r = FFmpeg::run(['-i', $concatFile, '-c:v', 'copy', '-an', '-movflags', '+faststart', $final]);
            @unlink($concatFile);
            if ($r['code'] !== 0) {
                $update(['status' => 'error', 'error' => 'Falha ao remover áudio', 'log' => $r['log']]);
                exit(1);
            }
        } else {
            rename($concatFile, $final);
        }
    } else {
        $args = ['-i', $concatFile];
        $filterParts = [];
        $musicLabels = [];
        $inputIdx = 1;

        foreach ($music as $m) {
            $musicPath = UPLOADS_DIR . '/' . $m['srcId'] . '.' . $m['ext'];
            if (!file_exists($musicPath)) continue;

            $ss = (float)$m['sourceStart'];
            $dur = (float)$m['tEnd'] - (float)$m['tStart'];
            if ($dur <= 0.05) continue;

            $args[] = '-ss'; $args[] = (string)$ss;
            $args[] = '-i'; $args[] = $musicPath;

            $delayMs = (int)round((float)$m['tStart'] * 1000);
            $vol = max(0, min(2, (float)($m['volume'] ?? 1)));
            $fadeIn = max(0, min(10, (float)($m['fadeIn'] ?? 0)));
            $fadeOut = max(0, min(10, (float)($m['fadeOut'] ?? 0)));

            $f = "[{$inputIdx}:a]atrim=start=0:end={$dur},asetpts=PTS-STARTPTS,volume={$vol}";
            if ($fadeIn > 0) $f .= ",afade=t=in:st=0:d={$fadeIn}";
            if ($fadeOut > 0 && $dur > $fadeOut) $f .= ",afade=t=out:st=" . ($dur - $fadeOut) . ":d={$fadeOut}";
            $f .= ",adelay={$delayMs}|{$delayMs}[m{$inputIdx}]";

            $filterParts[] = $f;
            $musicLabels[] = "[m{$inputIdx}]";
            $inputIdx++;
        }

        if (empty($musicLabels)) {
            if ($muted && $concatHasAudio) {
                FFmpeg::run(['-i', $concatFile, '-c:v', 'copy', '-an', '-movflags', '+faststart', $final]);
                @unlink($concatFile);
            } else {
                rename($concatFile, $final);
            }
        } else {
            $inputLabels = $musicLabels;
            if (!$muted && $concatHasAudio) {
                array_unshift($inputLabels, '[0:a]');
            }
            $n = count($inputLabels);
            $mix = implode('', $inputLabels) . "amix=inputs={$n}:duration=longest:normalize=0[aout]";
            $filterParts[] = $mix;

            $args[] = '-filter_complex';
            $args[] = implode(';', $filterParts);
            $args[] = '-map'; $args[] = '0:v';
            $args[] = '-map'; $args[] = '[aout]';
            $args[] = '-c:v'; $args[] = 'copy';
            $args[] = '-c:a'; $args[] = 'aac'; $args[] = '-b:a'; $args[] = '128k';
            $args[] = '-t'; $args[] = (string)$concatDur;
            $args[] = '-movflags'; $args[] = '+faststart';
            $args[] = $final;

            $r = FFmpeg::run($args);
            if ($r['code'] !== 0) {
                @unlink($final);
                rename($concatFile, $final);
            } else {
                @unlink($concatFile);
            }
        }
    }

    $update(['status' => 'done', 'progress' => 100, 'output' => "exports/{$jobId}.mp4"]);
} catch (Throwable $e) {
    $update(['status' => 'error', 'error' => $e->getMessage()]);
    exit(1);
}
