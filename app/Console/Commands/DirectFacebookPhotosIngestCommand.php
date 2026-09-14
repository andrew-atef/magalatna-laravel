<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GatekeeperFacebookPostJob;
use App\Models\RawFacebookPost;
use App\Models\Retailer;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DirectFacebookPhotosIngestCommand extends Command
{
    protected $signature = 'flyers:ingest-direct {--store= : Specific retailer slug} {--dry-run : Parse and report without saving or dispatching}';

    protected $description = 'Direct HTTP Facebook photo ingest via Vercel proxy mesh with Azure fallback';

    private const MAX_IMAGES = 50;

    private const REQUEST_TIMEOUT = 10;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $store = trim((string) ($this->option('store') ?? ''));

        if ($dryRun) {
            $this->info('--- DRY RUN: preview only, no saves or dispatches ---');
        }

        $retailers = $store !== ''
            ? Retailer::where('slug', $store)->where('is_active', true)->where('auto_ingest_enabled', true)->get()
            : Retailer::where('is_active', true)->where('auto_ingest_enabled', true)->orderBy('id')->get();

        if ($retailers->isEmpty()) {
            $this->error($store !== '' ? "Store not found, inactive, or auto-ingest disabled: {$store}" : 'No retailers eligible for auto-ingest.');

            return self::FAILURE;
        }

        foreach ($retailers as $retailer) {
            $status = 'failed';
            $images = 0;
            try {
                ['status' => $status, 'images' => $images] = $this->ingestRetailer($retailer, $dryRun);
            } catch (Throwable $e) {
                Log::error('Direct ingest failed for retailer.', [
                    'retailer_id' => $retailer->id,
                    'retailer_slug' => $retailer->slug,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Ingest failed [{$retailer->slug}]: {$e->getMessage()}");
            }
            $this->logTelemetry($retailer->slug, $status, $images);
        }

        return self::SUCCESS;
    }

    /**
     * Single-line telemetry per retailer execution for rotation tracking.
     *
     * @return array{status: string, images: int}
     */
    private function ingestRetailer(Retailer $retailer, bool $dryRun): array
    {
        $handle = $retailer->facebook_handle;
        // Timeline root first: server-renders full story units (complete
        // message text, creation_time, per-story viewer_image attachments).
        // photos_by variant second; photo grids last as media-only fallback.
        $candidates = [
            "https://www.facebook.com/{$handle}",
            "https://www.facebook.com/{$handle}/photos_by",
            "https://www.facebook.com/{$handle}/photos",
            "https://m.facebook.com/{$handle}/photos",
        ];

        $html = null;
        $target = $candidates[0];
        foreach ($candidates as $candidate) {
            $this->info("Fetching [{$retailer->slug}] via proxy mesh: {$candidate}");
            $html = $this->fetchViaProxyMesh($candidate);
            if ($html !== null && trim($html) !== '' && $this->hasExtractableContent($html)) {
                $target = $candidate;

                break;
            }
        }

        if ($html === null || trim($html) === '') {
            $this->warn("No HTML retrieved for [{$retailer->slug}].");

            return ['status' => 'no-content', 'images' => 0];
        }

        // Tier 1 (lightweight radar): newest post id only — no browser, no
        // heavy parsing. Story units first (true post ids), photo fbid fallback.
        $postId = $this->detectNewestPostId($html);
        if ($postId === null) {
            $this->warn("No identifiable post for [{$retailer->slug}] (login wall or empty page).");

            return ['status' => 'no-content', 'images' => 0];
        }

        // Dedup gate: ANY existing row means up-to-date — zero browser launches,
        // zero quota. This also stops re-dispatch while a post is still pending.
        if (RawFacebookPost::where('retailer_id', $retailer->id)->where('facebook_post_id', $postId)->exists()) {
            Log::info("[RADAR_IDLE] Store {$retailer->slug} up-to-date (Latest: {$postId}).");
            $this->info("Up-to-date [{$retailer->slug}:{$postId}], nothing new.");

            return ['status' => 'up-to-date', 'images' => 0];
        }

        Log::info("[RADAR_TRIGGER] New post {$postId} for {$retailer->slug}! Calling Full Browser Harvester.");
        $this->info("New post detected [{$retailer->slug}:{$postId}], harvesting...");

        if ($dryRun) {
            $preview = $this->directPostPayload($retailer, $postId, $html);
            $this->table(
                ['Store', 'Post ID', 'Images (preview)', 'Caption head', 'Published (UTC)'],
                [[
                    $retailer->slug,
                    $postId,
                    $preview !== null ? count($preview['images']) . ' imgs (direct parse)' : '0 imgs',
                    mb_substr($preview !== null && $preview['caption'] !== '' ? $preview['caption'] : "عروض {$retailer->name} الجديدة", 0, 60),
                    ($preview !== null ? $preview['published_at'] : Carbon::now('UTC'))->format('Y-m-d H:i'),
                ]]
            );
            $this->info('[DRY RUN] Live run would trigger the Cloudflare harvester for this post.');

            return ['status' => 'dry-run', 'images' => $preview !== null ? count($preview['images']) : 0];
        }

        // Tier 2 (event only): full harvest, then persist + dispatch.
        $payload = $this->harvestFullPost($retailer, $postId, $html);
        if ($payload === null || $payload['images'] === []) {
            $this->warn("Harvest yielded nothing usable for [{$retailer->slug}:{$postId}].");

            return ['status' => 'no-content', 'images' => 0];
        }

        $rawPost = $this->persistPost($retailer, $postId, $payload['caption'], $payload['images'], $payload['published_at'], null);
        $this->dispatchGatekeeper($retailer, $rawPost, $postId, $payload['caption'], $payload['images'], $payload['published_at']);

        return ['status' => 'harvested', 'images' => count($payload['images'])];
    }

    /**
     * Append the per-execution telemetry summary (rotation/health tracking).
     */
    private function logTelemetry(string $retailerSlug, string $status, int $images): void
    {
        $line = sprintf(
            '[INGEST_SUMMARY] Retailer: %s | Status: %s | Images: %d | Memory: %.2f MB' . PHP_EOL,
            $retailerSlug,
            $status,
            $images,
            round(memory_get_usage(true) / 1024 / 1024, 2)
        );

        file_put_contents(storage_path('logs/facebook_direct_ingest.log'), $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Tier 1 identification: newest story post_id, else first photo fbid.
     */
    private function detectNewestPostId(string $html): ?string
    {
        if (preg_match('/\{"node":\{"__typename":"Story".*?"post_id":"(\d+)"/s', $html, $m)) {
            return trim($m[1]);
        }
        if (preg_match('#"url":"[^"]*?/posts/(pfbid[\w]+)/#i', $html, $m)) {
            return trim($m[1]);
        }

        return $this->extractPostId($html);
    }

    /**
     * Tier 2: Cloudflare harvester first, local direct parse as fallback.
     * Never throws; null when nothing usable.
     *
     * @return array{caption: string, images: list<string>, published_at: Carbon}|null
     */
    private function harvestFullPost(Retailer $retailer, string $postId, string $html): ?array
    {
        $harvesterUrl = trim((string) config('services.cloudflare_harvester.url'));
        if ($harvesterUrl !== '') {
            try {
                $handle = $retailer->facebook_handle;
                $response = Http::timeout(30)
                    ->withHeaders(['Authorization' => 'Bearer ' . (string) config('services.cloudflare_harvester.secret')])
                    ->post($harvesterUrl, [
                        'retailer_slug' => $retailer->slug,
                        'post_id' => $postId,
                        'post_url' => "https://www.facebook.com/{$handle}/posts/{$postId}",
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    if (is_array($data)) {
                        $images = $this->purifyImageUrls((array) ($data['images'] ?? []), self::MAX_IMAGES);
                        if ($images !== []) {
                            return [
                                'caption' => mb_substr(trim((string) ($data['post_text'] ?? '')), 0, 10000),
                                'images' => $images,
                                'published_at' => $this->parseHarvesterTime($data['published_at'] ?? null),
                            ];
                        }
                    }
                }
                Log::warning('[HARVESTER_FALLBACK] Harvester yielded nothing usable, using local direct parse.', [
                    'retailer_slug' => $retailer->slug,
                    'post_id' => $postId,
                    'status' => $response->status(),
                ]);
            } catch (Throwable $e) {
                Log::warning('[HARVESTER_FALLBACK] Harvester unreachable, using local direct parse.', [
                    'retailer_slug' => $retailer->slug,
                    'post_id' => $postId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->directPostPayload($retailer, $postId, $html);
    }

    /**
     * Local direct-parse fallback: story unit with this post_id, else the photo
     * unit carrying this fbid. Pure HTML already in hand — zero extra fetches.
     *
     * @return array{caption: string, images: list<string>, published_at: Carbon}|null
     */
    private function directPostPayload(Retailer $retailer, string $postId, string $html): ?array
    {
        // Story path.
        if (preg_match_all('/\{"node":\{"__typename":"Story"/', $html, $m, PREG_OFFSET_CAPTURE)) {
            $offsets = array_column($m[0], 1);
            $offsets[] = strlen($html);
            foreach ($offsets as $i => $offset) {
                if ($i >= count($m[0])) {
                    break;
                }
                $chunk = substr($html, $offset, min(60000, $offsets[$i + 1] - $offset));
                if (! str_contains($chunk, '"' . $postId . '"')) {
                    continue;
                }
                $caption = '';
                if (preg_match('/"message":\s*\{\s*"text":\s*"((?:[^"\\\\]|\\\\.)*)"/u', $chunk, $cm)) {
                    $decoded = json_decode('"' . $cm[1] . '"');
                    $caption = trim((string) ($decoded !== null ? $decoded : $cm[1]));
                }
                $rawImages = [];
                if (preg_match_all('/"viewer_image":\s*\{\s*"height":\s*\d+,"width":\s*\d+,"uri":\s*"((?:[^"\\\\]|\\\\.)*)"/', $chunk, $im)) {
                    foreach ($im[1] as $raw) {
                        $rawImages[] = str_replace('\\/', '/', trim($raw));
                    }
                }
                $images = $this->purifyImageUrls($rawImages, self::MAX_IMAGES);
                if ($images === []) {
                    continue;
                }
                $publishedAt = Carbon::now('UTC');
                if (preg_match('/"creation_time":(\d{10})/', $chunk, $tm)) {
                    try {
                        $publishedAt = Carbon::createFromTimestampUTC((int) $tm[1]);
                    } catch (Throwable $e) {
                        $publishedAt = Carbon::now('UTC');
                    }
                }

                return [
                    'caption' => mb_substr($caption !== '' ? $caption : "عروض {$retailer->name} الجديدة", 0, 10000),
                    'images' => $images,
                    'published_at' => $publishedAt,
                ];
            }
        }

        // Photo-unit path: the single edge carrying this fbid.
        foreach ($this->extractPhotoUnits($html) as $unit) {
            if ($unit['fbid'] !== $postId) {
                continue;
            }
            $images = $this->purifyImageUrls($unit['images'] ?? [], self::MAX_IMAGES);
            if ($images === []) {
                continue;
            }

            return [
                'caption' => mb_substr(trim($unit['caption']) !== '' ? $unit['caption'] : "عروض {$retailer->name} الجديدة", 0, 10000),
                'images' => $images,
                'published_at' => $unit['published_at'] !== null
                    ? Carbon::createFromTimestampUTC($unit['published_at'])
                    : Carbon::now('UTC'),
            ];
        }

        return null;
    }

    private function parseHarvesterTime(mixed $value): Carbon
    {
        try {
            if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
                return Carbon::createFromTimestampUTC((int) $value);
            }
            if (is_string($value) && trim($value) !== '') {
                return Carbon::parse(trim($value))->setTimezone('UTC');
            }
        } catch (Throwable $e) {
            // fall through to now
        }

        return Carbon::now('UTC');
    }

    /**
     * Each unit: fbid + own caption + own high-res viewer_image + set + time.
     *
     * @return list<array{fbid: string, caption: string, image: ?string, width: ?int, height: ?int, set: ?string, published_at: ?int}>
     */
    private function extractPhotoUnits(string $html): array
    {
        $excluded = $this->systemPhotoIds($html);

        if (! preg_match_all('/"__typename":"Photo","id":"(\d+)"/', $html, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $units = [];
        $total = count($m[1]);
        for ($i = 0; $i < $total; $i++) {
            $fbid = trim($m[1][$i][0]);
            if ($fbid === '' || isset($excluded[$fbid])) {
                continue;
            }
            $offset = (int) $m[1][$i][1];
            $next = $m[0][$i + 1][1] ?? strlen($html);
            $window = substr($html, $offset, min(6000, max(0, $next - $offset)));

            $caption = null;
            if (preg_match('/"accessibility_caption":\s*"((?:[^"\\\\]|\\\\.)*)"/u', $window, $cm)) {
                $decoded = json_decode('"' . $cm[1] . '"');
                $caption = trim((string) ($decoded !== null ? $decoded : $cm[1]));
            }

            $image = null;
            $width = null;
            $height = null;
            if (preg_match('/"viewer_image":\s*\{\s*"uri":\s*"((?:[^"\\\\]|\\\\.)*)"/', $window, $vm)) {
                $image = str_replace('\\/', '/', trim($vm[1]));
                if (preg_match('/"height":\s*(\d+)[^}]{0,120}?"width":\s*(\d+)/', $window, $dm)) {
                    $height = (int) $dm[1];
                    $width = (int) $dm[2];
                }
            }

            $set = null;
            if (preg_match('#"url":\s*"[^"]*?[?&]set=([^&"\\\\ ]+)#', $window, $sm)) {
                $set = trim(str_replace('\\/', '/', $sm[1]));
            }

            $publishedAt = null;
            foreach (['/"creation_time":\s*(\d{10})/', '/"publish_time":\s*(\d{10})/', '/"timestamp":\s*(\d{10})/'] as $tp) {
                if (preg_match($tp, $window, $tm)) {
                    $publishedAt = (int) $tm[1];

                    break;
                }
            }

            if ($image === null && ($caption === null || $caption === '')) {
                continue;
            }

            $units[] = [
                'fbid' => $fbid,
                'caption' => $caption ?? '',
                'image' => $image,
                'width' => $width,
                'height' => $height,
                'set' => $set,
                'published_at' => $publishedAt,
            ];
        }

        // Merge duplicate edges of the same photo (main stream + prefetch
        // blocks repeat nodes): union images, longest caption wins, keep the
        // first set/time seen. Without this the same fbid is ingested twice.
        $merged = [];
        foreach ($units as $unit) {
            $fbid = $unit['fbid'];
            if (! isset($merged[$fbid])) {
                $merged[$fbid] = $unit;
                $merged[$fbid]['images'] = $unit['image'] !== null ? [$unit['image']] : [];
                unset($merged[$fbid]['image']);

                continue;
            }
            $prev = $merged[$fbid];
            if ($unit['image'] !== null && ! in_array($unit['image'], $prev['images'], true)) {
                $prev['images'][] = $unit['image'];
            }
            if (mb_strlen($unit['caption']) > mb_strlen($prev['caption'])) {
                $prev['caption'] = $unit['caption'];
            }
            if ($prev['set'] === null) {
                $prev['set'] = $unit['set'];
            }
            if ($prev['published_at'] === null) {
                $prev['published_at'] = $unit['published_at'];
            }
            $merged[$fbid] = $prev;
        }

        return array_values($merged);
    }

    /**
     * Cover/profile photo ids that must never key an ingest (they never change
     * while albums grow — keying on them would freeze change detection).
     *
     * @return array<string, true>
     */
    private function systemPhotoIds(string $html): array
    {
        $excluded = [];
        foreach ([
            '/"cover_photo":.*?"photo":\s*\{\s*"id":\s*"(\d+)"/s',
            '/"profilePhoto":.*?"id":\s*"(\d+)"/s',
        ] as $xp) {
            if (preg_match_all($xp, $html, $xm)) {
                foreach ($xm[1] as $id) {
                    $excluded[trim($id)] = true;
                }
            }
        }

        return $excluded;
    }

    private function persistPost(
        Retailer $retailer,
        string $postId,
        string $caption,
        array $images,
        Carbon $publishedAtUtc,
        ?RawFacebookPost $existing
    ): RawFacebookPost {
        if ($existing === null) {
            return RawFacebookPost::create([
                'retailer_id' => $retailer->id,
                'facebook_post_id' => $postId,
                'post_text' => $caption !== '' ? $caption : null,
                'image_urls' => $images,
                'published_at' => $publishedAtUtc,
                'status' => 'pending',
            ]);
        }
        $existing->update([
            'post_text' => $caption !== '' ? $caption : $existing->post_text,
            'image_urls' => $images,
            'published_at' => $publishedAtUtc,
            'status' => 'pending',
        ]);

        return $existing->refresh();
    }

    private function dispatchGatekeeper(
        Retailer $retailer,
        RawFacebookPost $rawPost,
        string $postId,
        string $caption,
        array $images,
        Carbon $publishedAtUtc
    ): void {

        GatekeeperFacebookPostJob::dispatch(
            retailerSlug: $retailer->slug,
            facebookPostId: $postId,
            postText: (string) ($rawPost->post_text ?? ''),
            imageUrls: $images,
            publishedAt: $publishedAtUtc->toIso8601String(),
            rawFacebookPostId: $rawPost->id,
        );

        Log::info("[INGEST_DISPATCHED] Dispatched Gatekeeper for {$retailer->slug}:{$postId} with " . count($images) . ' high-res pages.');
        $this->info("Dispatched Gatekeeper for [{$retailer->slug}:{$postId}].");
    }

    /**
     * Fetch upstream HTML through the Vercel proxy mesh, falling back to
     * direct Azure egress when the proxy is unreachable. Never throws.
     */
    private function fetchViaProxyMesh(string $targetUrl): ?string
    {
        // Strictly config-driven (never env() — breaks under config:cache).
        $proxyUrl = trim((string) config('services.facebook_proxy.url'));
        $secret = trim((string) config('services.facebook_proxy.secret', ''));

        if ($proxyUrl !== '') {
            try {
                $headers = $secret !== '' ? ['x-proxy-secret' => $secret] : [];
                $response = Http::timeout(self::REQUEST_TIMEOUT)
                    ->withHeaders($headers)
                    ->get($proxyUrl, ['url' => $targetUrl]);

                if ($response->successful()) {
                    $html = $this->extractHtml($response);
                    if ($html !== null) {
                        return $html;
                    }
                    Log::warning('[PROXY_EMPTY] Vercel proxy returned no usable HTML, using direct Azure egress.', ['target' => $targetUrl]);
                } else {
                    Log::warning("[PROXY_FALLBACK] Vercel proxy HTTP {$response->status()}, using direct Azure egress.", ['target' => $targetUrl]);
                }
            } catch (Throwable $e) {
                Log::warning('[PROXY_FALLBACK] Vercel proxy failed, using direct Azure egress.', [
                    'target' => $targetUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36',
                    'Accept-Language' => 'ar-EG,ar;q=0.9,en-US;q=0.8',
                ])
                ->get($targetUrl);

            return $response->successful() ? (string) $response->body() : null;
        } catch (Throwable $e) {
            Log::warning('Direct Azure egress fetch failed.', ['target' => $targetUrl, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Unwrap proxy response: raw HTML passes through, JSON envelopes
     * ({html|body|content|data}) are unwrapped. Null when unusable.
     */
    private function extractHtml(Response $response): ?string
    {
        $body = (string) $response->body();
        if (trim($body) === '') {
            return null;
        }

        $first = ltrim($body)[0] ?? '';
        if ($first !== '{' && $first !== '[') {
            return $body;
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            foreach (['html', 'body', 'content', 'data'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
                    return $decoded[$key];
                }
            }
        }

        return null;
    }

    private const AVATAR_MARKERS = [
        't39.30808-1', 's50x50', 's75x75', 's100x100', 's150x150', 's320x320',
        'p50x50', 'p100x100', 'rsrc.php', 'emoji.php',
    ];

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
     * Purify scontent URLs: reject avatars/thumbnails, prioritize full-size
     * uploads (t39.30808-6, mx2048, s960x960, p720x720), unique, capped.
     *
     * @param list<mixed> $urls
     * @return list<string>
     */
    private function purifyImageUrls(array $urls, int $max = self::MAX_IMAGES): array
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
     * Quick content check: does this HTML carry any photo identifiers or
     * usable images? Walls return 200 with empty content — detect and move on.
     */
    private function hasExtractableContent(string $html): bool
    {
        if ($this->extractPostId($html) !== null) {
            return true;
        }

        if (preg_match_all('#https://[a-z0-9.\-]*scontent[a-z0-9.\-]*\.xx\.fbcdn\.net[^"\'\s<>]*#i', $html, $m)) {
            return count($this->purifyImageUrls($m[0], 3)) >= 1;
        }

        return false;
    }

}
