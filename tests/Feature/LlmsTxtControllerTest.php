<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlyerStatus;
use App\Http\Controllers\LlmsTxtController;
use App\Models\Flyer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

final class LlmsTxtControllerTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://magalatna.com']);
    }

    public function test_cache_hit_executes_zero_db_queries(): void
    {
        $this->createPublishedFlyer(['slug' => 'kazyon-live']);

        // Warm the cache.
        $first = $this->get('/llms.txt');
        $first->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $second = $this->get('/llms.txt');

        $second->assertOk();
        $this->assertSame(0, $queries, 'Cache hit on /llms.txt must execute zero DB queries.');
        $this->assertSame('text/plain; charset=UTF-8', $second->headers->get('Content-Type'));
        $this->assertSame('noindex', $second->headers->get('X-Robots-Tag'));
    }

    public function test_excludes_flyers_of_inactive_retailers(): void
    {
        $this->createPublishedFlyer(['is_active' => false, 'slug' => 'dead-retailer']);

        Cache::forget('llms_txt_content');

        $content = (string) $this->get('/llms.txt')->getContent();

        $this->assertStringNotContainsString('dead-retailer', $content);
    }

    public function test_uses_direct_date_comparison_not_where_date(): void
    {
        $controller = new \ReflectionClass(LlmsTxtController::class);
        $source = (string) file_get_contents((string) $controller->getFileName());

        $this->assertStringNotContainsString('whereDate', $source, 'whereDate() defeats the B-Tree index on SQLite DATE columns.');
    }

    public function test_flyer_expiry_boundary_included(): void
    {
        // Flyer expiring exactly today must still appear.
        $this->createPublishedFlyer(['slug' => 'kazyon-live'], [
            'slug' => 'ends-today',
            'status' => FlyerStatus::Published,
            'valid_until' => now('Africa/Cairo')->toDateString(),
        ]);

        Cache::forget('llms_txt_content');

        $content = (string) $this->get('/llms.txt')->getContent();

        $this->assertStringContainsString('ends-today', $content);
    }
}
