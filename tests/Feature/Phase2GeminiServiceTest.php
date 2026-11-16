<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\GeminiRateLimitedException;
use App\Services\GeminiVisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class Phase2GeminiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://magalatna.com',
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-2.5-flash',
        ]);
    }

    public function test_bluf_preserves_decimal_percentages_and_both_sentences(): void
    {
        $text = 'تصفح عروض كازيون الساري في مصر حتى 20 نوفمبر 2026، بخصومات تصل إلى 37.55%. '
            .'يشمل العرض تخفيضات قوية على الزيت والسكر والأرز بجميع الفروع وحتى نفاذ الكمية.';

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $text]]]],
                ],
            ], 200),
        ]);

        $summary = app(GeminiVisionService::class)->generateBlufSummary(
            'كازيون',
            'عروض كازيون الأسبوعية',
            '2026-11-10',
            '2026-11-20',
            3,
            ['زيت', 'سكر', 'أرز'],
            37.55,
        );

        // The decimal point between digits is NOT a sentence boundary — the
        // second sentence must survive and the percentage stays intact.
        $this->assertStringContainsString('37.55%', $summary);
        $this->assertStringContainsString('يشمل العرض تخفيضات قوية على الزيت والسكر والأرز بجميع الفروع وحتى نفاذ الكمية', $summary);
    }

    public function test_gemini_calls_use_header_auth_and_never_query_string_key(): void
    {
        $text = 'تصفح عروض كازيون الساري في مصر حتى 20 نوفمبر، بخصومات تصل إلى 37.55%. '
            .'يشمل العرض تخفيضات قوية على الزيت والسكر والأرز بجميع الفروع وحتى نفاذ الكمية.';

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $text]]]],
                ],
            ], 200),
        ]);

        app(GeminiVisionService::class)->generateBlufSummary(
            'كازيون',
            'عروض كازيون الأسبوعية',
            '2026-11-10',
            '2026-11-20',
            3,
            ['زيت'],
            37.55,
        );

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/')
                && ! str_contains($request->url(), 'key=')
                && $request->hasHeader('x-goog-api-key', 'test-gemini-key');
        });
    }

    public function test_classification_prompt_uses_dynamic_cairo_year_and_magalatna_branding(): void
    {
        $service = new GeminiVisionService;

        $method = new \ReflectionMethod($service, 'classificationPrompt');
        $prompt = (string) $method->invoke($service, 2);

        $this->assertStringContainsString('Assume year '.now('Africa/Cairo')->year, $prompt);
        $this->assertStringContainsString('مجلاتنا', $prompt);
        $this->assertStringNotContainsString('عروض نت', $prompt);
    }

    public function test_rate_limited_response_throws_dedicated_exception(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 429, ['Retry-After' => '45']),
        ]);

        $service = app(GeminiVisionService::class);
        $method = new \ReflectionMethod($service, 'callGemini');

        $this->expectException(GeminiRateLimitedException::class);
        $this->expectExceptionMessage('429');

        $method->invoke($service, ['contents' => []], 'test-gemini-key');
    }
}
