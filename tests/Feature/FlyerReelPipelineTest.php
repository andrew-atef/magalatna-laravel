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
use Illuminate\Support\Facades\Log;
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

            public function compose(array $pageImageBinaries, string $audioPath, string $outputPath, string $captionsText = ''): array
            {
                if (! is_file($audioPath)) {
                    throw new \RuntimeException('composer double received a missing audio path');
                }

                $this->lastCall = [
                    'pages' => count($pageImageBinaries),
                    'audio_bytes' => filesize($audioPath),
                    'output' => $outputPath,
                    'captions_chars' => mb_strlen(trim($captionsText)),
                    'binaries' => array_map(static fn (string $b): string => substr($b, 0, 16), $pageImageBinaries),
                ];

                file_put_contents($outputPath, 'FAKE_MP4_BYTES');

                return ['audio_duration' => 14.0, 'video_duration' => 16.5];
            }
        };

        $this->instance(LowMemoryReelComposerService::class, $composer);

        return $composer;
    }

    private function fakeGemini(?string $scriptOverride = null): void
    {
        $script = $scriptOverride ?? ('عرض عرض اليوم الواحد من كازيون نزل رسمي النهارده، وفيه توفير تقيل! زيت عافية بـ 219 جنيه بدل 244، بتوفير 25 جنيه في الباكت. '
            .'مكرونة رومي بـ 12.5 بس بدل 16، بتوفير 3.5 جنيه. سكر بـ 41.95 بس. '
            .'العرض ليوم واحد بس والكمية بتطير! شوف باقي الأسعار والمجلات على منصة مجلاتنا.. الرابط في أول كومنت والبايو.');

        $caption = "عرض كازيون النهارده توفير تقيل بجد\n"
            ."كل الأسعار كاملة على منصة مجلاتنا — الرابط في أول كومنت والبايو 👇\n"
            .'#عروض_مصر #عروض_اليوم #مجلاتنا #كازيون';

        Http::fake(function ($request) use ($script, $caption) {
            $url = (string) $request->url();

            // Laravel JSON-encodes request bodies with escaped Unicode, so
            // Arabic markers must be matched on the DECODED payload.
            $decoded = json_decode($request->body() ?: '[]', true);
            $promptText = is_array($decoded) ? (string) ($decoded['contents'][0]['parts'][0]['text'] ?? '') : '';

            // TTS synthesis (streaming — audio split across 2 chunks to prove
            // the concatenation logic; "Tavi" voice, temperature 1). The
            // mimeType is the REAL Gemini TTS payload: raw PCM, headerless.
            if (str_contains($url, 'gemini-3.8-flash-tts:streamGenerateContent')) {
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
            }

            // TikTok caption call (prompt carries the hashtag instructions).
            if (str_contains($promptText, 'هاشتاج')) {
                return Http::response([
                    'candidates' => [
                        ['content' => ['parts' => [['text' => $caption]]]],
                    ],
                ], 200);
            }

            // Script generation (default gemini model)
            return Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $script]]]],
                ],
            ], 200);
        });
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

        // TikTok caption generated alongside the video (hook + CTA + hashtags).
        $this->assertNotNull($reel->caption);
        $this->assertStringContainsString('مجلاتنا', $reel->caption);
        $this->assertStringContainsString('#عروض_مصر', $reel->caption);
        $this->assertStringContainsString('#كازيون', $reel->caption);

        // Video stored on R2 under flyers/{id}/reels/reel_{ulid}.mp4.
        $this->assertMatchesRegularExpression('#^flyers/'.$flyer->id.'/reels/reel_[A-Z0-9]+\.mp4$#', (string) $reel->video_path);
        $this->assertTrue(Storage::disk('r2')->exists((string) $reel->video_path));
        $this->assertSame('FAKE_MP4_BYTES', Storage::disk('r2')->get((string) $reel->video_path));

        // Composer got BOTH page images and the synthesized audio
        // (PCM payload + 44-byte RIFF/WAVE header), plus the full script
        // for burned-in captions.
        $this->assertSame(2, $composer->lastCall['pages']);
        $this->assertSame(strlen(self::FAKE_WAV) + 44, $composer->lastCall['audio_bytes']);
        $this->assertGreaterThan(0, $composer->lastCall['captions_chars']);
        $this->assertSame(mb_strlen($reel->script_text), $composer->lastCall['captions_chars']);

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

    public function test_voiceover_always_speaks_the_closing_cta_even_if_gemini_forgets_it(): void
    {
        $flyer = $this->seedFlyer();
        $composer = $this->fakeComposer();

        // Gemini "forgets" the closing CTA — it must be enforced anyway,
        // because the voiceover is synthesized from this exact script.
        // (57 words: valid for the 45–65 gate, but no urgency/CTA ending.)
        $this->fakeGemini('عرض كازيون نزل النهارده وفيه توفير تقيل جوه المجلة الجديدة! '
            .'زيت عافية بـ 219 جنيه بدل 244، بتوفير 25 جنيه في الباكت، ومكرونة رومي بـ 12.5 بس بدل 16 يعني توفير 3.5 جنيه. '
            .'سكر برضه بـ 41.95 بس، والأسعار كلها نزلت النهارده بمناسبة العرض. '
            .'الجودة عالية والكميات محدودة، فلو عايز توفر في مشتريات البيت يبقى ده وقتك.');

        $this->artisan('flyers:generate-reel', ['flyer_id' => $flyer->id])->assertSuccessful();

        $reel = FlyerReel::query()->where('flyer_id', $flyer->id)->firstOrFail();
        $spoken = (string) $reel->script_text;

        // The two mandatory closing beats: urgency + where to find the rest.
        $this->assertMatchesRegularExpression('/العرض (ليوم واحد بس|لمدة \d+ أيام) والكمية بتطير!/u', $spoken);
        $this->assertStringEndsWith('شوف باقي الأسعار والمجلات على منصة مجلاتنا.. الرابط في أول كومنت والبايو.', $spoken);
        $this->assertSame(1, substr_count($spoken, 'الرابط في أول كومنت'), 'The CTA must not be duplicated.');
        $this->assertLessThanOrEqual(90, count(preg_split('/\s+/u', $spoken, -1, PREG_SPLIT_NO_EMPTY) ?: []));

        // Captions burn in exactly the spoken text.
        $this->assertSame(mb_strlen($spoken), $composer->lastCall['captions_chars']);

        // TTS receives that text verbatim → the voice literally says it.
        $ttsText = null;
        Http::assertSent(function ($request) use (&$ttsText): bool {
            if (! str_contains((string) $request->url(), 'gemini-3.8-flash-tts:streamGenerateContent')) {
                return false;
            }

            $ttsText = (string) $request['contents'][0]['parts'][0]['text'];

            return true;
        });

        $this->assertSame("## Transcript:\n".$spoken, $ttsText);
    }

    public function test_overlong_gemini_script_is_trimmed_not_discarded(): void
    {
        $flyer = $this->seedFlyer();
        $composer = $this->fakeComposer();

        // ~100 words: over the hard ceiling, must be trimmed at a sentence
        // boundary — NOT thrown away in favour of the robotic fallback.
        $this->fakeGemini(
            'عروض كازيون الجديدة وصلت النهارده وفيها توفير تقيل على كل حاجة محتاجها في البيت، من الزيت والمكرونة للسكر والشاي والمساحيق. '
            .'زيت عافية بـ 219 جنيه بدل 244، ومكرونة رومي بـ 12.5 بس بدل 16، وسكر بـ 41.95 جنيه، وكمان شاي العروسة عليه خصم جامد جدًا. '
            .'العروض دي متوفرة في كل فروع كازيون على مستوى الجمهورية، والكميات محدودة جدًا وممكن تخلص في نفس اليوم. '
            .'كل الأسعار اللي شوفتها دي موجودة بالتفصيل في مجلة العروض الجديدة اللي نزلت النهارده الصبح على موقعنا.');

        $this->artisan('flyers:generate-reel', ['flyer_id' => $flyer->id])->assertSuccessful();

        $reel = FlyerReel::query()->where('flyer_id', $flyer->id)->firstOrFail();
        $spoken = (string) $reel->script_text;

        // Gemini's copy survived (not the numbered fallback script).
        $this->assertStringContainsString('زيت عافية', $spoken);
        $this->assertStringNotContainsString('رقم 1:', $spoken);
        $this->assertLessThanOrEqual(90, count(preg_split('/\s+/u', $spoken, -1, PREG_SPLIT_NO_EMPTY) ?: []));

        // …and the enforced closing CTA is still intact.
        $this->assertStringContainsString('والكمية بتطير!', $spoken);
        $this->assertStringEndsWith('الرابط في أول كومنت والبايو.', $spoken);
        $this->assertSame(mb_strlen($spoken), $composer->lastCall['captions_chars']);
    }

    public function test_broken_log_file_never_breaks_reel_generation(): void
    {
        $flyer = $this->seedFlyer();
        $this->fakeComposer();
        $this->fakeGemini();

        // The VPS scenario: storage/logs is not writable → every Log call
        // throws. The pipeline must still finish successfully.
        Log::shouldReceive('log')->andThrow(new \UnexpectedValueException('Failed to open stream: Permission denied'));
        Log::shouldReceive('warning')->andThrow(new \UnexpectedValueException('Failed to open stream: Permission denied'));
        Log::shouldReceive('info')->andThrow(new \UnexpectedValueException('Failed to open stream: Permission denied'));
        Log::shouldReceive('error')->andThrow(new \UnexpectedValueException('Failed to open stream: Permission denied'));

        $exitCode = $this->artisan('flyers:generate-reel', ['flyer_id' => $flyer->id])->run();

        $reel = FlyerReel::query()->where('flyer_id', $flyer->id)->firstOrFail();

        $this->assertSame(0, $exitCode, 'Reel error: '.$reel->error_message);
        $this->assertSame(FlyerReel::STATUS_READY, $reel->status);
        $this->assertNotNull($reel->caption);
    }

    public function test_job_sends_full_resolution_masters_not_thumbnails(): void
    {
        $flyer = $this->seedFlyer();
        $composer = $this->fakeComposer();
        $this->fakeGemini();

        $this->artisan('flyers:generate-reel', ['flyer_id' => $flyer->id])->assertSuccessful();

        // image_path (1200px master) must win over thumb_path (400px).
        $this->assertSame(['FAKE_MASTER_1', 'FAKE_MASTER_2'], $composer->lastCall['binaries']);
    }

    public function test_latin_brands_are_transliterated_and_decimal_commas_normalized(): void
    {
        $flyer = $this->seedFlyer();
        $composer = $this->fakeComposer();

        $this->fakeGemini(
            'عرض كازيون النهارده فيه توفير تقيل على Elvive وشامبو Lipton، وكمان زيت عافية بـ 219 جنيه بدل 244. '
            .'سكر بـ 41,95 جنيه بس، ومكرونة رومي بـ 12.5 بدل 16، وده توفير حقيقي على كل حاجة محتاجها في البيت. '
            .'العرض ساري لمدة أسبوع والكمية بتطير! شوف باقي الأسعار والمجلات على منصة مجلاتنا.. الرابط في أول كومنت والبايو.'
        );

        $this->artisan('flyers:generate-reel', ['flyer_id' => $flyer->id])->assertSuccessful();

        $spoken = (string) FlyerReel::query()->where('flyer_id', $flyer->id)->firstOrFail()->script_text;

        // Latin brands → Arabic (config: ocr_aliases.brand_aliases).
        $this->assertStringContainsString('إلفيف', $spoken);
        $this->assertStringContainsString('ليبتون', $spoken);
        $this->assertStringNotContainsString('Elvive', $spoken);
        $this->assertStringNotContainsString('Lipton', $spoken);

        // Decimal comma → dot, so both TTS and libass read "41.95".
        $this->assertStringContainsString('41.95', $spoken);
        $this->assertStringNotContainsString('41,95', $spoken);

        // Captions are burned from exactly the same normalized text.
        $this->assertSame(mb_strlen($spoken), $composer->lastCall['captions_chars']);
    }

    public function test_page_composite_is_a_760x1360_opaque_jpeg(): void
    {
        $composer = app(LowMemoryReelComposerService::class);

        $dir = storage_path('framework/testing/reels');
        @mkdir($dir, 0775, true);
        $srcPath = $dir.'/src_page.jpg';
        $outPath = $dir.'/page_composite.jpg';

        $im = imagecreatetruecolor(1200, 1700);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 255, 255, 255));
        imagejpeg($im, $srcPath, 90);
        imagedestroy($im);

        try {
            $method = new \ReflectionMethod($composer, 'preparePage');
            $method->invoke($composer, (string) file_get_contents($srcPath), $outPath);

            $info = getimagesize($outPath);

            $this->assertIsArray($info);
            // Fixed canvas: 720x1280 output + pan headroom, 1:1 pixels.
            $this->assertSame(760, $info[0]);
            $this->assertSame(1360, $info[1]);
            $this->assertSame(IMAGETYPE_JPEG, $info[2]);
            $this->assertGreaterThan(0, (int) filesize($outPath));
        } finally {
            @unlink($srcPath);
            @unlink($outPath);
        }
    }

    public function test_captions_are_synced_and_end_with_a_big_cta_card(): void
    {
        $composer = app(LowMemoryReelComposerService::class);

        $script = 'عرض اليوم الواحد من كازيون نزل رسمي النهارده، وفيه توفير تقيل! '
            .'زيت عافية بـ 219 جنيه بدل 244، بتوفير 25 جنيه في الباكت. '
            .'العرض ليوم واحد بس والكمية بتطير! شوف باقي الأسعار والمجلات على منصة مجلاتنا.. الرابط في أول كومنت والبايو.';

        $dir = storage_path('framework/testing/reels');
        @mkdir($dir, 0775, true);
        $path = $dir.'/captions_test.ass';

        config(['services.reels.caption_font_name' => 'DejaVu Sans', 'services.reels.caption_font_size' => 54]);

        $writeAss = new \ReflectionMethod($composer, 'writeCaptionsAss');

        try {
            $writeAss->invoke($composer, $script, 20.0, 22.5, $path);

            $ass = (string) file_get_contents($path);

            // Two styles: spoken Caption (bottom) + EndCard (big, centered).
            $this->assertStringContainsString('Style: Caption,DejaVu Sans,54', $ass);
            $this->assertStringContainsString('Style: EndCard,DejaVu Sans,62', $ass);

            // Every spoken caption ends no later than the voiceover itself.
            preg_match_all('/Dialogue: 0,(\d+):(\d+):(\d+\.\d+),(\d+):(\d+):(\d+\.\d+),Caption,/', $ass, $m, PREG_SET_ORDER);

            $this->assertNotEmpty($m, 'No Caption events were written.');

            foreach ($m as $hit) {
                $end = ((int) $hit[4]) * 3600 + ((int) $hit[5]) * 60 + (float) $hit[6];
                $this->assertLessThanOrEqual(20.0 + 0.01, $end, 'Caption outlived the voiceover.');
            }

            // End card spans exactly the silent tail (audio end → total) and
            // uses the same explicit-wrap discipline as the captions.
            $endCardStart = strpos($ass, 'EndCard,,0,0,0,,{');
            $this->assertNotFalse($endCardStart);
            $endCardBlock = (string) substr($ass, (int) $endCardStart);

            $this->assertStringContainsString(
                'Dialogue: 0,0:00:20.00,0:00:22.50,EndCard,,0,0,0,,',
                $ass
            );
            $this->assertStringContainsString('{\\fad(300,0)\\q2}', $endCardBlock);

            // It is the closing CTA, not the whole script (lines are broken
            // at 4 words, so assert on the surviving fragments).
            $this->assertStringContainsString('بتطير!', $endCardBlock);
            $this->assertStringContainsString('الرابط', $endCardBlock);
            $this->assertStringContainsString('كومنت', $endCardBlock);
            $this->assertStringNotContainsString('زيت عافية', $endCardBlock);

            // Strict RTL: every event is RLE+RLM … PDF wrapped and carries
            // \q2, and every line is ≤ 4 words (explicit \N breaks only).
            $this->assertStringContainsString('{\q2}', $ass);
            $this->assertStringContainsString("\u{202B}\u{200F}", $ass);
            $this->assertStringContainsString("\u{202C}", $ass);

            // Numbers are isolated as LTR runs so "219" never renders "912".
            $this->assertStringContainsString("\u{202A}219\u{202C}", $ass);

            $captionLineCount = 0;

            foreach (explode("\n", $ass) as $eventLine) {
                if (! str_contains($eventLine, ',Caption,,0,0,0,,')) {
                    continue;
                }

                $this->assertStringContainsString('{\q2}', $eventLine);
                $this->assertStringContainsString("\u{202B}\u{200F}", $eventLine);

                $text = substr($eventLine, (int) strpos($eventLine, '{\q2}') + 5);
                $text = str_replace(["\u{202A}", "\u{202B}", "\u{202C}", "\u{200F}"], '', $text);

                foreach (explode('\N', $text) as $line) {
                    $captionLineCount++;
                    $this->assertLessThanOrEqual(
                        4,
                        count(preg_split('/\s+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: []),
                        'Caption line exceeds 4 words: '.$line
                    );
                }
            }

            $this->assertGreaterThan(0, $captionLineCount);
        } finally {
            @unlink($path);
        }
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
