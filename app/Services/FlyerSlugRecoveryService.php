<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FlyerStatus;
use App\Models\Flyer;
use App\Models\Retailer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Dynamic 301 recovery for legacy indexed flyer slugs whose rows were
 * deleted, merged (dedup) or unpublished — ZERO hardcoded URLs.
 *
 * GSC keeps sending traffic to old /offers/{slug} URLs forever. Instead of
 * hand-registering Route::redirect() lines per incident, the slug itself is
 * parsed: every flyer slug starts with the retailer prefix (see
 * FlyerSlugService::generate()) followed by the encoded date range, so the
 * recovery target can be resolved from data on every request.
 *
 * Escalation ladder:
 *  1. Match an ACTIVE retailer prefix (cached 1h) — garbage slugs that match
 *     no retailer return null so they keep 404ing cleanly.
 *  2. Exact surviving/merged flyer match: the "-part-N" suffix is stripped
 *     and the canonical base slug checked, or the encoded date range parsed
 *     and a Published/Expired flyer of the same retailer with the exact
 *     valid_from/valid_until window found.
 *  3. Fallback: the retailer's canonical hub URL (always 200, always
 *     indexable) — a 301 to the hub recovers 100% of the link equity.
 */
final class FlyerSlugRecoveryService
{
    private const RETAILER_PREFIX_CACHE_KEY = 'flyer_recovery:retailer_prefixes';

    private const RETAILER_PREFIX_CACHE_TTL = 3600; // 1 hour

    /**
     * English month names exactly as emitted by FlyerSlugService
     * (strtolower($carbon->englishMonth)).
     *
     * @var array<string, int>
     */
    private const MONTHS = [
        'january' => 1,
        'february' => 2,
        'march' => 3,
        'april' => 4,
        'may' => 5,
        'june' => 6,
        'july' => 7,
        'august' => 8,
        'september' => 9,
        'october' => 10,
        'november' => 11,
        'december' => 12,
    ];

    /**
     * Resolve a permanent recovery target for a legacy flyer slug.
     *
     * @return string|null Absolute canonical URL (config('app.url') host) to
     *                     301 to — or null when the slug matches no active
     *                     retailer, so unrelated junk keeps returning 404.
     */
    public function resolveRedirectUrl(string $slug): ?string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        // ---- Step 1: retailer prefix resolution (cached, dynamic) ----
        $matched = $this->matchRetailerPrefix($slug);
        if ($matched === null) {
            return null;
        }
        [$retailerId, $matchedPrefix] = $matched;

        // The 1h prefix cache can go stale the moment a retailer is
        // deactivated: a 301 to a now-dead hub (404) or back onto the same
        // unpublishable flyer would be worse than a clean 404. One indexed
        // PK query on the 404 path buys that safety.
        $retailer = Retailer::query()
            ->where('id', $retailerId)
            ->where('is_active', true)
            ->first(['id', 'slug']);

        if ($retailer === null) {
            return null;
        }

        // ---- Step 2: exact surviving/merged flyer match ----
        $surviving = $this->findSurvivingFlyer($retailer, $slug, $matchedPrefix);

        // Redirecting to a flyer whose slug equals the requested slug would
        // 301-loop back into this same 404 — only redirect on a DIFFERENT
        // surviving slug.
        if ($surviving !== null && (string) $surviving->slug !== $slug) {
            return rtrim((string) config('app.url'), '/')
                .route('flyers.show', ['slug' => $surviving->slug], false);
        }

