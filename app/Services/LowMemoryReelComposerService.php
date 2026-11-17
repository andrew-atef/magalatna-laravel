<?php

declare(strict_types=1);

namespace App\Services;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Composes the vertical 9:16 Reel (720x1280) with ffmpeg/ffprobe under
 * STRICT low-memory constraints (840MB RAM VPS, <100MB during encode).
 *
 * Strategy:
 *  - Resolution is LOCKED to 720x1280 (never 1080x1920) and encoding runs
 *    with -threads 1 — the two knobs that keep libx264's frame buffers tiny.
 *  - Foreground flyer pages are pre-processed ONCE with GD (downscaled,
 *    rounded corners, drop shadow baked as alpha PNG) so the ffmpeg graph
 *    stays cheap: blurred fill background + crisp centered overlay + concat.
 *  - The "dynamic movement" is a single time-animated crop (slow zoom+pan)
 *    applied AFTER the concat — one filter, no per-frame zoompan buffers.
 *  - Every temporary file lives in a configurable temp dir and is the
 *    CALLER's responsibility to unlink (the job wraps compose() in
 *    try/finally).
 */
class LowMemoryReelComposerService
{
    /** 720x1280 locked — RAM-safety is a hard requirement, never negotiate. */
    private const WIDTH = 720;

    private const HEIGHT = 1280;

    /** Foreground crisp layer max box (inside 720x1280 with breathing room). */
    private const FG_MAX_WIDTH = 560;

    private const FG_MAX_HEIGHT = 1040;

    private const CORNER_RADIUS = 28;

    private const SHADOW_OFFSET_X = 8;

    private const SHADOW_OFFSET_Y = 10;

    private const CANVAS_PADDING = 22;

    private const TRAIL_SECONDS = 2.5;

    private const MAX_PAGES = 4;

    private const ENCODE_TIMEOUT_SECONDS = 270;

