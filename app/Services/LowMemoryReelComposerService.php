<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
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
 *  - SHARPNESS RULE: the flyer foreground is NEVER resampled by ffmpeg — it
 *    is overlaid 1:1 on a 720x1280 canvas. The slow pan/zoom motion is
 *    applied ONLY to the blurred background (800x1422 → animated 720x1280
 *    crop), because upscaling text is what made prices look mushy.
 *  - Captions are strictly RTL: every line is wrapped in RLE+RLM … PDF and
 *    every Latin/numeric run is isolated with LRE … PDF, then `\q2` disables
 *    libass auto-wrap (we insert explicit breaks, max 4 words per line).
 *  - Every temporary file lives in a configurable temp dir and is the
 *    CALLER's responsibility to unlink (the job wraps compose() in
 *    try/finally).
 */
class LowMemoryReelComposerService
{
    /** 720x1280 locked — RAM-safety is a hard requirement, never negotiate. */
    private const WIDTH = 720;

    private const HEIGHT = 1280;

    /** Foreground crisp layer max box — fills most of the 720x1280 frame.
     *  Masters arrive at 1200px, so this is always a DOWNSCALE (zero upscaling). */
    private const FG_MAX_WIDTH = 690;

    private const FG_MAX_HEIGHT = 1160;

    /** Oversized blurred backdrop — gives the animated crop room to drift. */
    private const BG_WIDTH = 800;

    private const BG_HEIGHT = 1422;

    private const CORNER_RADIUS = 28;

    private const SHADOW_OFFSET_X = 6;

    private const SHADOW_OFFSET_Y = 8;

    private const CANVAS_PADDING = 18;

    private const TRAIL_SECONDS = 2.5;

    private const MAX_PAGES = 4;

    private const ENCODE_TIMEOUT_SECONDS = 270;

    /** Burned-in caption styling (ASS, PlayRes 720x1280). */
    private const CAPTION_FONT_SIZE = 54;

    private const CAPTION_OUTLINE = 4;

    private const CAPTION_SHADOW = 2;

    private const CAPTION_MARGIN_V = 150;

    private const CAPTION_MARGIN_H = 36;

    /** Max words per burned-in caption line (bidi-safe short lines). */
    private const CAPTION_MAX_WORDS = 4;

    /** End-card styling: last 2.5s, big centered CTA, fades in. */
    private const END_CARD_FONT_SIZE = 62;

    private const END_CARD_MAX_WORDS = 28;

    private const END_CARD_FADE_MS = 300;

    /**
     * @param  list<string>  $pageImageBinaries  Raw image bytes, priority order (cover first).
     * @param  string  $audioPath  Temporary voiceover file (wav/mp3).
     * @param  string  $outputPath  Temporary MP4 output path (caller unlinks it).
     * @param  string  $captionsText  Optional script text burned in as big
     *   white bold captions timed across the voiceover (ASS subtitles).
     * @return array{audio_duration: float, video_duration: float}
     *
     * @throws \RuntimeException
     */
    public function compose(array $pageImageBinaries, string $audioPath, string $outputPath, string $captionsText = ''): array
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
        $captionsPath = $tempDir.'/'.$ulid.'_captions.ass';