        // ---- Step 3: canonical active retailer hub fallback ----
        return rtrim((string) config('app.url'), '/')
            .route('retailers.show', ['retailer' => $retailer->slug], false);
    }

    /**
     * Match the slug against active-retailer prefixes, longest first.
     *
     * Every flyer slug starts "{$prefix}-" followed by a DIGIT (the encoded
     * date range — FlyerSlugService output shape), so requiring "prefix-\d"
     * keeps near-misses like "bimmisr-..." from matching a "bim" prefix.
     *
     * @return array{0: int, 1: string}|null [retailer_id, matched_prefix]
     */
    private function matchRetailerPrefix(string $slug): ?array
    {
        foreach ($this->retailerPrefixes() as $entry) {
            if (preg_match('/^'.preg_quote($entry['prefix'], '/').'-\d/', $slug) === 1) {
                return [(int) $entry['id'], (string) $entry['prefix']];
            }
        }

        return null;
    }

    /**
     * Active-retailer prefix map, cached for 1 hour and invalidated from
     * RetailerObserver::forgetCachedSeoArtifacts().
     *
     * @return list<array{id: int, prefix: string}> sorted by prefix length DESC
     */
    private function retailerPrefixes(): array
    {
        $prefixes = Cache::remember(
            self::RETAILER_PREFIX_CACHE_KEY,
            self::RETAILER_PREFIX_CACHE_TTL,
            static function (): array {
                $map = [];

                foreach (Retailer::query()->where('is_active', true)->get(['id', 'slug']) as $retailer) {
                    $slug = trim((string) $retailer->slug);
                    if ($slug === '') {
                        continue;
                    }

                    $add = static function (string $prefix) use (&$map, $retailer): void {
                        $prefix = trim($prefix, '-');
                        if ($prefix === '') {
                            return;
                        }
                        // Dedupe (a retailer's own slug often equals its clean form).
                        foreach ($map as $existing) {
                            if ($existing['id'] === (int) $retailer->id && $existing['prefix'] === $prefix) {
                                return;
                            }
                        }
                        $map[] = ['id' => (int) $retailer->id, 'prefix' => $prefix];
                    };

                    // The retailer slug itself is always a candidate prefix.
                    $add($slug);

                    // Clean form — mirrors FlyerSlugService::generate() ("egypt"
                    // / "misr" are marketing suffixes that never appear in
                    // generated flyer slugs, e.g. carrefouregypt -> carrefour).
                    $clean = Str::of($slug)
                        ->replace('-egypt', '')
                        ->replace('egypt', '')
                        ->replace('-misr', '')
                        ->replace('misr', '')
                        ->trim('-')
                        ->toString();

                    if ($clean !== '') {
                        $add($clean);
                    }

                    // Legacy "bim" flyers predate the "bimmisr" slug
                    // (see the /bim -> /bimmisr 301 alias in routes/web.php).
                    if (str_contains($slug, 'bimmisr')) {
                        $add('bim');
                    }
                }

                // Longest prefix first so the most specific retailer wins when
                // two prefixes could both match the same slug.
                usort(
                    $map,
                    static fn (array $a, array $b): int => mb_strlen((string) $b['prefix']) <=> mb_strlen((string) $a['prefix']),
                );

                return $map;
            },
        );

        return is_array($prefixes) ? $prefixes : [];
    }

    /**
     * Find a PUBLIC (Published/Expired) flyer of this retailer that the
     * legacy slug most plausibly merged into. Draft/PendingReview rows are
     * NEVER valid recovery targets — a 301 onto them would leak non-public
     * content via the redirect target's URL (and they render 404 anyway).
     */
    private function findSurvivingFlyer(Retailer $retailer, string $slug, string $matchedPrefix): ?Flyer
    {
        $publicStatuses = [FlyerStatus::Published, FlyerStatus::Expired];

        // (a) Strip the dedup suffix ("-part-N", FlyerSlugService::ensureUniqueSlug)
        // and check whether the canonical base slug survived.
        $canonicalBase = (string) preg_replace('/-part-\d+$/', '', $slug);
        if ($canonicalBase !== '' && $canonicalBase !== $slug) {
            $surviving = Flyer::query()
                ->where('retailer_id', $retailer->id)
                ->where('slug', $canonicalBase)
                ->whereIn('status', $publicStatuses)
                ->first(['slug']);

            if ($surviving !== null) {
                return $surviving;
            }
        }

        // (b) Parse the date range encoded in the slug and look up a surviving
        // flyer of the same retailer with the EXACT valid_from/valid_until —
        // covers the inverse -part-N case (original row deleted, the "-part-2"
        // twin survived) and any slug drift within the same retailer.
        $dates = $this->parseSlugDates($canonicalBase, $matchedPrefix);
        if ($dates !== null) {
            [$from, $until] = $dates;

            // Exact-day windows as half-open ranges: Eloquent's date cast
            // stores "Y-m-d H:i:s" strings, so bare "Y-m-d" equality would
            // silently miss. Direct comparisons keep the date indexes usable
            // (repo convention — never whereDate(), it wraps the column).
            $dayAfterFrom = $from->copy()->addDay()->toDateString();
            $dayAfterUntil = $until->copy()->addDay()->toDateString();

            $surviving = Flyer::query()
                ->where('retailer_id', $retailer->id)
                ->where('valid_from', '>=', $from->toDateString())
                ->where('valid_from', '<', $dayAfterFrom)
                ->where('valid_until', '>=', $until->toDateString())
                ->where('valid_until', '<', $dayAfterUntil)
                ->whereIn('status', $publicStatuses)
                // Prefer the live flyer when duplicates share the window.
                ->orderByRaw("CASE WHEN status = 'published' THEN 0 ELSE 1 END")
                ->orderByDesc('updated_at')
                ->first(['slug']);

            if ($surviving !== null) {
                return $surviving;
            }
        }

        return null;
    }

    /**
     * Parse valid_from/valid_until out of the date portion of a flyer slug.
     *
     * Supported shapes (exactly FlyerSlugService::generate() output):
     *   - single day:    "{D}-{month}-{YYYY}"
     *   - same month:    "{D1}-{D2}-{month}-{YYYY}"
     *   - cross month:   "{D1}-{month1}-{D2}-{month2}-{YYYY}"
     *   - cross year:    "{D1}-{month1}-{YYYY1}-{D2}-{month2}-{YYYY2}"
     *
     * @return array{0: Carbon, 1: Carbon}|null [valid_from, valid_until]
     */
    private function parseSlugDates(string $canonicalBase, string $matchedPrefix): ?array
    {
        $prefix = $matchedPrefix.'-';
        if (! str_starts_with($canonicalBase, $prefix)) {
            return null;
        }

        $range = mb_substr($canonicalBase, mb_strlen($prefix));
        if (preg_match('/^\d/', $range) !== 1) {
            return null;
        }

        $monthAlt = implode('|', array_keys(self::MONTHS));
        $day = '(\d{1,2})';
        $year = '(\d{4})';
        $monthName = '('.$monthAlt.')';

        // Most-specific patterns first (anchored — no partial matches).
        $patterns = [
            "/^{$day}-{$monthName}-{$year}-{$day}-{$monthName}-{$year}$/", // cross year
            "/^{$day}-{$monthName}-{$day}-{$monthName}-{$year}$/",         // cross month
            "/^{$day}-{$day}-{$monthName}-{$year}$/",                      // same month
            "/^{$day}-{$monthName}-{$year}$/",                             // single day
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $range, $m) !== 1) {
                continue;
            }

            if (count($m) === 7) {
                // D1-month1-YYYY1-D2-month2-YYYY2
                $from = $this->makeDate((int) $m[3], (int) self::MONTHS[$m[2]], (int) $m[1]);
                $until = $this->makeDate((int) $m[6], (int) self::MONTHS[$m[5]], (int) $m[4]);
            } elseif (count($m) === 6) {
                // D1-month1-D2-month2-YYYY
                $from = $this->makeDate((int) $m[5], (int) self::MONTHS[$m[2]], (int) $m[1]);
                $until = $this->makeDate((int) $m[5], (int) self::MONTHS[$m[4]], (int) $m[3]);
            } elseif (count($m) === 5) {
                // D1-D2-month-YYYY
                $from = $this->makeDate((int) $m[4], (int) self::MONTHS[$m[3]], (int) $m[1]);
                $until = $this->makeDate((int) $m[4], (int) self::MONTHS[$m[3]], (int) $m[2]);
            } else {
                // D-month-YYYY
                $from = $this->makeDate((int) $m[3], (int) self::MONTHS[$m[2]], (int) $m[1]);
                $until = $from;
            }

            if ($from === null || $until === null || $from->gt($until)) {
                return null;
            }

            return [$from, $until];
        }

        return null;
    }

    /**
     * Strict date factory — Carbon rolls overflow days (30 February) forward,
     * and a rolled date would silently miss the exact-window lookup.
     */
    private function makeDate(int $year, int $month, int $day): ?Carbon
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        $date = Carbon::create($year, $month, $day, 0, 0, 0, 'Africa/Cairo');

        if ($date === false
            || (int) $date->format('Y') !== $year
            || (int) $date->format('n') !== $month
            || (int) $date->format('j') !== $day) {
            return null;
        }

        return $date->startOfDay();
    }
}
