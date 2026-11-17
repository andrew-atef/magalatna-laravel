<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateFlyerReelJob;
use App\Models\Flyer;
use App\Models\FlyerItem;
use App\Models\FlyerPage;
use App\Models\FlyerReel;
use App\Models\Retailer;
use App\Services\GeminiAudioService;
use App\Services\LowMemoryReelComposerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class FlyerReelPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_WAV = 'RIFF____WAVEfmt data____';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-2.5-flash',
            'services.gemini.tts_model' => 'gemini-3.8-flash-tts',
            'services.reels.temp_dir' => storage_path('framework/testing/reels'),
            'services.cloudflare.api_token' => null,
            'services.cloudflare.zone_id' => null,
        ]);

        Storage::fake('r2');
    }

    private function seedFlyer(string $status = 'published'): Flyer
    {
        $retailer = Retailer::query()->create([
            'name' => 'كازيون',
            'slug' => 'kazyon',
        ]);

        $flyer = Flyer::query()->create([
            'retailer_id' => $retailer->id,
            'title' => 'عروض كازيون 17-23 نوفمبر 2026',
            'slug' => 'kazyon-17-23-november-2026',
            'valid_from' => '2026-11-17',
            'valid_until' => '2026-11-23',
            'status' => $status,
            'total_pages' => 2,
        ]);

        FlyerItem::query()->create([
            'flyer_id' => $flyer->id,
            'product_name' => 'زيت عافية 800 مل',
            'slug' => 'zayt-afya-800-ml',
            'sale_price' => 219,
            'old_price' => 244,
            'savings_amount' => 25,
            'discount_percent' => 10.25,
        ]);
        FlyerItem::query()->create([
            'flyer_id' => $flyer->id,
            'product_name' => 'سكر 1 كجم',
            'slug' => 'sukkar-1-kg',
            'sale_price' => 41.95,
            'old_price' => 0,
            'savings_amount' => 0,
            'discount_percent' => 0,
        ]);
        FlyerItem::query()->create([
            'flyer_id' => $flyer->id,
            'product_name' => 'مكرونة رومي 400 جرام',
            'slug' => 'macarona-romy-400g',
            'sale_price' => 12.5,
            'old_price' => 16,
            'savings_amount' => 3.5,
            'discount_percent' => 21.88,
        ]);

        foreach ([1, 2] as $pageNumber) {
            $page = FlyerPage::query()->create([
                'flyer_id' => $flyer->id,
                'page_number' => $pageNumber,
                'image_path' => "flyers/{$flyer->id}/pages/page_{$pageNumber}_master.webp",
                'thumb_path' => "flyers/{$flyer->id}/pages/page_{$pageNumber}_thumb.webp",
            ]);
            Storage::disk('r2')->put($page->thumb_path, 'FAKE_THUMB_'.$pageNumber);
            Storage::disk('r2')->put($page->image_path, 'FAKE_MASTER_'.$pageNumber);
        }

        return $flyer->refresh();
    }

    /**
     * Swap the ffmpeg composer with a deterministic double: records its
     * inputs and "renders" a file, so tests never need a real ffmpeg.
     */
    private function fakeComposer(): object
    {
        $composer = new class extends LowMemoryReelComposerService
        {
            public ?array $lastCall = null;

            public function compose(array $pageImageBinaries, string $audioPath, string $outputPath): array
            {
                if (! is_file($audioPath)) {
                    throw new \RuntimeException('composer double received a missing audio path');
                }

                $this->lastCall = [
                    'pages' => count($pageImageBinaries),
                    'audio_bytes' => filesize($audioPath),
                    'output' => $outputPath,
                ];

                file_put_contents($outputPath, 'FAKE_MP4_BYTES');

                return ['audio_duration' => 14.0, 'video_duration' => 16.5];
            }
        };

        $this->instance(LowMemoryReelComposerService::class, $composer);

        return $composer;
    }

    private function fakeGemini(): void
    {
        $script = 'عرض عرض اليوم الواحد من كازيون نزل رسمي النهارده، وفيه توفير تقيل! زيت عافية بـ 219 جنيه بدل 244، بتوفير 25 جنيه في الباكت. '
            .'مكرونة رومي بـ 12.5 بس بدل 16، بتوفير 3.5 جنيه. سكر بـ 41.95 بس. '
            .'العرض ليوم واحد بس والكمية بتطير! شوف باقي الأسعار والمجلات على منصة مجلاتنا.. الرابط في أول كومنت والبايو.';

        Http::fake([
            // Script generation (default gemini model)
            '*/models/gemini-2.5-flash:generateContent' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $script]]]],
                ],
            ], 200),
            // TTS synthesis (streaming — audio split across 2 chunks to prove
            // the concatenation logic; "Tavi" voice, temperature 1). The
            // mimeType is the REAL Gemini TTS payload: raw PCM, headerless.
            '*/models/gemini-3.8-flash-tts:streamGenerateContent' => (static function () {
                // One continuous base64 stream, split mid-payload exactly like
                // real streamGenerateContent chunks (padding only at the end).
                $full = base64_encode(self::FAKE_WAV);
                $half = intdiv(strlen($full), 2);

                return Http::response([
                    [
                        'candidates' => [
                            ['content' => ['parts' => [
                                ['inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => substr($full, 0, $half)]],
                            ]]],
                        ],
                    ],
                    [
                        'candidates' => [
                            ['content' => ['parts' => [
                                ['inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => substr($full, $half)]],
                            ]]],
                        ],
                    ],
                ], 200);
            })(),
        ]);
    }

    public function test_full_pipeline_generates_ready_reel_and_cleans_temp_files(): void
    {
        $flyer = $this->seedFlyer();
        $composer = $this->fakeComposer();
        $this->fakeGemini();

        $this->artisan('flyers:generate-reel', ['flyer_id' => $flyer->id])
            ->assertSuccessful();

        $reel = FlyerReel::query()->where('flyer_id', $flyer->id)->firstOrFail();

        $this->assertSame(FlyerReel::STATUS_READY, $reel->status);
        $this->assertSame('Tavi', $reel->voice_name);
        $this->assertNotNull($reel->audio_duration);
        $this->assertNotNull($reel->video_duration);
        $this->assertSame('14.00', (string) $reel->audio_duration);
        $this->assertSame('16.50', (string) $reel->video_duration);
        $this->assertNull($reel->error_message);

        // Script is natural Egyptian radio-ad copy with real prices.
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $reel->script_text);
        $this->assertStringContainsString('كازيون', $reel->script_text);
        $this->assertStringContainsString('219', $reel->script_text);
        $this->assertStringContainsString('مجلاتنا', $reel->script_text);

        // Video stored on R2 under flyers/{id}/reels/reel_{ulid}.mp4.
        $this->assertMatchesRegularExpression('#^flyers/'.$flyer->id.'/reels/reel_[A-Z0-9]+\.mp4$#', (string) $reel->video_path);
        $this->assertTrue(Storage::disk('r2')->exists((string) $reel->video_path));
        $this->assertSame('FAKE_MP4_BYTES', Storage::disk('r2')->get((string) $reel->video_path));

        // Composer got BOTH page images and the synthesized audio
        // (PCM payload + 44-byte RIFF/WAVE header).
        $this->assertSame(2, $composer->lastCall['pages']);
        $this->assertSame(strlen(self::FAKE_WAV) + 44, $composer->lastCall['audio_bytes']);

        // Temp files are 100% cleaned up (audio + video).
        $tempDir = (string) config('services.reels.temp_dir');
        $leftovers = array_merge(
            glob($tempDir.'/audio_*') ?: [],
            glob($tempDir.'/reel_*.mp4') ?: [],
        );
        $this->assertSame([], $leftovers, 'Temporary audio/video files must be unlinked.');

        // TTS call carries the exact payload structure + header auth, and the
        // streamed chunks are concatenated into one decoded WAV.
        $ttsSeen = false;
        Http::assertSent(function ($request) use (&$ttsSeen): bool {
            if (! str_contains((string) $request->url(), 'gemini-3.8-flash-tts:streamGenerateContent')) {
                return false;
            }

            $ttsSeen = true;
            $this->assertSame('test-gemini-key', $request->header('x-goog-api-key')[0]);
            $this->assertSame(['audio'], $request['generationConfig']['responseModalities']);
            $this->assertSame(1, $request['generationConfig']['temperature']);
            $this->assertSame('Tavi', $request['generationConfig']['speech_config']['voice_config']['prebuilt_voice_config']['voice_name']);
            $this->assertStringStartsWith('## Transcript:', (string) $request['contents'][0]['parts'][0]['text']);
            $this->assertArrayHasKey('speechMetadata', $request['contents'][0]['parts'][0]);

            return true;
        });

        $this->assertTrue($ttsSeen, 'TTS request was never sent.');
        // Raw PCM is wrapped in a 44-byte RIFF/WAVE header before disk.
        $this->assertSame(strlen(self::FAKE_WAV) + 44, $composer->lastCall['audio_bytes'],
            'Streamed PCM chunks must concatenate and containerize into a full WAV.');
    }

    public function test_command_skips_ready_reel_without_force(): void
    {
        $flyer = $this->seedFlyer();
        $this->fakeComposer();
        $this->fakeGemini();

        $this->artisan('flyers:generate-reel', ['flyer_id' => $flyer->id])->assertSuccessful();

        FlyerReel::query()->where('flyer_id', $flyer->id)->update(['video_path' => 'flyers/x/reels/old.mp4']);

        // Second run without --force must NOT re-generate.
        $this->artisan('flyers:generate-reel', ['flyer_id' => $flyer->id])
            ->expectsOutputToContain('استخدم --force')
            ->assertSuccessful();

        $reel = FlyerReel::query()->where('flyer_id', $flyer->id)->firstOrFail();
        $this->assertSame('flyers/x/reels/old.mp4', $reel->video_path);
    }

    public function test_deleting_flyer_removes_reel_video_from_r2(): void
    {
        $flyer = $this->seedFlyer();
        $this->fakeComposer();
        $this->fakeGemini();

        $this->artisan('flyers:generate-reel', ['flyer_id' => $flyer->id])->assertSuccessful();

        $reel = FlyerReel::query()->where('flyer_id', $flyer->id)->firstOrFail();
        $videoPath = (string) $reel->video_path;
        $this->assertTrue(Storage::disk('r2')->exists($videoPath));

        // Deleting the flyer must reap the reel video from R2.
        $flyer->delete();

        $this->assertFalse(Storage::disk('r2')->exists($videoPath));
        $this->assertDatabaseMissing('flyer_reels', ['id' => $reel->id]);
    }

    public function test_job_skips_draft_flyer(): void
    {
        $flyer = $this->seedFlyer('draft');

        GenerateFlyerReelJob::dispatchSync($flyer->id);

        $this->assertDatabaseMissing('flyer_reels', ['flyer_id' => $flyer->id]);
    }

    public function test_tts_raw_pcm_is_wrapped_in_a_valid_wav_header(): void
    {
        config([
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.tts_model' => 'gemini-3.8-flash-tts',
            'services.reels.temp_dir' => storage_path('framework/testing/reels'),
        ]);

        // 400 bytes of fake 16-bit PCM, streamed as Gemini really serves it.
        $pcm = str_repeat("\x01\x02", 200);

        Http::fake([
            '*/models/gemini-3.8-flash-tts:streamGenerateContent' => Http::response([
                ['candidates' => [['content' => ['parts' => [
                    ['inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => base64_encode($pcm)]],
                ]]]]],
            ], 200),
        ]);

        $result = app(GeminiAudioService::class)->synthesize('عرض من كازيون فيه توفير تقيل');

        try {
            $this->assertStringEndsWith('.wav', $result['path']);

            $bytes = (string) file_get_contents($result['path']);

            $this->assertSame('RIFF', substr($bytes, 0, 4));
            $this->assertSame('WAVE', substr($bytes, 8, 4));
            // fmt chunk: PCM format (1), mono, 24000 Hz, byte rate 48000.
            $this->assertSame(1, unpack('v', substr($bytes, 20, 2))[1]);
            $this->assertSame(1, unpack('v', substr($bytes, 22, 2))[1]);
            $this->assertSame(24000, unpack('V', substr($bytes, 24, 4))[1]);
            $this->assertSame(48000, unpack('V', substr($bytes, 28, 4))[1]);
            // data chunk carries exactly the raw PCM, total = header + payload.
            $this->assertSame(strlen($pcm), unpack('V', substr($bytes, 40, 4))[1]);
            $this->assertSame(44 + strlen($pcm), strlen($bytes));
        } finally {
            @unlink($result['path']);
        }
    }

    public function test_tts_real_wav_and_mp3_payloads_pass_through_untouched(): void
    {
        config([
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.tts_model' => 'gemini-3.8-flash-tts',
            'services.reels.temp_dir' => storage_path('framework/testing/reels'),
        ]);

        $realWav = 'RIFF'.str_repeat("\x00", 40).'DATA'.str_repeat("\x00", 8);

        Http::fake([
            '*/models/gemini-3.8-flash-tts:streamGenerateContent' => Http::response([
                ['candidates' => [['content' => ['parts' => [
                    ['inlineData' => ['mimeType' => 'audio/wav', 'data' => base64_encode($realWav)]],
                ]]]]],
            ], 200),
        ]);

        $result = app(GeminiAudioService::class)->synthesize('عرض من كازيون فيه توفير تقيل');

        try {
            // A genuine RIFF/WAVE payload must NOT get a second header.
            $this->assertStringEndsWith('.wav', $result['path']);
            $this->assertSame(strlen($realWav), filesize($result['path']));
        } finally {
            @unlink($result['path']);
        }
    }
}