    /**
     * @param  list<string>  $pageImageBinaries  Raw image bytes, priority order (cover first).
     * @param  string  $audioPath  Temporary voiceover file (wav/mp3).
     * @param  string  $outputPath  Temporary MP4 output path (caller unlinks it).
     * @return array{audio_duration: float, video_duration: float}
     *
     * @throws \RuntimeException
     */
    public function compose(array $pageImageBinaries, string $audioPath, string $outputPath): array
    {
        if ($pageImageBinaries === []) {
            throw new \RuntimeException('Compose requires at least one flyer page image.');
        }

        $audioDuration = $this->probeAudioDuration($audioPath);

        if ($audioDuration <= 0) {
            throw new \RuntimeException('ffprobe returned a non-positive audio duration.');
        }

        $totalDuration = round($audioDuration + self::TRAIL_SECONDS, 2);

        $tempDir = rtrim((string) config('services.reels.temp_dir', sys_get_temp_dir()), '/\\');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $ulid = uniqid('reelfg_', false);
        $foregroundPaths = [];

        try {
            foreach (array_slice($pageImageBinaries, 0, self::MAX_PAGES) as $index => $binary) {
                $foregroundPaths[] = $this->prepareForeground($binary, $tempDir.'/'.$ulid.'_'.$index.'.png');
            }

            $this->runFfmpeg($foregroundPaths, $audioPath, $outputPath, $totalDuration);

            if (! is_file($outputPath) || filesize($outputPath) === 0) {
                throw new \RuntimeException('ffmpeg produced no output video.');
            }

            return ['audio_duration' => $audioDuration, 'video_duration' => $totalDuration];
        } finally {
            // Memory & disk hygiene: foreground PNGs never survive compose().
            foreach ($foregroundPaths as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * ffprobe duration probe — the single source of truth for video length.
     */
    private function probeAudioDuration(string $audioPath): float
    {
        $bin = (string) config('services.reels.ffprobe_bin', 'ffprobe');

        $process = new Process([
            $bin,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $audioPath,
        ], timeout: 30);

        $process->run();

        $duration = trim($process->getOutput());

        if (! $process->isSuccessful() || $duration === '' || ! is_numeric($duration)) {
            throw new \RuntimeException('ffprobe failed on audio: '.substr($process->getErrorOutput(), 0, 400));
        }

        return (float) $duration;
    }

    /**
     * Single-pass ffmpeg render. Filter graph:
     *   per page: split → [fill+crop+boxblur+darken (bg)] overlay [crisp fg] → segment
     *   all segments → concat → time-animated slow zoom/pan crop → 720x1280
     *
     * @param  list<string>  $foregroundPaths
     */
    private function runFfmpeg(array $foregroundPaths, string $audioPath, string $outputPath, float $totalDuration): void
    {
        $bin = (string) config('services.reels.ffmpeg_bin', 'ffmpeg');
        $count = count($foregroundPaths);
        $segmentDuration = round($totalDuration / $count, 3);

        $args = [$bin, '-y', '-threads', '1'];

        foreach ($foregroundPaths as $path) {
            $args[] = '-framerate';
            $args[] = '25';
            $args[] = '-loop';
            $args[] = '1';
            $args[] = '-t';
            $args[] = (string) $segmentDuration;
            $args[] = '-i';
            $args[] = $path;
        }

        $args[] = '-i';
        $args[] = $audioPath;

        $filters = [];

        for ($i = 0; $i < $count; $i++) {
            $filters[] = "[{$i}:v]split=2[src{$i}a][src{$i}b]";
            $filters[] = "[src{$i}a]scale=".self::WIDTH.':'.self::HEIGHT
                .':force_original_aspect_ratio=increase,crop='.self::WIDTH.':'.self::HEIGHT
                .',boxblur=20:5,eq=brightness=-0.06,format=yuv420p[bg'.$i.']';
            $filters[] = "[bg{$i}][src{$i}b]overlay=(W-w)/2:(H-h)/2[seg{$i}]";
        }

        $concatInputs = implode('', array_map(static fn (int $i): string => "[seg{$i}]", range(0, $count - 1)));
        $filters[] = $concatInputs.'concat=n='.$count.':v=1:a=0[vc]';

        // Subtle slow zoom + pan: crop 93% of the frame and drift toward the
        // bottom-right corner across the WHOLE duration, then restore size.
        $filters[] = '[vc]crop='.(self::WIDTH - 48).':'.(self::HEIGHT - 64)
            .":x='(iw-ow)*t/{$totalDuration}':y='(ih-oh)*t/{$totalDuration}'"
            .',scale='.self::WIDTH.':'.self::HEIGHT.',setsar=1,format=yuv420p[vout]';

        $args[] = '-filter_complex';
        $args[] = implode(';', $filters);

        $args[] = '-map';
        $args[] = '[vout]';
        $args[] = '-map';
        $args[] = (string) $count.':a';

        $args = array_merge($args, [
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '26',
            '-threads', '1',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-af', 'apad',
            '-t', (string) $totalDuration,
            '-r', '25',
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            $outputPath,
        ]);

        $process = new Process($args, timeout: self::ENCODE_TIMEOUT_SECONDS);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg render failed: '.substr($process->getErrorOutput(), 0, 800));
        }
    }

    /**
     * GD pre-processing (runs ONCE per page, never per frame): downscale,
     * rounded corners (alpha mask) and a soft drop shadow baked into a PNG
     * with alpha — so the ffmpeg graph needs NO geq/drawbox tricks.
     */
    private function prepareForeground(string $binary, string $outputPath): string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('ext-gd is required to compose reels.');
        }

        try {
            $src = @imagecreatefromstring($binary);
        } catch (Throwable) {
            $src = false;
        }

        if ($src === false) {
            throw new \RuntimeException('Failed to decode flyer page image for foreground preparation.');
        }

        $srcWidth = (int) imagesx($src);
        $srcHeight = (int) imagesy($src);

        // Prefer filling the FG box: small 400px thumbs are gently upscaled.
        $ratio = min(self::FG_MAX_WIDTH / $srcWidth, self::FG_MAX_HEIGHT / $srcHeight);
        $width = max(1, (int) round($srcWidth * $ratio));
        $height = max(1, (int) round($srcHeight * $ratio));

        $fg = imagecreatetruecolor($width, $height);
        imagealphablending($fg, false);
        imagesavealpha($fg, true);
        imagecopyresampled($fg, $src, 0, 0, 0, 0, $width, $height, $srcWidth, $srcHeight);
        imagedestroy($src);

        // Rounded corners: only the 4 corner squares are touched pixel-wise
        // (~4 × r² pixels) — the body stays untouched, so this is fast.
        self::roundCorners($fg, self::CORNER_RADIUS);

        // Canvas with baked drop shadow: soft black rounded layer offset
        // behind the crisp foreground.
        $canvasW = $width + self::CANVAS_PADDING * 2;
        $canvasH = $height + self::CANVAS_PADDING * 2;
        $canvas = imagecreatetruecolor($canvasW, $canvasH);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $canvasTransparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $canvasTransparent);
        imagealphablending($canvas, true);

