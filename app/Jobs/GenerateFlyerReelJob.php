<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\FlyerStatus;
use App\Models\Flyer;
use App\Models\FlyerReel;
use App\Services\GeminiAudioService;
use App\Services\LowMemoryReelComposerService;
use App\Services\ReelScriptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Memory-controlled pipeline job: Egyptian script → Gemini TTS voiceover →
 * 720x1280 low-RAM ffmpeg render → R2 upload.
 *
 * Deliberately takes ONLY the flyer id (SerializesModels of a heavy Flyer
 * with items would bloat every queued payload); the worker re-hydrates the
 * eager loads it needs.
 */
final class GenerateFlyerReelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(public readonly int $flyerId)
    {
    }

    public function handle(
        ReelScriptService $scriptService,
        GeminiAudioService $audioService,
        LowMemoryReelComposerService $composer,
    ): void {
        $flyer = Flyer::query()
            ->with(['retailer', 'items', 'pages', 'reel'])
            ->find($this->flyerId);

        if ($flyer === null) {
            $this->logSafely('warning', 'GenerateFlyerReelJob: flyer not found, skipping.', ['flyer_id' => $this->flyerId]);

            return;
        }

        if ($flyer->items->isEmpty()) {
            $this->logSafely('warning', 'GenerateFlyerReelJob: flyer has no items, skipping.', ['flyer_id' => $flyer->id]);

            return;
        }

        if ($flyer->status !== FlyerStatus::Published) {
            $this->logSafely('info', 'GenerateFlyerReelJob: flyer is not published, skipping.', [
                'flyer_id' => $flyer->id,
                'status' => $flyer->status->value,
            ]);

            return;
        }

        /** @var FlyerReel $reel */
        $reel = $flyer->reel()->firstOrCreate([], [
            'script_text' => '',
            'status' => FlyerReel::STATUS_PENDING,
        ]);

        $reel->forceFill(['voice_name' => (string) config('services.reels.voice_name', 'Tavi')]);
        $reel->markProcessing();

        $audioPath = null;
        $videoPath = null;

        try {
            // 1. Script + TikTok caption — natural Egyptian radio-ad copy.
            $script = $scriptService->generate($flyer);
            $reel->forceFill([
                'script_text' => $script['script'],
                'caption' => $script['caption'],
            ])->saveQuietly();

            // 2. Voiceover — Gemini Audio synthesis (voice "Tavi").
            $audio = $audioService->synthesize($script['script'], $reel->voice_name);
            $audioPath = $audio['path'];

            // 3. Render — 720x1280, threads=1, veryfast, burned-in captions.
            $pageBinaries = $this->pageBinaries($flyer);
            $tempDir = rtrim((string) config('services.reels.temp_dir', sys_get_temp_dir()), '/\\');
            if (! is_dir($tempDir)) {
                @mkdir($tempDir, 0775, true);
            }
            $videoPath = $tempDir.'/reel_'.(string) Str::ulid().'.mp4';

            $durations = $composer->compose($pageBinaries, $audioPath, $videoPath, $script['script']);

            // 4. Upload to R2 with a NEW ulid per attempt — immutable objects.
            $r2Path = 'flyers/'.$flyer->id.'/reels/reel_'.(string) Str::ulid().'.mp4';
            $stream = fopen($videoPath, 'rb');

            if ($stream === false) {
                throw new \RuntimeException('Cannot open rendered reel for upload: '.$videoPath);
            }

            try {
                Storage::disk('r2')->put($r2Path, $stream, [
                    'ContentType' => 'video/mp4',
                    'visibility' => 'public',
                ]);
            } finally {
                fclose($stream);
            }

            // 5. Persist success BEFORE releasing the R2 path variable.
            $reel->markReady($r2Path, $durations['audio_duration'], $durations['video_duration']);

            $this->logSafely('info', 'GenerateFlyerReelJob: reel ready.', [
                'flyer_id' => $flyer->id,
                'r2_path' => $r2Path,
                'audio_duration' => $durations['audio_duration'],
                'video_duration' => $durations['video_duration'],
                'script_source' => $script['source'],
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            ]);
        } catch (Throwable $e) {
            $reel->markFailed($e->getMessage());

            $this->logSafely('error', 'GenerateFlyerReelJob failed.', [
                'flyer_id' => $flyer->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            // Memory & disk hygiene — ALWAYS, success or failure.
            if ($audioPath !== null) {
                @unlink($audioPath);
            }
            if ($videoPath !== null) {
                @unlink($videoPath);
            }
            gc_collect_cycles();
        }
    }

    /**
     * Up to 4 page images from R2, cover first.
     *
     * MASTERS FIRST: image_path is the full ~1200px page, which is what the
     * composer downscales to 690px (a 400px thumb would have to be enlarged
     * and the prices would look mushy). thumb_path is only a last resort for
     * legacy pages that never got a master uploaded.
     *
     * @return list<string>
     */
    private function pageBinaries(Flyer $flyer): array
    {
        $pages = $flyer->pages()
            ->orderBy('page_number')
            ->limit(4)
            ->get(['id', 'image_path', 'thumb_path']);

        $binaries = [];

        foreach ($pages as $page) {
            $candidates = array_values(array_filter([
                trim((string) $page->image_path),
                trim((string) $page->thumb_path),
            ], static fn (string $p): bool => $p !== ''));

            foreach ($candidates as $path) {
                try {
                    $binary = Storage::disk('r2')->get($path);

                    if (is_string($binary) && $binary !== '') {
                        $binaries[] = $binary;

                        break;
                    }
                } catch (Throwable $e) {
                    $this->logSafely('warning', 'GenerateFlyerReelJob: page image fetch failed.', [
                        'flyer_id' => $flyer->id,
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($binaries === []) {
            throw new \RuntimeException('Flyer #'.$flyer->id.' has no downloadable page images on R2.');
        }

        return $binaries;
    }

    /**
     * Logging must never break the reel pipeline — a broken/read-only log
     * file should degrade to a silent skip instead of masking the real work.
     *
     * @param  array<string, mixed>  $context
     */
    private function logSafely(string $level, string $message, array $context = []): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (Throwable) {
            // Logging unavailable (permissions/disk full) — carry on.
        }
    }
}