        try {
            foreach (array_slice($pageImageBinaries, 0, self::MAX_PAGES) as $index => $binary) {
                $foregroundPaths[] = $this->prepareForeground($binary, $tempDir.'/'.$ulid.'_'.$index.'.png');
            }

            $assFile = trim($captionsText) !== ''
                ? $this->writeCaptionsAss($captionsText, $audioDuration, $totalDuration, $captionsPath)
                : null;

            try {
                $this->runFfmpeg($foregroundPaths, $audioPath, $outputPath, $totalDuration, $assFile);
            } catch (\RuntimeException $e) {
                // libass/ASS filter unavailable or font missing on this box —
                // the reel is still worth shipping: retry WITHOUT captions.
                if ($assFile === null) {
                    throw $e;
                }

                try {
                    Log::warning('ReelComposer: captioned render failed, retrying without burned-in captions.', [
                        'error' => substr($e->getMessage(), 0, 400),
                    ]);
                } catch (Throwable) {
                    // Logging must never block the graceful fallback.
                }

                $this->runFfmpeg($foregroundPaths, $audioPath, $outputPath, $totalDuration, null);
            }

            if (! is_file($outputPath) || filesize($outputPath) === 0) {
                throw new \RuntimeException('ffmpeg produced no output video.');
            }

            return ['audio_duration' => $audioDuration, 'video_duration' => $totalDuration];
        } finally {
            // Memory & disk hygiene: foreground PNGs never survive compose().
            foreach ($foregroundPaths as $path) {
                @unlink($path);
            }
            @unlink($captionsPath);
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
     *   per page: split → [bg: scale 800x1422 + blur + animated 720x1280 crop]
     *                       overlay [crisp fg, 1:1, no resampling] → segment
     *   all segments → concat → ASS captions → 720x1280
     *
     * The Process runs with cwd = temp dir so the ASS filter can reference
     * the captions file by bare relative filename (no path-escaping traps).
     *
     * @param  list<string>  $foregroundPaths
     */
    private function runFfmpeg(array $foregroundPaths, string $audioPath, string $outputPath, float $totalDuration, ?string $assFile): void
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

            // Background: blurred + slightly oversized (800x1422) so the
            // animated 720x1280 crop never exposes an edge.
            $filters[] = "[src{$i}a]scale=".self::BG_WIDTH.':'.self::BG_HEIGHT
                .':force_original_aspect_ratio=increase,crop='.self::BG_WIDTH.':'.self::BG_HEIGHT
                .',boxblur=20:5,eq=brightness=-0.06,setsar=1[bgfull'.$i.']';

            // ALL motion lives here: a slow drift across the oversized,
            // already-blurred backdrop. Drift resets per page (each input has
            // its own timeline) which reads as a natural pan per slide.
            $filters[] = "[bgfull{$i}]crop=".self::WIDTH.':'.self::HEIGHT
                .":x='(iw-ow)*t/{$segmentDuration}':y='(ih-oh)*t/{$segmentDuration}'[bg{$i}]";

            // Foreground is overlaid 1:1 — NO crop/scale means zero blur.
            $filters[] = "[bg{$i}][src{$i}b]overlay=(W-w)/2:(H-h)/2[seg{$i}]";
        }

        $concatInputs = implode('', array_map(static fn (int $i): string => "[seg{$i}]", range(0, $count - 1)));
        $filters[] = $concatInputs.'concat=n='.$count.':v=1:a=0[vc]';

        // No motion pass here: the frames are already final-size and crisp.
        $tailChain = '[vc]setsar=1';

        if ($assFile !== null) {
            // Note: NO fontsdir option — several libass builds mis-parse it
            // and kill the whole track. libass resolves Fontname through the
            // system font provider instead (fontconfig on Linux VPS,
            // directwrite on Windows), so install the Arabic font system-wide
            // (e.g. `apt install fonts-noto-core`) and set REELS_CAPTION_FONT_NAME.
            $tailChain .= ',ass='.basename($assFile);
        }

        $tailChain .= ',format=yuv420p[vout]';

        $filters[] = $tailChain;

        $args[] = '-filter_complex';
        $args[] = implode(';', $filters);

        $args[] = '-map';
        $args[] = '[vout]';
        $args[] = '-map';
        $args[] = (string) $count.':a';

        $args = array_merge($args, [
            '-c:v', 'libx264',
            // Text/price-tag friendly: slower preset + stillimage tuning keeps
            // sharp glyph/price edges at CRF 20. -threads 1 keeps RAM flat.
            '-preset', 'fast',
            '-crf', '20',
            '-tune', 'stillimage',
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

        $process = new Process($args, cwd: dirname($outputPath), timeout: self::ENCODE_TIMEOUT_SECONDS);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg render failed: '.substr($process->getErrorOutput(), 0, 1500));
        }
    }

