<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

/**
 * Discover hero upgrade: the 1280x720 composite renders INSIDE the page as
 * the elegant pre-cover band (eager + fetchpriority=high), explicit
 * og:image:width/height meta tags land in <head> for every crawler, and the
 * pages grid collapses to ONE image per row on mobile.
 */
final class DiscoverHeroRenderTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://magalatna.com']);
    }

    public function test_discover_hero_renders_with_lcp_priority_and_exact_meta_dimensions(): void
    {
        $flyer = $this->createPublishedFlyer([], [
            'slug' => 'discover-hero-flyer',
            'discover_image_path' => 'flyers/99/discover_testulid.webp',
        ]);
        $this->addPage($flyer, 'flyers/pages/cover.webp');
        $this->addPage($flyer, 'flyers/pages/p2.webp', 2);

        $content = (string) $this->get('/offers/discover-hero-flyer')->getContent();

        // <head>: explicit dimensions matching the REAL 1280x720 file.
        $this->assertStringContainsString('<meta property="og:image:width" content="1280">', $content);
        $this->assertStringContainsString('<meta property="og:image:height" content="720">', $content);

        // og:image points at the composite itself.
        $this->assertStringContainsString(
            'property="og:image" content="https://cdn.magalatna.com/flyers/99/discover_testulid.webp"',
            $content,
        );

        // In-page hero: eager + fetchpriority=high at the START of #flyer-viewer.
        // Search AFTER the schema JSON-LD, which also references the same URLs.
        $viewerPos = mb_strpos($content, 'id="flyer-viewer"');
        $this->assertNotFalse($viewerPos);
        $heroPos = mb_strpos($content, 'discover_testulid.webp', $viewerPos);
        $coverPos = mb_strpos($content, 'flyers/pages/cover.webp', $viewerPos);
        $this->assertNotFalse($heroPos);
        $this->assertNotFalse($coverPos);
        $this->assertTrue($heroPos < $coverPos, 'Discover hero must sit inside #flyer-viewer, before the cover.');

        $heroImg = mb_substr($content, $heroPos - 400, 900);
        $this->assertStringContainsString('loading="eager"', $heroImg);
        $this->assertStringContainsString('fetchpriority="high"', $heroImg);
        $this->assertStringContainsString('width="1280"', $heroImg);
        $this->assertStringContainsString('height="720"', $heroImg);

        // With the hero taking the LCP role, page 1 cover must NOT also
        // claim fetchpriority=high (two priorities = browser confusion).
        $coverImg = mb_substr($content, $coverPos - 200, 700);
        $this->assertStringContainsString('loading="lazy"', $coverImg);
        $this->assertStringNotContainsString('fetchpriority="high"', $coverImg);
    }

    public function test_cover_fallback_reports_real_page_dimensions_and_no_hero(): void
    {
        $flyer = $this->createPublishedFlyer([], ['slug' => 'no-discover-flyer']);
        $this->addPage($flyer, 'flyers/pages/only-cover.webp');

        $content = (string) $this->get('/offers/no-discover-flyer')->getContent();

        // No discover path → NO in-page hero band...
        $this->assertStringNotContainsString('صورة غلاف مجلة', $content);
        // ...and og:image dimensions are the REAL cover page dimensions
        // (fixture pages are 1200x1600) — never a wrong 1280x720 pair.
        $this->assertStringContainsString('<meta property="og:image:width" content="1200">', $content);
        $this->assertStringContainsString('<meta property="og:image:height" content="1600">', $content);

        // Page 1 remains the eager LCP element (search in the body, after
        // the schema JSON-LD).
        $viewerPos = mb_strpos($content, 'id="flyer-viewer"');
        $coverPos = mb_strpos($content, 'flyers/pages/only-cover.webp', (int) $viewerPos);
        $this->assertNotFalse($coverPos);
        $coverImg = mb_substr($content, $coverPos - 200, 700);
        $this->assertStringContainsString('loading="eager"', $coverImg);
        $this->assertStringContainsString('fetchpriority="high"', $coverImg);
    }

    public function test_pages_grid_is_single_column_on_mobile_and_small_tablets(): void
    {
        // R2 image resizing ON so the srcset/sizes branch actually renders
        // (with it off, resizedAsset() returns the master URL and no srcset
        // is emitted at all).
        config(['filesystems.disks.r2.image_resizing_enabled' => true]);

        $flyer = $this->createPublishedFlyer([], ['slug' => 'grid-cols-flyer']);
        $this->addPage($flyer, 'flyers/pages/p1.webp');
        $this->addPage($flyer, 'flyers/pages/p2.webp', 2);
        $this->addPage($flyer, 'flyers/pages/p3.webp', 3);

        $content = (string) $this->get('/offers/grid-cols-flyer')->getContent();

        // ONE image per row on mobile AND small tablets (below md/768px);
        // 2 columns MAX from md: up — never 3+.
        $this->assertStringContainsString('grid grid-cols-1 gap-3 md:grid-cols-2', $content);
        // The srcset hint matches: full width below md, half-ish above.
        $this->assertStringContainsString('sizes="(max-width: 767px) 100vw, 46vw"', $content);
    }
}
