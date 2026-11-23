<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\GeminiRateLimitedException;
use App\Services\GeminiVisionService;
use App\Services\ReelScriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

/**
 * Ordered multi-model Gemini waterfall + per-model RPM/RPD circuit breaker.
 *
 * Priority order under test:
 *   #1 gemini-4-hydrogen (5 RPM / 20 RPD)
 *   #2 gemini-3.8-flash  (5 RPM / 20 RPD)
 *   #3 gemini-3.6-flash  (5 RPM / 20 RPD)
 *   #4 gemini-3.5-flash-lite (15 RPM / 500 RPD)
 */
final class GeminiModelFallbackTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://magalatna.com',
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.models' => $this->models(),
        ]);
    }

    /**
     * @return list<array{name: string, rpm: int, rpd: int}>
     */
    private function models(): array
    {
        return [
            ['name' => 'gemini-4-hydrogen', 'rpm' => 5, 'rpd' => 20],
            ['name' => 'gemini-3.8-flash', 'rpm' => 5, 'rpd' => 20],
            ['name' => 'gemini-3.6-flash', 'rpm' => 5, 'rpd' => 20],
            ['name' => 'gemini-3.5-flash-lite', 'rpm' => 15, 'rpd' => 500],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function textResponse(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    private function ptDate(): string
    {
        return now('America/Los_Angeles')->toDateString();
    }

    private function countCallsTo(string $model): int
    {
        return collect(Http::recorded())
            ->filter(static fn (array $pair): bool => str_contains($pair[0]->url(), $model.':generateContent'))
            ->count();
    }

    public function test_healthy_first_model_succeeds_and_lower_models_never_called(): void
    {
        Http::fake([
            '*gemini-4-hydrogen*' => Http::response($this->textResponse('نص عربي من النموذج الأول'), 200),
            '*' => Http::response([], 500),
        ]);

        $result = app(GeminiVisionService::class)->generatePlainText('اكتب جملة');

        $this->assertSame('نص عربي من النموذج الأول', $result);
        Http::assertSentCount(1);
        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'gemini-4-hydrogen:generateContent'));

        // The attempt was budgeted against model #1's own quotas.
        $this->assertSame(1, RateLimiter::attempts('gemini_rpm:gemini-4-hydrogen'));
        $this->assertSame(1, (int) Cache::get('gemini_rpd:gemini-4-hydrogen:'.$this->ptDate()));
    }

    public function test_rpm_429_fails_over_to_second_model_and_cooldown_skips_first_on_next_call(): void
    {
        Http::fake([
            '*gemini-4-hydrogen*' => Http::response([
                'error' => ['message' => 'Resource has been exhausted (e.g. check quota).', 'status' => 'RESOURCE_EXHAUSTED'],
            ], 429),
            '*gemini-3.8-flash*' => Http::response($this->textResponse('نص من النموذج الثاني'), 200),
        ]);

        $service = app(GeminiVisionService::class);

        $first = $service->generatePlainText('السؤال الأول');
        $this->assertSame('نص من النموذج الثاني', $first);
        Http::assertSentCount(2);

        // 429 RPM burst (no Retry-After, no PerDay marker) → 65s cooldown.
        $this->assertSame('429_rpm', Cache::get('gemini_cooldown:gemini-4-hydrogen'));
        $until = (int) Cache::get('gemini_cooldown_until:gemini-4-hydrogen', 0);
        $this->assertGreaterThanOrEqual(time() + 60, $until);
        $this->assertLessThanOrEqual(time() + 66, $until);

        // Next request skips #1 cleanly (cooldown) and goes straight to #2.
        $second = $service->generatePlainText('السؤال الثاني');
        $this->assertSame('نص من النموذج الثاني', $second);
        Http::assertSentCount(3);
        $this->assertSame(1, $this->countCallsTo('gemini-4-hydrogen'));
        $this->assertSame(2, $this->countCallsTo('gemini-3.8-flash'));
    }

    public function test_daily_429_then_503_then_invalid_json_cascades_to_fourth_model(): void
    {
        Http::fake([
            '*gemini-4-hydrogen*' => Http::response([
                'error' => ['message' => 'Quota exceeded for quota metric GenerateRequestsPerDayPerProjectPerModel-FreeTier', 'status' => 'RESOURCE_EXHAUSTED'],
            ], 429, ['Retry-After' => '3600']),
            '*gemini-3.8-flash*' => Http::response(['error' => ['message' => 'Service unavailable']], 503),
            '*gemini-3.6-flash*' => Http::response('THIS IS NOT JSON AT ALL', 200),
            '*gemini-3.5-flash-lite*' => Http::response($this->textResponse('النص النهائي من النموذج الرابع'), 200),
        ]);

        $service = app(GeminiVisionService::class);
        $result = $service->generatePlainText('سؤال');

        $this->assertSame('النص النهائي من النموذج الرابع', $result);
        Http::assertSentCount(4);

        // #1: Google daily quota → local RPD counter pinned to the limit for
        // the rest of the Pacific-Time day.
        $this->assertSame(20, (int) Cache::get('gemini_rpd:gemini-4-hydrogen:'.$this->ptDate()));
        // #2: 503 → short transient cooldown. #3: malformed body → same.
        $this->assertSame('http_503', Cache::get('gemini_cooldown:gemini-3.8-flash'));
        $this->assertSame('invalid_response', Cache::get('gemini_cooldown:gemini-3.6-flash'));

        // A follow-up call skips #1 (daily), #2 and #3 (cooldowns) → #4 again.
        $again = $service->generatePlainText('سؤال آخر');
        $this->assertSame('النص النهائي من النموذج الرابع', $again);
        Http::assertSentCount(5);
        $this->assertSame(2, $this->countCallsTo('gemini-3.5-flash-lite'));
    }

    public function test_all_models_locally_rpm_throttled_throws_without_hitting_api(): void
    {
        foreach ($this->models() as $model) {
            for ($i = 0; $i < $model['rpm']; $i++) {
                RateLimiter::hit('gemini_rpm:'.$model['name'], 60);
            }
        }

        Http::fake();

        try {
            app(GeminiVisionService::class)->generatePlainText('سؤال');
            $this->fail('Expected GeminiRateLimitedException when every model is RPM-throttled.');
        } catch (GeminiRateLimitedException $e) {
            $this->assertGreaterThanOrEqual(15, $e->retryAfterSeconds);
            $this->assertLessThanOrEqual(60, $e->retryAfterSeconds);
        }

        // Zero HTTP traffic — the whole decision was local (<1ms per model).
        Http::assertNothingSent();
    }

    public function test_local_rpd_exhaustion_skips_model_without_http(): void
    {
        Cache::put('gemini_rpd:gemini-4-hydrogen:'.$this->ptDate(), 20, now('America/Los_Angeles')->endOfDay());

        Http::fake([
            '*gemini-3.8-flash*' => Http::response($this->textResponse('من الثاني'), 200),
        ]);

        $result = app(GeminiVisionService::class)->generatePlainText('سؤال');

        $this->assertSame('من الثاني', $result);
        Http::assertSentCount(1);
        $this->assertSame(0, $this->countCallsTo('gemini-4-hydrogen'));
    }

    public function test_auth_error_fails_fast_without_burning_models(): void
    {
        Http::fake([
            '*gemini-4-hydrogen*' => Http::response(['error' => ['message' => 'API key not valid']], 401),
            '*' => Http::response($this->textResponse('يجب ألا يُستدعى'), 200),
        ]);

        try {
            app(GeminiVisionService::class)->generatePlainText('سؤال');
            $this->fail('Expected RuntimeException on 401.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('authentication', $e->getMessage());
        }

        // A bad key is NOT model-specific — never burn #2/#3/#4 on it.
        Http::assertSentCount(1);
    }

    public function test_unsupported_model_gets_six_hour_cooldown_and_fails_over(): void
    {
        Http::fake([
            '*gemini-4-hydrogen*' => Http::response(['error' => ['message' => 'models/unknown is not found']], 404),
            '*gemini-3.8-flash*' => Http::response($this->textResponse('من الثاني'), 200),
        ]);

        $result = app(GeminiVisionService::class)->generatePlainText('سؤال');

        $this->assertSame('من الثاني', $result);
        Http::assertSentCount(2);
        $this->assertSame('unsupported', Cache::get('gemini_cooldown:gemini-4-hydrogen'));
        $until = (int) Cache::get('gemini_cooldown_until:gemini-4-hydrogen', 0);
        $this->assertGreaterThanOrEqual(time() + 21590, $until);
    }

    public function test_classify_post_json_mode_uses_the_waterfall(): void
    {
        $classification = [
            'is_flyer' => true,
            'reason' => 'catalog with prices',
            'flyer_title' => 'عروض كازيون الأسبوعية',
            'valid_from' => '2026-11-20',
            'valid_until' => '2026-11-27',
            'applicable_governorates' => [],
        ];

        Http::fake([
            '*gemini-4-hydrogen*' => Http::response(['error' => ['message' => 'Resource exhausted']], 429),
            '*gemini-3.8-flash*' => Http::response($this->textResponse(json_encode($classification, JSON_UNESCAPED_UNICODE)), 200),
        ]);

        $result = app(GeminiVisionService::class)->classifyPost('عروض كازيون من 20 حتى 27 نوفمبر', [], 1);

        $this->assertTrue($result['is_flyer']);
        $this->assertSame('2026-11-27', $result['valid_until']);
        Http::assertSentCount(2);
        $this->assertSame(1, $this->countCallsTo('gemini-3.8-flash'));
    }

    public function test_reel_script_and_caption_use_the_waterfall(): void
    {
        $flyer = $this->createPublishedFlyer();
        $this->addItem($flyer);

        $script = 'عرض لمدة أسبوع من كازيون نزل رسمي دلوقتي يا جماعة، وفيه توفير تقيل! '
            .'زيت عافية 1.5 لتر بـ 90.5 جنيه بدل 120، وسكر بـ 30 جنيه، وأرز بـ 25 جنيه. '
            .'العرض لمدة أسبوع والكمية بتطير! شوف باقي الأسعار والمجلات على منصة مجلاتنا.. الرابط في أول كومنت والبايو.';
        $caption = "عروض كازيون نزلت والأسعار ولعت\nكل الأسعار كاملة على منصة مجلاتنا — الرابط في أول كومنت والبايو 👇\n#عروض_مصر #عروض_اليوم #مجلاتنا #كازيون";

        Http::fake([
            '*gemini-4-hydrogen*' => Http::sequence()
                ->push($this->textResponse($script), 200)
                ->push($this->textResponse($caption), 200),
            '*' => Http::response([], 500),
        ]);

        $result = app(ReelScriptService::class)->generate($flyer);

        $this->assertSame('gemini', $result['source']);
        $this->assertStringContainsString('زيت عافية', $result['script']);
        $this->assertStringContainsString('#مجلاتنا', $result['caption']);
        // BOTH the script and the caption went through model #1 of the
        // waterfall — never a raw single-model Http::post.
        Http::assertSentCount(2);
        $this->assertSame(2, $this->countCallsTo('gemini-4-hydrogen'));
    }

    public function test_reel_script_fails_over_when_first_model_rate_limited(): void
    {
        $flyer = $this->createPublishedFlyer();
        $this->addItem($flyer);

        $script = 'عرض لمدة أسبوع من كازيون نزل رسمي دلوقتي يا جماعة، وفيه توفير تقيل! '
            .'زيت عافية 1.5 لتر بـ 90.5 جنيه بدل 120، وسكر بـ 30 جنيه، وأرز بـ 25 جنيه. '
            .'العرض لمدة أسبوع والكمية بتطير! شوف باقي الأسعار والمجلات على منصة مجلاتنا.. الرابط في أول كومنت والبايو.';
        $caption = "عروض كازيون نزلت والأسعار ولعت\nكل الأسعار كاملة على منصة مجلاتنا — الرابط في أول كومنت والبايو 👇\n#عروض_مصر #عروض_اليوم #مجلاتنا #كازيون";

        Http::fake([
            '*gemini-4-hydrogen*' => Http::response(['error' => ['message' => 'Resource exhausted']], 429),
            '*gemini-3.8-flash*' => Http::sequence()
                ->push($this->textResponse($script), 200)
                ->push($this->textResponse($caption), 200),
        ]);

        $result = app(ReelScriptService::class)->generate($flyer);

        $this->assertSame('gemini', $result['source']);
        $this->assertStringContainsString('زيت عافية', $result['script']);
        // #1 hit once (429 → cooldown), then #2 served BOTH script + caption.
        Http::assertSentCount(3);
        $this->assertSame(1, $this->countCallsTo('gemini-4-hydrogen'));
        $this->assertSame(2, $this->countCallsTo('gemini-3.8-flash'));
    }

    public function test_config_gemini_models_serialize_cleanly_without_closures(): void
    {
        $config = require config_path('services.php');

        $this->assertIsArray($config['gemini']['models']);
        $this->assertNotEmpty($config['gemini']['models']);

        foreach ($config['gemini']['models'] as $model) {
            $this->assertIsArray($model);
            $this->assertArrayHasKey('name', $model);
            $this->assertArrayHasKey('rpm', $model);
            $this->assertArrayHasKey('rpd', $model);
            $this->assertIsString($model['name']);
            $this->assertIsInt($model['rpm']);
            $this->assertIsInt($model['rpd']);
        }

        // config:cache serializes the whole array — a single Closure anywhere
        // in the gemini block would make `php artisan config:cache` fatal.
        $serialized = serialize($config['gemini']);
        $this->assertIsString($serialized);
        $this->assertSame($config['gemini'], unserialize($serialized));
    }

    public function test_gemini_models_env_parsing_defaults_duplicates_and_fallback(): void
    {
        $keys = ['GEMINI_MODELS', 'GEMINI_MODEL'];
        $saved = [];

        foreach ($keys as $key) {
            $saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        }

        $setEnv = static function (string $key, string $value): void {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        };

        try {
            // Explicit quotas, omitted quotas (→ 5/20), and zero quotas.
            $setEnv('GEMINI_MODELS', 'alpha:1:2,beta,gamma:0:0');
            $config = require config_path('services.php');
            $this->assertSame([
                ['name' => 'alpha', 'rpm' => 1, 'rpd' => 2],
                ['name' => 'beta', 'rpm' => 5, 'rpd' => 20],
                ['name' => 'gamma', 'rpm' => 0, 'rpd' => 0],
            ], $config['gemini']['models']);

            // Duplicate names collapse to their FIRST occurrence.
            $setEnv('GEMINI_MODELS', 'dup:1:2,dup:9:9,other');
            $config = require config_path('services.php');
            $this->assertSame([
                ['name' => 'dup', 'rpm' => 1, 'rpd' => 2],
                ['name' => 'other', 'rpm' => 5, 'rpd' => 20],
            ], $config['gemini']['models']);

            // Empty GEMINI_MODELS → graceful single-model fallback @ 15/500.
            $setEnv('GEMINI_MODELS', '');
            $setEnv('GEMINI_MODEL', 'gemini-test-fallback');
            $config = require config_path('services.php');
            $this->assertSame([
                ['name' => 'gemini-test-fallback', 'rpm' => 15, 'rpd' => 500],
            ], $config['gemini']['models']);
        } finally {
            foreach ($keys as $key) {
                [$envVal, $envConst, $serverConst] = $saved[$key];

                if ($envVal === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$envVal);
                }

                if ($envConst === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $envConst;
                }

                if ($serverConst === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $serverConst;
                }
            }
        }
    }
}
