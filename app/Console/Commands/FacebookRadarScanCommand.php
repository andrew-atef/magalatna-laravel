<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GatekeeperFacebookPostJob;
use App\Models\RawFacebookPost;
use App\Models\Retailer;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Throwable;

final class FacebookRadarScanCommand extends Command
{
    protected $signature = 'flyers:radar-scan {--store= : Specific retailer slug} {--force : Force deep scrape bypassing KV/cache}';

    protected $description = 'High-speed 1-minute browser radar scan across active retailers via native Chromium';

    private const ROTATION_KEY = 'system:last_retailer_scan_index';

    private const BATCH_SIZE = 2;

    private const MAX_IMAGES = 20;

    private const BROWSER_TIMEOUT = 15;

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $store = trim((string) ($this->option('store') ?? ''));

        $chromePath = $this->resolveChromePath();
        if ($chromePath === null) {
            $this->error('Chromium binary not found. Set CHROMIUM_PATH or install Chromium (VPS: /usr/bin/chromium-browser).');

            return self::FAILURE;
        }

        $this->info("Chromium: {$chromePath}");

        $advancePointer = true;

        if ($store !== '') {
            $retailer = Retailer::where('slug', $store)->where('is_active', true)->where('auto_ingest_enabled', true)->first();
            if ($retailer === null) {
                $this->error("Store not found or inactive: {$store}");

                return self::FAILURE;
            }
            $targets = [$retailer];
            $advancePointer = false;
        } else {
            $retailers = Retailer::where('is_active', true)->where('auto_ingest_enabled', true)->orderBy('id')->get();
            if ($retailers->isEmpty()) {
                $this->info('No active retailers to scan.');

                return self::SUCCESS;
            }

            $total = $retailers->count();
            $start = ((int) Cache::get(self::ROTATION_KEY, 0)) % $total;

            $targets = [];
            for ($i = 0; $i < min(self::BATCH_SIZE, $total); $i++) {
                $targets[] = $retailers[($start + $i) % $total];
            }

            Cache::put(self::ROTATION_KEY, ($start + count($targets)) % $total);
        }