    /**
     * Writes an .ass subtitle file that syncs the script to the voiceover.
     *
     * Timing has no word-level timestamps available, so each caption chunk
     * is allotted a slice of the audio duration PROPORTIONAL TO ITS WORD
     * COUNT — the standard karaoke-free approximation for TTS narration.
     *
     * Style: big white bold text, heavy black outline + soft shadow,
     * bottom-center (below the flyer frame), Arabic-safe encoding=1.
     */
    private function writeCaptionsAss(string $scriptText, float $audioDuration, float $totalDuration, string $path): string
    {
        $segments = $this->buildCaptionSegments($scriptText);

        if ($segments === []) {
            throw new \RuntimeException('Caption text produced no segments.');
        }

        $fontName = trim((string) config('services.reels.caption_font_name', 'Noto Sans Arabic'));
        $fontSize = max(24, (int) config('services.reels.caption_font_size', self::CAPTION_FONT_SIZE));
        $endCardSize = max(28, (int) config('services.reels.endcard_font_size', self::END_CARD_FONT_SIZE));

        // Total speech weight (words) → per-segment proportional time slice.
        $weights = array_map(static fn (string $s): int => count(preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: []), $segments);
        $totalWeight = array_sum($weights) ?: 1;

        $events = '';
        $cursor = 0.0;

        foreach ($segments as $index => $text) {
            $duration = ($weights[$index] / $totalWeight) * $audioDuration;
            $start = $cursor;
            $cursor = min($audioDuration, $cursor + $duration);

            $events .= 'Dialogue: 0,'.self::assTime($start).','.self::assTime($cursor)
                .',Caption,,0,0,0,,{\q2}'.self::assBidiLines($text, self::CAPTION_MAX_WORDS)."\n";
        }

        // END CARD: while the voiceover is over (the 2.5s tail) the CTA is
        // shown big and centered, so viewers read where to find the prices.
        $endCard = $this->buildEndCardText($scriptText);

        if ($endCard !== '' && $totalDuration > $audioDuration) {
            $events .= 'Dialogue: 0,'.self::assTime($audioDuration).','.self::assTime($totalDuration)
                .',EndCard,,0,0,0,,{\\fad('.self::END_CARD_FADE_MS.',0)\\q2}'
                .self::assBidiLines($endCard, self::CAPTION_MAX_WORDS)."\n";
        }

        $ass = "[Script Info]\n"
            ."; Auto-generated by LowMemoryReelComposerService\n"
            ."ScriptType: v4.00+\n"
            ."PlayResX: ".self::WIDTH."\n"
            ."PlayResY: ".self::HEIGHT."\n"
            ."WrapStyle: 0\n"
            ."ScaledBorderAndShadow: yes\n\n"
            ."[V4+ Styles]\n"
            ."Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
            ."Style: Caption,".$fontName.",".$fontSize
            .",&H00FFFFFF,&H00FFFFFF,&H00000000,&H96000000"   // white text, black outline, soft shadow
            .",1,0,0,0,100,100,0,0,1,".self::CAPTION_OUTLINE.",".self::CAPTION_SHADOW
            .",2,".self::CAPTION_MARGIN_H.",".self::CAPTION_MARGIN_H.",".self::CAPTION_MARGIN_V.",1\n"
            ."Style: EndCard,".$fontName.",".$endCardSize
            .",&H00FFFFFF,&H00FFFFFF,&H00000000,&H96000000"
            .",1,0,0,0,100,100,0,0,1,5,2"
            .",5,".self::CAPTION_MARGIN_H.",".self::CAPTION_MARGIN_H.",0,1\n\n"
            ."[Events]\n"
            ."Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n"
            .$events;

        if (file_put_contents($path, $ass) === false || filesize($path) === 0) {
            throw new \RuntimeException('Failed to write ASS captions file: '.$path);
        }

        return $path;
    }

