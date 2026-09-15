<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GatekeeperFacebookPostJob;
use App\Models\RawFacebookPost;
use App\Models\Retailer;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

final class DirectFacebookPhotosIngestCommand extends Command
{
    protected $signature = 'flyers:ingest-direct {--store= : Specific retailer slug} {--dry-run : Parse and report without saving or dispatching} {--force : Bypass the radar dedup gate and re-harvest even if the post ID already exists}';

    protected $description = 'Direct Chrome-TLS Facebook photo ingest via local curl-impersonate engine';

    private const MAX_IMAGES = 50;

    private const REQUEST_TIMEOUT = 10;

    /**
     * Resolved curl-impersonate Chrome binary. Prefers the pinned install
     * path, falls back to PATH lookup; missing binary degrades to null
     * (never fatal — the loop simply records no-content).
     */
    private function curlImpersonateBinary(): string
    {
        foreach (['/usr/local/bin/curl_chrome116', '/usr/bin/curl_chrome116'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return 'curl_chrome116';
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $store = trim((string) ($this->option('store') ?? ''));
        $force = (bool) $this->option('force');

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
                ['status' => $status, 'images' => $images] = $this->ingestRetailer($retailer, $dryRun, $force);
            } catch (Throwable $e) {
                $this->safeLog('error', 'Direct ingest failed for retailer.', [
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
    private function ingestRetailer(Retailer $retailer, bool $dryRun, bool $force = false): array
    {
        $handle = $retailer->facebook_handle;
        // Timeline root first: server-renders full story units (complete
        // message text, creation_time, per-story viewer_image attachments).
        // photos_by variant second; photo grids last as media-only fallback.
        // Desktop www endpoints only — mobile web suppresses timeline
        // streams behind login walls and must never overwrite good HTML.
        $candidates = [
            "https://www.facebook.com/{$handle}",
            "https://www.facebook.com/{$handle}/photos_by",
            "https://www.facebook.com/{$handle}/photos",
        ];

        $html = null;
        $target = $candidates[0];
        $firstHtml = null;
        foreach ($candidates as $index => $candidate) {
            $this->info("Fetching [{$retailer->slug}] via curl-impersonate: {$candidate}");
            $html = $this->fetchViaCurlImpersonate($candidate);
            if ($index === 0) {
                // Forensic reference: timeline-root response.
                $firstHtml = $html;
            }
            if ($html !== null && trim($html) !== '') {
                $this->inspectHtmlAnatomy($retailer->slug, $candidate, $html);
            }
            if ($html !== null && trim($html) !== '' && $this->hasExtractableContent($html)) {
                $target = $candidate;

                break;
            }
        }

        if ($html === null || trim($html) === '') {
            $this->warn("No HTML retrieved for [{$retailer->slug}].");
            $this->dumpRawHtml($retailer->slug, $firstHtml ?? $html);

            return ['status' => 'no-content', 'images' => 0];
        }

        // Tier 1 (lightweight radar): newest post id only — no browser, no
        // heavy parsing. Story units first (true post ids), photo fbid fallback.
        $postId = $this->detectNewestPostId($html);
        if ($postId === null) {
            $this->warn("No identifiable post for [{$retailer->slug}] (login wall or empty page).");
            $this->dumpRawHtml($retailer->slug, $firstHtml ?? $html);

            return ['status' => 'no-content', 'images' => 0];
        }

        // KV / Database gate (Tier 1 radar dedup): LIKE match catches stored
        // id variants (bare fbid vs pfbid-wrapped rows). Seen id + no --force
        // means up-to-date — zero extra fetches. --force
        // bypasses the gate for manual re-harvests.
        $like = '%' . addcslashes($postId, '\\%_') . '%';
        $alreadyExists = RawFacebookPost::where('retailer_id', $retailer->id)
            ->where('facebook_post_id', 'like', $like)
            ->exists();

        if ($alreadyExists && ! $force) {
            $this->safeLog('info', "[RADAR_IDLE] Store {$retailer->slug} up-to-date (ID: {$postId}).");
            $this->info("Up-to-date [{$retailer->slug}:{$postId}], nothing new.");

            return ['status' => 'up-to-date', 'images' => 0];
        }

        $this->safeLog('info', "[RADAR_TRIGGER] New post {$postId} detected for {$retailer->slug}! Parsing Relay payload.");
        $this->info("New post detected [{$retailer->slug}:{$postId}], parsing...");

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
            $this->info('[DRY RUN] Live run would persist this Relay payload and dispatch Gatekeeper.');

            return ['status' => 'dry-run', 'images' => $preview !== null ? count($preview['images']) : 0];
        }

        // Tier 2 (self-contained): parse the Relay payload, persist + dispatch.
        $payload = $this->directPostPayload($retailer, $postId, $html);
        if ($payload === null || $payload['images'] === []) {
            $this->warn("Relay parse yielded nothing usable for [{$retailer->slug}:{$postId}].");
            $this->dumpRawHtml($retailer->slug, $firstHtml ?? $html);

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
            $postId = trim($m[1]);
            $this->line("  ├── [TRACE] Pattern 1 (Relay Story post_id): Match: {$postId}");
            $this->safeLog('debug', '[TRACE] Pattern 1 (Relay Story post_id) matched.', ['post_id' => $postId]);

            return $postId;
        }
        $this->line('  ├── [TRACE] Pattern 1 (Relay Story post_id): NO_MATCH');
        if (preg_match('#"url":"[^"]*?/posts/(pfbid[\w]+)/#i', $html, $m)) {
            $postId = trim($m[1]);
            $this->line("  ├── [TRACE] Pattern 2 (Story URL pfbid): Match: {$postId}");
            $this->safeLog('debug', '[TRACE] Pattern 2 (Story URL pfbid) matched.', ['post_id' => $postId]);

            return $postId;
        }
        $this->line('  ├── [TRACE] Pattern 2 (Story URL pfbid): NO_MATCH');
        $fallback = $this->extractPostId($html);
        $this->line('  ├── [TRACE] Pattern 3 (fbid in query/script): ' . ($fallback !== null ? "Match: {$fallback}" : 'NO_MATCH'));
        $this->line('  └── [TRACE] RESOLVED_AS: ' . ($fallback ?? 'NULL'));
        $this->safeLog('debug', '[TRACE] Post ID resolution finished.', ['post_id' => $fallback]);

        return $fallback;
    }

    // NOTE: curl_chrome116 is the sole, self-contained extraction layer.
    // External cloud scrapers were decommissioned (see git history):
    // directPostPayload() below parses the Relay payload exhaustively.

    /**
     * Local direct-parse fallback: story unit with this post_id, else the photo
     * unit carrying this fbid. Pure HTML already in hand — zero extra fetches.
     *
     * @return array{caption: string, images: list<string>, published_at: Carbon}|null
     */
    private function directPostPayload(Retailer $retailer, string $postId, string $html): ?array
    {
        // Cover shield reference: page cover / profile photo ids that must
        // never appear in flyer page arrays (they never change while albums
        // grow — keying on them would freeze change detection).
        $excludedIds = $this->systemPhotoIds($html);

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
                // Primary: Relay JSON message body (unclipped full caption).
                $caption = '';
                if (preg_match('/"message":\s*\{\s*"text":\s*"((?:[^"\\\\]|\\\\.)*)"/u', $chunk, $cm)) {
                    $decoded = json_decode('"' . $cm[1] . '"', true);
                    if (is_string($decoded) && trim($decoded) !== '') {
                        $caption = trim($decoded);
                    }
                }
                // Secondary: aggregated accessibility captions (image
                // descriptions), skipping reaction counters and placeholders.
                if ($caption === '' && preg_match_all('/"accessibility_caption":\s*"((?:[^"\\\\]|\\\\.)*)"/u', $chunk, $am)) {
                    $parts = [];
                    foreach (array_unique($am[1]) as $raw) {
                        $decoded = json_decode('"' . $raw . '"', true);
                        $text = trim((string) ($decoded !== null ? $decoded : $raw));
                        if ($text !== '' && ! str_contains($text, 'likes') && ! str_contains($text, 'May be an image')) {
                            $parts[] = $text;
                        }
                    }
                    $caption = trim(implode("\n", $parts));
                }
                // Tertiary: neutral fallback (never raw \uXXXX escapes).
                if ($caption === '') {
                    $caption = "عروض {$retailer->name} الجديدة";
                }
                // Primary media: EVERY scontent uri in the story unit —
                // viewer_image covers plus all subattachment originals.
                $rawImages = [];
                if (preg_match_all('/"uri":\s*"((?:[^"\\\\]|\\\\.)*scontent(?:[^"\\\\]|\\\\.)*)"/i', $chunk, $im)) {
                    foreach ($im[1] as $raw) {
                        $rawImages[] = str_replace('\\/', '/', trim($raw));
                    }
                }
                // Broad master-asset sweep: story viewer_image often exposes
                // only the cover page while the remaining flyer pages ship as
                // high-res t39 master assets further down the same story
                // block. Never settle for a solitary page when masters exist.
                if (count($rawImages) < 2) {
                    foreach ($this->extractStoryMasterImages($chunk) as $master) {
                        $rawImages[] = $master;
                    }
                }
                // Cover shield: discard immediately any rendition carrying a
                // system photo id (page cover / profile photo). Strict
                // substring match on the immutable numeric id.
                if ($excludedIds !== []) {
                    $filtered = [];
                    foreach ($rawImages as $candidate) {
                        $blocked = false;
                        foreach ($excludedIds as $excludedId => $_) {
                            if ($excludedId !== '' && str_contains($candidate, (string) $excludedId)) {
                                $blocked = true;

                                break;
                            }
                        }
                        if (! $blocked) {
                            $filtered[] = $candidate;
                        }
                    }
                    $rawImages = $filtered;
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

    /**
     * Broad sweep for high-res flyer master assets inside a story block.
     * Matches only full-size masters (t39.30808-6 / t39.99422-6) — avatar
     * thumbnails (t39.30808-1) can never match this pattern. Decodes the
     * `\/` unicode escapes and HTML entities up front; downstream
     * purifyImageUrls() still dedups, re-scores, and caps at MAX_IMAGES.
     *
     * @return list<string>
     */
    private function extractStoryMasterImages(string $chunk): array
    {
        // Normalize first: server-rendered Relay JSON escapes every slash as
        // `\/`, so match against the decoded form (idempotent when clean).
        $clean = str_replace('\\/', '/', $chunk);
        $patterns = [
            "#https://scontent[^\\s\"'<>]+?/v/t39\\.(?:30808|99422)-6/[^\\s\"'<>]+#",
            "#https://scontent[^\\s\"'<>]+?(?:mx2048|s960x960|p720x720)[^\\s\"'<>]+#",
        ];
        $found = [];
        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $clean, $m)) {
                continue;
            }
            foreach ($m[0] as $raw) {
                $url = html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($url !== '' && str_contains($url, 'scontent')) {
                    $found[] = $url;
                }
            }
        }

        return $found;
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

        $this->safeLog('info', "[INGEST_DISPATCHED] Dispatched Gatekeeper for {$retailer->slug}:{$postId} with " . count($images) . ' high-res pages.');
        $this->info("Dispatched Gatekeeper for [{$retailer->slug}:{$postId}].");
    }

    /**
     * Fetch upstream HTML through the Vercel proxy mesh, falling back to
     * direct Azure egress when the proxy is unreachable. Never throws.
     */
    /**
     * Fetch upstream HTML via the local curl-impersonate Chrome engine routed
     * through Cloudflare WARP consumer VPN (Proxy Mode, SOCKS5 127.0.0.1:40000).
     * Masks the Azure datacenter IP behind Cloudflare's trusted consumer
     * network — obliterates Meta's IP blocks at zero cost. socks5h:// (with
     * 'h') resolves DNS remotely through the tunnel, no DNS leak to Meta.
     * Every outbound Facebook request natively carries Chrome's TLS JA3/JA4
     * fingerprint (< 15MB RAM, zero browser processes). Array-form Process
     * construction — no shell interpolation. Never throws.
     */
    private function fetchViaCurlImpersonate(string $targetUrl): ?string
    {
        $t0 = microtime(true);
        $this->line("[DEBUG_NETWORK] OUTBOUND -> Target: {$targetUrl} via curl_chrome116 + WARP");
        $this->safeLog('debug', "[DEBUG_NETWORK] OUTBOUND -> Target: {$targetUrl} via curl_chrome116 + WARP");

        try {
            $process = new Process([
                $this->curlImpersonateBinary(),
                '-s', '-L',
                '-x', 'socks5h://127.0.0.1:40000', // Route traffic through WARP
                '--max-time', '20',
                '-H', 'Accept-Language: ar-EG,ar;q=0.9,en-US;q=0.8,en;q=0.7',
                '-H', 'Sec-Fetch-Dest: document',
                '-H', 'Sec-Fetch-Mode: navigate',
                '-H', 'Sec-Fetch-Site: none',
                $targetUrl,
            ]);
            $process->setTimeout(25);
            $process->run();

            $elapsedMs = round((microtime(true) - $t0) * 1000, 2);

            if ($process->isSuccessful()) {
                $html = $process->getOutput();
                $line = '[DEBUG_RESPONSE] Route: CURL_CHROME116+WARP | Size: ' . strlen($html) . " bytes | Time: {$elapsedMs}ms";
                $this->line($line);
                $this->safeLog('debug', $line);

                return $html;
            }

            $this->safeLog('warning', "[CURL_IMPERSONATE_ERROR] Failed to fetch {$targetUrl}: " . trim($process->getErrorOutput()));

            return null;
        } catch (Throwable $e) {
            $this->safeLog('warning', "[CURL_IMPERSONATE_ERROR] Exception fetching {$targetUrl}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Granular per-candidate HTML anatomy: title, wall signals, Relay JSON
     * density, image density, story presence, and a text preview.
     */
    private function inspectHtmlAnatomy(string $retailerSlug, string $candidate, string $html): void
    {
        preg_match('/<title[^>]*>([^<]*)<\/title>/i', $html, $titleMatch);
        $pageTitle = trim($titleMatch[1] ?? 'NO_TITLE_FOUND');
        $hasLoginForm = (bool) preg_match('/form[^>]+action*="login"|input[^>]+name="email"/i', $html);
        $hasLoginKeyword = (bool) preg_match('/Log into Facebook|m-login-interstitial|تسجيل الدخول|login_dialog|checkpoint/i', $html);
        $jsonScriptBlocks = preg_match_all('/<script type="application\/json"[^>]*>/i', $html);
        $scontentImagesCount = preg_match_all('/https:\/\/[a-z0-9.\-]*scontent[^\s"\'<>]+/i', $html);
        $hasStoryNode = str_contains($html, '"__typename":"Story"');
        $preview = mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags($html))), 0, 120);

        $lines = [
            "  ├── [ANATOMY] {$candidate} Title: \"{$pageTitle}\"",
            '  ├── [ANATOMY] Login Form: ' . ($hasLoginForm ? 'YES (WALLED)' : 'NO') . ' | Login Keyword: ' . ($hasLoginKeyword ? 'YES' : 'NO'),
            "  ├── [ANATOMY] JSON Scripts: {$jsonScriptBlocks} | scontent Images: {$scontentImagesCount} | Story Nodes: " . ($hasStoryNode ? 'YES' : 'NO'),
            "  └── [ANATOMY] Body Preview: {$preview}",
        ];
        foreach ($lines as $line) {
            $this->line($line);
        }
        $this->safeLog('debug', '[ANATOMY] Candidate inspected.', [
            'retailer_slug' => $retailerSlug,
            'candidate' => $candidate,
            'title' => $pageTitle,
            'login_form' => $hasLoginForm,
            'login_keyword' => $hasLoginKeyword,
            'json_scripts' => $jsonScriptBlocks,
            'scontent_images' => $scontentImagesCount,
            'story_nodes' => $hasStoryNode,
        ]);
    }

    /**
     * Crash-proof log shim. A broken log sink (rotated file owned by another
     * OS user, full disk) must NEVER convert a recoverable fetch failure
     * into a fatal command crash — console output continues regardless.
     */
    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (Throwable) {
            // Intentionally silent.
        }
    }

    /**
     * Persist the raw upstream HTML snapshot for unresolved retailers.
     * Keeps the newest 10 snapshots per store: bounds scheduler disk growth
     * (~5MB/store) while preserving forensic evidence. Never throws.
     */
    private function dumpRawHtml(string $retailerSlug, ?string $html): void
    {
        if ($html === null || trim($html) === '') {
            return;
        }
        try {
            $snapshotPath = storage_path("logs/fb_raw_{$retailerSlug}_" . date('Ymd_His') . '.html');
            file_put_contents($snapshotPath, $html, LOCK_EX);
            $this->error("  ⚠️ DUMP SAVED: Full raw HTML saved to: {$snapshotPath}");
            $this->safeLog('warning',  '[FORENSIC_DUMP] Raw upstream HTML snapshot saved.', [
                'retailer_slug' => $retailerSlug,
                'path' => $snapshotPath,
                'bytes' => strlen($html),
            ]);
            $snapshots = glob(storage_path("logs/fb_raw_{$retailerSlug}_*.html")) ?: [];
            if (count($snapshots) > 10) {
                rsort($snapshots);
                foreach (array_slice($snapshots, 10) as $stale) {
                    @unlink($stale);
                }
            }
        } catch (Throwable $e) {
            $this->safeLog('warning',  '[FORENSIC_DUMP] Failed to save raw HTML snapshot.', [
                'retailer_slug' => $retailerSlug,
                'error' => $e->getMessage(),
            ]);
        }
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
     * Purify scontent URLs into unique 2K/3K masters — exactly one rendition
     * per immutable photo signature.
     *
     * - Rejects avatars/thumbnails (AVATAR_MARKERS: t39.30808-1, s50x50,
     *   s75x75, s100x100, s150x150, s320x320, p50x50, p100x100, rsrc.php,
     *   emoji.php).
     * - Groups renditions by immutable photo signature
     *   (FacebookMediaHelper::extractPhotoSignature) so rotating subdomains
     *   and duplicate sizes of the same page collapse to one entry.
     * - Per signature keeps ONLY the highest-scoring rendition — JPEG masters
     *   (t39.30808-6) and PNG masters (t39.99422-6) have full parity:
     *   Master 2K/3K (mx3090, mx2048, mx1638, s2048x2048, t39.99422-6,
     *   t39.30808-6) = 100; Mid (mx1199, s960x960, s1080x1080, p720x720) = 50;
     *   Low (s320x320, p240x240, fb50) = 1; unrecognized = 0.
     * - Wild-format tiebreak: live CDN URLs carry the asset class (t39.x-6)
     *   on EVERY rendition while the true size ships in cstp/ctp/stp params,
     *   so same-photo renditions routinely tie at 100. Ties break by size
     *   rank (mx3090 > s2048x2048 > mx2048 > mx1638 > mx1199 > s1080x1080 >
     *   s960x960 > p720x720 > bare master class > unknown > p240x240 >
     *   s320x320/fb50), then first-seen — a 320px thumb can never shadow a
     *   2K master regardless of HTML order.
     * - Sorts unique masters by score desc, size rank desc, first-seen, caps
     *   at $max. URLs are selected whole — Meta's `oh=` HMAC signatures are
     *   preserved byte-for-byte (never rewritten, `stp=` never touched).
     *
     * @param list<mixed> $urls
     * @return list<string>
     */
    private function purifyImageUrls(array $urls, int $max = self::MAX_IMAGES): array
    {
        $seenUrls = [];
        /** @var array<string, array{url: string, score: int, size: int, index: int}> $best */
        $best = [];
        $order = 0;
        foreach ($urls as $u) {
            $u = trim(html_entity_decode((string) $u, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($u === '' || ! str_contains($u, 'scontent') || isset($seenUrls[$u])) {
                continue;
            }
            $seenUrls[$u] = true;

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

            if (
                str_contains($lower, 'mx3090') || str_contains($lower, 'mx2048')
                || str_contains($lower, 'mx1638') || str_contains($lower, 's2048x2048')
                || str_contains($lower, 't39.99422-6') || str_contains($lower, 't39.30808-6')
            ) {
                $score = 100;
            } elseif (
                str_contains($lower, 'mx1199') || str_contains($lower, 's960x960')
                || str_contains($lower, 's1080x1080') || str_contains($lower, 'p720x720')
            ) {
                $score = 50;
            } elseif (
                str_contains($lower, 's320x320') || str_contains($lower, 'p240x240')
                || str_contains($lower, 'fb50')
            ) {
                $score = 1;
            } else {
                $score = 0;
            }

            // Size rank: secondary ordering within the same score band.
            $size = 20;
            foreach ([
                'mx3090' => 70, 's2048x2048' => 65, 'mx2048' => 60, 'mx1638' => 55,
                'mx1199' => 45, 's1080x1080' => 42, 's960x960' => 40, 'p720x720' => 38,
                't39.99422-6' => 30, 't39.30808-6' => 30,
                'p240x240' => 12, 's320x320' => 10, 'fb50' => 10,
            ] as $token => $rank) {
                if (str_contains($lower, $token) && $rank > $size) {
                    $size = $rank;
                }
            }
            // Known-thumb signal (wild URLs carry class + thumb-size together):
            // unless a genuinely larger size token is present, a thumbnail
            // sinks below size-unknown URLs; bare master-class URLs
            // (unconstrained originals) keep rank 30.
            $hasLow = str_contains($lower, 's320x320') || str_contains($lower, 'p240x240')
                || str_contains($lower, 'fb50');
            if ($hasLow && $size <= 30) {
                $size = -5;
            }

            $key = \App\Support\FacebookMediaHelper::extractPhotoSignature($u);
            if (! isset($best[$key]) || $score > $best[$key]['score']
                || ($score === $best[$key]['score'] && $size > $best[$key]['size'])) {
                $best[$key] = ['url' => $u, 'score' => $score, 'size' => $size, 'index' => $order];
            }
            $order++;
        }

        $masters = array_values($best);
        usort($masters, static fn (array $a, array $b): int => $b['score'] <=> $a['score']
            ?: $b['size'] <=> $a['size']
            ?: $a['index'] <=> $b['index']);

        return array_slice(array_column($masters, 'url'), 0, $max);
    }

    /**
     * Quick content check: does this HTML carry any photo identifiers or
     * usable images? Walls return 200 with empty content — detect and move on.
     * Synced with detectNewestPostId (the Tier 1 gate): a candidate breaks
     * the loop under exactly the same condition Tier 1 will accept, so a
     * valid timeline response is never overwritten by later fallbacks.
     */
    private function hasExtractableContent(string $html): bool
    {
        if ($this->detectNewestPostId($html) !== null) {
            return true;
        }

        if (preg_match_all('#https://[a-z0-9.\-]*scontent[a-z0-9.\-]*\.xx\.fbcdn\.net[^"\'\s<>]*#i', $html, $m)) {
            return count($this->purifyImageUrls($m[0], 3)) >= 1;
        }

        return false;
    }

}