        $shadow = self::createRoundedLayer($width, $height, self::CORNER_RADIUS, 0, 0, 0, 82);
        imagecopy($canvas, $shadow, self::CANVAS_PADDING + self::SHADOW_OFFSET_X, self::CANVAS_PADDING + self::SHADOW_OFFSET_Y, 0, 0, $width, $height);
        imagedestroy($shadow);

        imagecopy($canvas, $fg, self::CANVAS_PADDING, self::CANVAS_PADDING, 0, 0, $width, $height);
        imagedestroy($fg);

        $ok = imagepng($canvas, $outputPath, 6);
        imagedestroy($canvas);

        if ($ok === false || ! is_file($outputPath) || filesize($outputPath) === 0) {
            throw new \RuntimeException('Failed to write prepared foreground PNG: '.$outputPath);
        }

        return $outputPath;
    }

    /**
     * Punch rounded-corner transparency into an RGBA truecolor image.
     * Only the 4 corner squares are visited pixel-wise (~4 × r² pixels);
     * the body is never touched, so the cost is negligible.
     */
    private function roundCorners(\GdImage $im, int $r): void
    {
        $width = (int) imagesx($im);
        $height = (int) imagesy($im);

        imagealphablending($im, false);
        imagesavealpha($im, true);

        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        $r2 = $r * $r;

        for ($y = 0; $y < $r; $y++) {
            for ($x = 0; $x < $r; $x++) {
                $dx = $r - $x;
                $dy = $r - $y;

                if ($dx * $dx + $dy * $dy <= $r2) {
                    continue;
                }

                imagesetpixel($im, $x, $y, $transparent);                                   // top-left
                imagesetpixel($im, $width - 1 - $x, $y, $transparent);                      // top-right
                imagesetpixel($im, $x, $height - 1 - $y, $transparent);                     // bottom-left
                imagesetpixel($im, $width - 1 - $x, $height - 1 - $y, $transparent);        // bottom-right
            }
        }
    }

    /**
     * A solid-color RGBA layer shaped as a rounded rectangle (used for the
     * drop shadow): body rectangles + per-pixel arcs in the corner squares.
     */
    private function createRoundedLayer(int $width, int $height, int $r, int $cr, int $cg, int $cb, int $alpha): \GdImage
    {
        $layer = imagecreatetruecolor($width, $height);
        imagealphablending($layer, false);
        imagesavealpha($layer, true);

        $transparent = imagecolorallocatealpha($layer, 0, 0, 0, 127);
        imagefill($layer, 0, 0, $transparent);

        $fill = imagecolorallocatealpha($layer, $cr, $cg, $cb, $alpha);
        imagefilledrectangle($layer, $r, 0, $width - 1 - $r, $height - 1, $fill);
        imagefilledrectangle($layer, 0, $r, $width - 1, $height - 1 - $r, $fill);

        $r2 = $r * $r;
        for ($y = 0; $y < $r; $y++) {
            for ($x = 0; $x < $r; $x++) {
                $dx = $r - $x;
                $dy = $r - $y;

                if ($dx * $dx + $dy * $dy <= $r2) {
                    imagesetpixel($layer, $x, $y, $fill);                                   // top-left
                    imagesetpixel($layer, $width - 1 - $x, $y, $fill);                      // top-right
                    imagesetpixel($layer, $x, $height - 1 - $y, $fill);                     // bottom-left
                    imagesetpixel($layer, $width - 1 - $x, $height - 1 - $y, $fill);        // bottom-right
                }
            }
        }

        return $layer;
    }
}