        foreach ($targets as $retailer) {
            try {
                $this->scanRetailer($retailer, $chromePath, $force);
            } catch (Throwable $e) {
                Log::error('Radar scan failed for retailer.', [
                    'retailer_id' => $retailer->id,
                    'retailer_slug' => $retailer->slug,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Scan failed [{$retailer->slug}]: {$e->getMessage()}");
            }
        }

        // Pointer already advanced before scanning so a crash never stalls rotation.
        if ($advancePointer) {
            $this->info('Rotation pointer advanced.');
        }

        return self::SUCCESS;
    }

    private function scanRetailer(Retailer $retailer, string $chromePath, bool $force): void
    {
        $handle = $retailer->facebook_handle;
        $this->info("Scanning [{$retailer->slug}] (fb: {$handle})...");

        // Tier 1: lightweight plugin probe (images disabled).
        $pluginUrl = 'https://www.facebook.com/plugins/page.php?href='
            . urlencode('https://www.facebook.com/' . $handle)
            . '&tabs=timeline&width=500&height=800';

        $probeHtml = $this->baseShot($pluginUrl, $chromePath, false)->bodyHtml();
        $postId = $this->extractPostId($probeHtml);

        if ($postId === null) {
            $this->warn("No post identifier found for [{$retailer->slug}], skipping.");

            return;
        }

        $this->info("Newest post candidate: {$postId}");

        // Deduplication check (skipped with --force).
        if (! $force && RawFacebookPost::where('retailer_id', $retailer->id)->where('facebook_post_id', 'like', "%{$postId}%")->exists()) {
            $this->info("Already ingested [{$retailer->slug}:{$postId}], advancing pointer.");

            return;
        }

        // Tier 2: deep flyer extraction (images enabled).
        $permalink = ctype_digit($postId)
            ? "https://www.facebook.com/photo/?fbid={$postId}"
            : "https://www.facebook.com/{$handle}/posts/{$postId}";

        $extracted = $this->deepExtract($permalink, $chromePath);
        $imageUrls = array_values(array_unique(array_filter($extracted['images'])));
        $imageUrls = array_slice($imageUrls, 0, self::MAX_IMAGES);

        if ($imageUrls === []) {
            $this->warn("No scontent images extracted for [{$retailer->slug}:{$postId}].");

            return;
        }

        $caption = trim((string) ($extracted['caption'] ?? ''));
        $publishedAtUtc = Carbon::now('UTC');

        // Idempotent persist: preserve terminal states, dispatch only when retryable.
        $rawPost = RawFacebookPost::where('retailer_id', $retailer->id)
            ->where('facebook_post_id', $postId)
            ->first();

        if ($rawPost !== null && ! in_array($rawPost->status, ['pending', 'failed'], true)) {
            $rawPost->update([
                'post_text' => $caption !== '' ? $caption : $rawPost->post_text,
                'image_urls' => $imageUrls,
            ]);
            $this->info("Post exists with status [{$rawPost->status}], payload refreshed, no redispatch.");

            return;
        }

        if ($rawPost === null) {
            $rawPost = RawFacebookPost::create([
                'retailer_id' => $retailer->id,
                'facebook_post_id' => $postId,
                'post_text' => $caption !== '' ? $caption : null,
                'image_urls' => $imageUrls,
                'published_at' => $publishedAtUtc,
                'status' => 'pending',
            ]);
        } else {
            $rawPost->update([
                'post_text' => $caption !== '' ? $caption : $rawPost->post_text,
                'image_urls' => $imageUrls,
                'published_at' => $publishedAtUtc,
                'status' => 'pending',
            ]);
        }

        GatekeeperFacebookPostJob::dispatch(
            retailerSlug: $retailer->slug,
            facebookPostId: $postId,
            postText: (string) ($rawPost->post_text ?? ''),
            imageUrls: $imageUrls,
            publishedAt: $publishedAtUtc->toIso8601String(),
            rawFacebookPostId: $rawPost->id,
        );

        $this->info("Dispatched Gatekeeper for [{$retailer->slug}:{$postId}] (" . count($imageUrls) . ' images).');
    }

    /**
     * Shared Browsershot builder: server-safe flags, Arabic headers, hard timeout
     * (Symfony Process kills Chromium on exceed — no zombie processes).
     */
    private function baseShot(string $url, string $chromePath, bool $imagesEnabled): Browsershot
    {
        $shot = Browsershot::url($url)
            ->setChromePath($chromePath)
            ->addChromiumArguments([
                'no-sandbox',
                'disable-setuid-sandbox',
                'disable-dev-shm-usage',
                'disable-gpu',
            ])
            ->setExtraHttpHeaders([
                'Referer' => 'https://fathallamarket.com/',
                'Sec-Fetch-Dest' => 'iframe',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'cross-site',
                'Accept-Language' => 'ar-EG,ar;q=0.9,en-US;q=0.8',
            ])
            ->timeout(self::BROWSER_TIMEOUT)
            ->waitUntilNetworkIdle();

        if (! $imagesEnabled) {
            $shot->addChromiumArguments(['blink-settings=imagesEnabled=false']);
        }

        return $shot;
    }

    /**
     * @return array{images: list<string>, caption: string}
     */
    private function deepExtract(string $url, string $chromePath): array
    {
        // Attempt 1: in-page DOM evaluation (overlay removal + structured JSON out).
        try {
            $json = $this->baseShot($url, $chromePath, true)->evaluate(<<<'JS'
                (() => {
                    // 1. Remove login modals and backdrops
                    document.querySelectorAll('[role="dialog"], div[data-pagelet*="Login"], div[aria-modal="true"]').forEach(el => el.remove());
                    document.body.style.overflow = "auto";

                    // 2. Programmatically click ALL "See more" triggers
                    const expandSelectors = [
                        'div[role="button"]',
                        'span[role="button"]',
                        'a[role="button"]',
                        'div[dir="auto"] span'
                    ];
                    document.querySelectorAll(expandSelectors.join(',')).forEach(btn => {
                        const text = (btn.innerText || '').trim();
                        if (/عرض المزيد|See more|See More|مشاهدة المزيد/i.test(text)) {
                            try {
                                btn.click();
                            } catch (_) {}
                        }
                    });

                    // 3. Extract distinct post cards
                    const postContainers = Array.from(document.querySelectorAll('div[role="article"], div[data-pagelet^="FeedUnit"]'));

                    // 4. Extract full expanded text from the primary active post
                    const targetContainer = postContainers[0] || document.body;
                    const textElements = Array.from(targetContainer.querySelectorAll('div[dir="auto"], span[dir="auto"], div[data-ad-preview="message"]'))
                        .map(el => (el.innerText || '').trim())
                        .filter(t => t.length > 20 && !/عرض المزيد|See more|مشاركة|تعليق/i.test(t));

                    const fullCaption = Array.from(new Set(textElements)).join('\n\n').trim();

                    // 5. Extract high-res flyer images (excluding avatars)
                    const imgSrcs = Array.from(targetContainer.querySelectorAll('img'))
                        .map(i => i.currentSrc || i.src)
                        .filter(s => typeof s === 'string' && s.includes('scontent') && !s.includes('t39.30808-1') && !s.includes('s50x50'));

                    return JSON.stringify({
                        images: Array.from(new Set(imgSrcs)),
                        caption: fullCaption.length > 0 ? fullCaption : (document.title || '')
                    });
                })()
                JS);

            $decoded = json_decode((string) $json, true);
            if (is_array($decoded)) {
                return [
                    'images' => $this->purifyImageUrls((array) ($decoded['images'] ?? [])),
                    'caption' => (string) ($decoded['caption'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            Log::debug('Radar evaluate() failed, falling back to bodyHtml parsing.', ['error' => $e->getMessage()]);
        }

        // Attempt 2: PHP-side parsing of rendered HTML (version-proof fallback).
        $html = $this->baseShot($url, $chromePath, true)->bodyHtml();

        $images = [];
        if (preg_match_all('#https://[a-z0-9.\-]*scontent[a-z0-9.\-]*\.xx\.fbcdn\.net[^"\'\s<>]*#i', $html, $m)) {
            $images = $this->purifyImageUrls($m[0]);
        }

        $caption = '';
        if (preg_match('#<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)#i', $html, $cm)
            || preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:description["\']#i', $html, $cm)) {
            $caption = html_entity_decode(trim($cm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return ['images' => $images, 'caption' => $caption];
    }

    private const AVATAR_MARKERS = [
        't39.30808-1', 's50x50', 's75x75', 's100x100', 's150x150', 's320x320',
        'p50x50', 'p100x100', 'rsrc.php', 'emoji.php',
    ];

    /**
     * Purify scontent URLs: reject avatars/thumbnails, prioritize full-size
     * uploads (t39.30808-6, mx2048, s960x960, p720x720), unique, max 20.
     *
     * @param list<mixed> $urls
     * @return list<string>
     */
    private function purifyImageUrls(array $urls, int $max = 20): array
    {
        $seen = [];
        $scored = [];
        $index = 0;
        foreach ($urls as $u) {
            $u = trim(html_entity_decode((string) $u, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($u === '' || ! str_contains($u, 'scontent') || isset($seen[$u])) {
                continue;
            }
            $seen[$u] = true;

            $lower = strtolower($u);
            $isAvatar = false;
            foreach (self::AVATAR_MARKERS as $marker) {
                if (str_contains($lower, $marker)) {
                    $isAvatar = true;

                    break;
                }
            }
            if ($isAvatar) {
                continue;
            }

            $score = 0;
            if (str_contains($u, 't39.30808-6')) {
                $score += 2;
            }
            if (str_contains($u, 'mx2048') || str_contains($u, 's960x960') || str_contains($u, 'p720x720')) {
                $score += 1;
            }
            $scored[] = ['url' => $u, 'score' => $score, 'index' => $index++];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['index'] <=> $b['index']);

        return array_slice(array_column($scored, 'url'), 0, $max);
    }

    /**
     * Match all modern Facebook post identifier formats; returns the first
     * valid numeric or pfbid identifier found (post-specific patterns first,
     * generic JSON id keys last to avoid actor/page-id false positives).
     *
     * Skips cover/profile photo ids: keying an ingest on them would freeze
     * change detection (they never change while the album grows).
     */
    private function extractPostId(string $html): ?string
    {
        $excluded = [];
        foreach ([
            '/"cover_photo":.*?"photo":\s*\{\s*"id":\s*"(\d+)"/s',
            '/"profilePhoto":.*?"id":\s*"(\d+)"/s',
        ] as $excludePattern) {
            if (preg_match_all($excludePattern, $html, $xm)) {
                foreach ($xm[1] as $id) {
                    $excluded[trim($id)] = true;
                }
            }
        }

        $patterns = [
            '#[?&]fbid=(\d{10,})#i',
            '#/photo(?:\.php)?/?\?fbid=(\d{10,})#i',
            '#/posts/(?:pfbid[\w]+|(\d{10,}))#i',
            '#/story_fbid=(\d{10,})#i',
            '#"id":\s*"(\d{10,})"#i',
            '#"photo_id":\s*"(\d{10,})"#i',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $html, $m, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($m as $match) {
                $candidate = trim((string) ($match[1] ?? ''));
                if ($candidate === '' && isset($match[0]) && preg_match('/pfbid[\w]+/i', $match[0], $pm)) {
                    // pfbid branch carries no capture group — recover the token itself.
                    return $pm[0];
                }
                if ($candidate !== '' && ! isset($excluded[$candidate])) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Resolve the Chromium binary: explicit config first (VPS), then well-known
     * OS paths, else null (caller fails loudly instead of hanging).
     */
    private function resolveChromePath(): ?string
    {
        $configured = trim((string) config('services.chromium.path', ''));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        $candidates = PHP_OS_FAMILY === 'Windows'
            ? [
                'C:\Program Files\Google\Chrome\Application\chrome.exe',
                'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
            ]
            : [
                '/usr/bin/chromium-browser',
                '/usr/bin/chromium',
                '/usr/bin/google-chrome',
                '/snap/bin/chromium',
            ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
