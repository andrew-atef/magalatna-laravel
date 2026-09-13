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

    private const MAX_IMAGES = 20;

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
            try {
                $this->ingestRetailer($retailer, $dryRun);
            } catch (Throwable $e) {
                Log::error('Direct ingest failed for retailer.', [
                    'retailer_id' => $retailer->id,
                    'retailer_slug' => $retailer->slug,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Ingest failed [{$retailer->slug}]: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function ingestRetailer(Retailer $retailer, bool $dryRun): void
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

            return;
        }

        // Story pipeline first: complete captions + timestamps + attachments.
        $stories = $this->extractStoryUnits($html, $retailer);
        if ($stories !== []) {
            foreach ($stories as $story) {
                $this->ingestSinglePost(
                    $retailer,
                    $story['post_id'],
                    $story['caption'],
                    $story['images'],
                    $story['published_at'],
                    $dryRun
                );
            }

            return;
        }

        // Photo-unit stream parsing: each photo edge becomes an isolated unit,
        // grouped by album set so distinct posts never merge into one payload.
        $units = $this->extractPhotoUnits($html);

        if ($units !== []) {
            $this->ingestUnitGroups($retailer, $units, $dryRun);

            return;
        }

        // Task 3 fallback: no Relay edges (static HTML) — group photo links by
        // album set; unattributable images ride with each set group and
        // converge downstream via Gatekeeper photo-signature dedup.
        $this->ingestFallbackGroups($retailer, $handle, $html, $dryRun);
    }

    /**
     * Full-text story units from timeline Relay streams. Each story carries
     * its complete untruncated message, creation_time, and own viewer_image
     * attachments. Prefetch duplicates merge by post_id (longest caption and
     * union images win). Cover/profile-change stories are skipped.
     *
     * @return list<array{post_id: string, caption: string, images: list<string>, published_at: Carbon}>
     */
    private function extractStoryUnits(string $html, Retailer $retailer): array
    {
        if (! preg_match_all('/\{"node":\{"__typename":"Story"/', $html, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $offsets = array_column($m[0], 1);
        $offsets[] = strlen($html);

        $byPost = [];
        $total = count($m[0]);
        for ($i = 0; $i < $total; $i++) {
            $chunk = substr($html, $offsets[$i], min(60000, $offsets[$i + 1] - $offsets[$i]));

            // Skip cover/profile-change system stories — never flyers.
            if (str_contains($chunk, '"cover_photo"') || str_contains($chunk, '"profilePhoto"')) {
                continue;
            }

            $postId = null;
            if (preg_match('/"post_id":"(\d+)"/', $chunk, $pm)) {
                $postId = trim($pm[1]);
            } elseif (preg_match('#"url":"[^"]*?/posts/(pfbid[\w]+)/#i', $chunk, $pm)) {
                $postId = trim($pm[1]);
            }
            if ($postId === null || $postId === '') {
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

            $caption = '';
            if (preg_match('/"message":\s*\{\s*"text":\s*"((?:[^"\\\\]|\\\\.)*)"/u', $chunk, $cm)) {
                $decoded = json_decode('"' . $cm[1] . '"');
                $caption = trim((string) ($decoded !== null ? $decoded : $cm[1]));
            }
            if ($caption === '' && preg_match_all('/"accessibility_caption":\s*"((?:[^"\\\\]|\\\\.)*)"/u', $chunk, $am)) {
                $parts = [];
                foreach (array_unique($am[1]) as $raw) {
                    $decoded = json_decode('"' . $raw . '"');
                    $text = trim((string) ($decoded !== null ? $decoded : $raw));
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
                $caption = trim(implode("\n", $parts));
            }
            if ($caption === '') {
                $caption = "عروض {$retailer->name} الجديدة";
            }
            $caption = mb_substr($caption, 0, 10000);

            $rawImages = [];
            if (preg_match_all('/"viewer_image":\s*\{\s*"height":\s*\d+,"width":\s*\d+,"uri":\s*"((?:[^"\\\\]|\\\\.)*)"/', $chunk, $im)) {
                foreach ($im[1] as $raw) {
                    $rawImages[] = str_replace('\\/', '/', trim($raw));
                }
            }
            if ($rawImages === [] && preg_match_all('#https://[a-z0-9.\-]*scontent[a-z0-9.\-]*\.xx\.fbcdn\.net[^"\'\s<>]*#i', $chunk, $im)) {
                $rawImages = $im[0];
            }
            $images = $this->purifyImageUrls($rawImages, self::MAX_IMAGES);

            if ($images === []) {
                continue;
            }

            if (! isset($byPost[$postId])) {
                $byPost[$postId] = [
                    'post_id' => $postId,
                    'caption' => $caption,
                    'images' => $images,
                    'published_at' => $publishedAt,
                ];

                continue;
            }

            // Prefetch duplicate of the same story: union images, keep the
            // longer caption and the earliest timestamp.
            $prev = $byPost[$postId];
            $byPost[$postId] = [
                'post_id' => $postId,
                'caption' => mb_strlen($caption) > mb_strlen($prev['caption']) ? $caption : $prev['caption'],
                'images' => array_values(array_unique([...$prev['images'], ...$images])),
                'published_at' => $prev['published_at']->lessThan($publishedAt) ? $prev['published_at'] : $publishedAt,
            ];
        }

        return array_values($byPost);
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

    /**
     * @param list<array{fbid: string, caption: string, images: list<string>, width: ?int, height: ?int, set: ?string, published_at: ?int}> $units
     */
    private function ingestUnitGroups(Retailer $retailer, array $units, bool $dryRun): void
    {
        // Strict per-photo posting: each edge is its own post with exactly its
        // own image(s). Listing HTML carries no reliable post-boundary signal
        // (fbids are not time-dense; sets span whole collections), so any
        // merging here risks frankenstein flyers. Same-period singles converge
        // downstream via Gatekeeper consolidation + signature dedup.
        $dryRows = [];
        foreach ($units as $unit) {
            $postId = $unit['fbid'];
            $caption = mb_substr(trim($unit['caption']), 0, 2000);
            $images = $this->purifyImageUrls($unit['images'] ?? [], self::MAX_IMAGES);
            $publishedAtUtc = $unit['published_at'] !== null
                ? Carbon::createFromTimestampUTC($unit['published_at'])
                : Carbon::now('UTC');

            if ($images === []) {
                $this->info("Skipping post {$postId}: no usable images.");

                continue;
            }

            $existing = RawFacebookPost::where('retailer_id', $retailer->id)
                ->where('facebook_post_id', $postId)
                ->first();

            if ($existing !== null && ! in_array($existing->status, ['pending', 'failed'], true)) {
                $this->info("Already processed [{$postId}] (status: {$existing->status}), skipping.");

                continue;
            }

            if ($dryRun) {
                $dims = ($unit['width'] && $unit['height']) ? " {$unit['width']}x{$unit['height']}" : '';
                $dryRows[] = [
                    $retailer->slug,
                    $postId,
                    count($images) . ' imgs' . $dims,
                    mb_substr($caption !== '' ? $caption : "عروض {$retailer->name} الجديدة", 0, 60),
                    $publishedAtUtc->format('Y-m-d H:i'),
                ];

                continue;
            }

            $this->ingestSinglePost($retailer, $postId, $caption, $images, $publishedAtUtc, false);
        }

        if ($dryRun && $dryRows !== []) {
            $this->table(
                ['Store', 'Post ID', 'Images (preview)', 'Caption head', 'Published (UTC)'],
                $dryRows
            );
        }
    }

    /**
     * Task 3 fallback: static HTML without Relay edges. Photo links grouped by
     * album set so different albums never merge; unattributable images ride
     * with each set group and converge via Gatekeeper signature dedup.
     */
    private function ingestFallbackGroups(Retailer $retailer, string $handle, string $html, bool $dryRun): void
    {
        $bySet = [];
        if (preg_match_all('#https://www\.facebook\.com/photo\.php\?fbid=(\d+)&set=([^&"\'\\\\ ]+)#', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $bySet[$match[2]][] = $match[1];
            }
        }

        $rawUrls = [];
        if (preg_match_all('#https://[a-z0-9.\-]*scontent[a-z0-9.\-]*\.xx\.fbcdn\.net[^"\'\s<>]*#i', $html, $im)) {
            $rawUrls = $im[0];
        }
        $images = $this->purifyImageUrls($rawUrls, self::MAX_IMAGES);
        $caption = $this->extractPostCaption($html, $retailer);

        if ($bySet === []) {
            // Single anonymous bucket (legacy behavior).
            $postId = $this->extractPostId($html) ?? ('direct-' . md5($caption . ($images[0] ?? $handle) . $retailer->id));
            $this->ingestSinglePost($retailer, $postId, $caption, $images, Carbon::now('UTC'), $dryRun);

            return;
        }

        foreach ($bySet as $fbids) {
            $fbids = array_values(array_unique($fbids));
            $this->ingestSinglePost($retailer, $fbids[0], $caption, $images, Carbon::now('UTC'), $dryRun);
        }
    }

    private function ingestSinglePost(
        Retailer $retailer,
        string $postId,
        string $caption,
        array $images,
        Carbon $publishedAtUtc,
        bool $dryRun
    ): void {
        if ($images === []) {
            $this->warn("Nothing extractable for [{$retailer->slug}] (login wall or empty album).");

            return;
        }

        $this->info("Candidate [{$retailer->slug}:{$postId}] — " . count($images) . ' images.');

        $existing = RawFacebookPost::where('retailer_id', $retailer->id)
            ->where('facebook_post_id', $postId)
            ->first();

        if ($existing !== null && ! in_array($existing->status, ['pending', 'failed'], true)) {
            $this->info("Already processed [{$postId}] (status: {$existing->status}), skipping.");

            return;
        }

        if ($dryRun) {
            $this->table(
                ['Store', 'Post ID', 'Images (preview)', 'Caption head', 'Published (UTC)'],
                [[
                    $retailer->slug,
                    $postId,
                    count($images) . ' imgs',
                    mb_substr($caption !== '' ? $caption : "عروض {$retailer->name} الجديدة", 0, 60),
                    $publishedAtUtc->format('Y-m-d H:i'),
                ]]
            );

            return;
        }

        $rawPost = $this->persistPost($retailer, $postId, $caption, $images, $publishedAtUtc, $existing);
        $this->dispatchGatekeeper($retailer, $rawPost, $postId, $caption, $images, $publishedAtUtc);
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

    /**
     * Multi-layered caption extraction. NEVER returns the page-bio
     * og:description from listing pages (generic "About" text, not the offer).
     *  1. Embedded Relay post message (unicode escapes decoded).
     *  2. Photo accessibility captions (carry OCR product/price/date text).
     *  3. Explicit generic fallback naming the retailer.
     */
    private function extractPostCaption(string $html, Retailer $retailer): string
    {
        if (preg_match('/"message":\s*\{\s*"text":\s*"([^"]+)"\}/u', $html, $m) && trim($m[1]) !== '') {
            $decoded = json_decode('"' . $m[1] . '"');
            $text = trim((string) ($decoded !== null ? $decoded : $m[1]));
            if ($text !== '') {
                return mb_substr($text, 0, 2000);
            }
        }

        if (preg_match_all('/"accessibility_caption":\s*"([^"]+)"/u', $html, $m) && $m[1] !== []) {
            $captions = [];
            foreach (array_unique($m[1]) as $raw) {
                $decoded = json_decode('"' . $raw . '"');
                $text = trim((string) ($decoded !== null ? $decoded : $raw));
                if ($text !== '') {
                    $captions[] = $text;
                }
            }
            $joined = trim(implode("\n", $captions));
            if ($joined !== '') {
                return mb_substr($joined, 0, 2000);
            }
        }

        return "عروض {$retailer->name} الجديدة";
    }
}