    /**
     * End card copy: the script's last sentences (the closing hook +
     * "شوف باقي الأسعار… الرابط في أول كومنت والبايو"), capped so it always
     * fits the frame at END_CARD_FONT_SIZE.
     */
    private function buildEndCardText(string $scriptText): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $scriptText));

        if ($text === '') {
            return '';
        }

        $sentences = array_values(array_filter(array_map(
            'trim',
            preg_split('/(?<=[.!؟])\s+/u', $text) ?: [$text]
        ), static fn (string $s): bool => $s !== ''));

        $closing = implode(' ', array_slice($sentences === [] ? [$text] : $sentences, -3));
        $words = preg_split('/\s+/u', $closing, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) > self::END_CARD_MAX_WORDS) {
            $words = array_slice($words, -self::END_CARD_MAX_WORDS);
        }

        return trim(implode(' ', $words));
    }

    /**
     * Split the script into readable caption chunks: sentence boundaries
     * first, then groups of ≤ CAPTION_MAX_WORDS.
     *
     * @return list<string>
     */
    private function buildCaptionSegments(string $text): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return [];
        }

        $sentences = preg_split('/(?<=[.!؟])\s+/u', $text) ?: [$text];
        $segments = [];

        foreach ($sentences as $sentence) {
            $words = preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach (array_chunk($words, self::CAPTION_MAX_WORDS) as $chunk) {
                $segments[] = implode(' ', $chunk);
            }
        }

        return array_values(array_filter(array_map('trim', $segments)));
    }

    /**
     * ASS timestamp: H:MM:SS.cc (centiseconds).
     */
    private static function assTime(float $seconds): string
    {
        $seconds = max(0.0, $seconds);
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);
        $s = fmod($seconds, 60);

        return sprintf('%d:%02d:%05.2f', $h, $m, $s);
    }

    /**
     * ASS Dialogue text field: braces are override tags and line breaks
     * would end the event — flatten both.
     */
    private static function assEscape(string $text): string
    {
        return str_replace(['{', '}', "\r", "\n"], ['(', ')', '', ' '], trim($text));
    }

    /**
     * ASS text field for one event: explicit `\N` breaks of at most
     * $maxWords words, with EACH line bidi-isolated independently. Keeping
     * the breaks explicit (paired with `\q2`) means libass can never pick
     * its own wrap points inside a mixed Arabic/number sentence.
     */
    private static function assBidiLines(string $text, int $maxWords): string
    {
        return implode('\\N', array_map(
            static fn (string $line): string => self::assBidiLine($line),
            self::splitLines($text, $maxWords)
        ));
    }

    /**
     * @return list<string>
     */
    private static function splitLines(string $text, int $maxWords): array
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($clean === '') {
            return [];
        }

        $words = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return [];
        }

        return array_map(
            static fn (array $chunk): string => implode(' ', $chunk),
            array_chunk($words, max(1, $maxWords))
        );
    }

    /**
     * Strict-RTL line for libass/FriBidi:
     *  1. every LTR run (Latin word, decimal like 41.95, compound figure like
     *     6×500) is isolated with LRE … PDF so its characters stay in order;
     *  2. the whole line is wrapped in RLE + RLM … PDF so the paragraph
     *     direction can never flip to LTR — even when the line starts with a
     *     number, `..` or `!` (the classic causes of mirrored punctuation and
     *     reversed word order in burned-in Arabic subtitles).
     */
    private static function assBidiLine(string $line): string
    {
        $line = self::assEscape($line);

        $isolated = (string) preg_replace_callback(
            '/[A-Za-z0-9]+(?:[._\/:×*+\-][A-Za-z0-9]+)*/u',
            static fn (array $match): string => "\u{202A}".$match[0]."\u{202C}",
            $line
        );

        return "\u{202B}\u{200F}".$isolated."\u{202C}";
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

        // Masters arrive at 1200px wide, so this is a pure DOWNSCALE
        // (ratio capped at 1.0) — never stretch a 400px thumb into mush.
        $ratio = min(1.0, self::FG_MAX_WIDTH / $srcWidth, self::FG_MAX_HEIGHT / $srcHeight);
        $width = max(1, (int) round($srcWidth * $ratio));
        $height = max(1, (int) round($srcHeight * $ratio));

        $fg = imagecreatetruecolor($width, $height);
        imagealphablending($fg, false);
        imagesavealpha($fg, true);

        // Bicubic/Lanczos resampling keeps the price tags and product text
        // crisp when shrinking the 1200px page into the 690px box.
        if (function_exists('imagesetinterpolation')) {
            $method = defined('IMG_LANCZOS4')
                ? IMG_LANCZOS4
                : (defined('IMG_BICUBIC') ? IMG_BICUBIC : IMG_BILINEAR);

            imagesetinterpolation($fg, $method);
        }

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
