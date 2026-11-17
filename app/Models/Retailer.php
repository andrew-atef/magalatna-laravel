<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FlyerStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Retailer extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'logo_path',
        'website_url',
        'facebook_page_url',
        'hotline',
        'branches_count',
        'app_url',
        'schedule_summary',
        'about_text',
        'faqs',
        'currency',
        'is_active',
        'auto_ingest_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'auto_ingest_enabled' => 'boolean',
            'faqs' => 'array',
        ];
    }

    /**
     * Parent retailer (for branch support).
     *
     * @return BelongsTo<Retailer, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Child branches.
     *
     * @return HasMany<Retailer, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Flyers belonging to this retailer.
     *
     * @return HasMany<Flyer, $this>
     */
    public function flyers(): HasMany
    {
        return $this->hasMany(Flyer::class);
    }

    public function latestActiveFlyer(): ?Flyer
    {
        return $this->flyers()
            ->where('status', FlyerStatus::Published)
            // Direct >= comparison so the valid_until B-Tree index is used
            // (whereDate() wraps the column and forces a full scan on SQLite).
            ->where('valid_until', '>=', now('Africa/Cairo')->toDateString())
            ->latest('valid_from')
            ->first();
    }

    public function getCleanNameAttribute(): string
    {
        return trim((string) preg_replace('/\s+مصر$/u', '', (string) $this->name));
    }

    /**
     * Hub-facing display name: clean_name with any parenthesized ENGLISH
     * brand "(Kazyon)" stripped — Latin noise in Arabic SERP titles/FAQ
     * questions wastes the pixel budget and reads like machine output
     * (same rule as the flyer-page SEO titles). Arabic parentheticals stay.
     */
    public function getHubNameAttribute(): string
    {
        return trim((string) preg_replace('/\s*\([^()]*[A-Za-z][^()]*\)/u', '', $this->clean_name));
    }

    /**
     * Canonical Facebook page handle for scrapers. Prefers the dedicated
     * facebook_page_url; falls back to a Facebook website_url (legacy rows),
     * then to the internal slug. Never mixes website_url semantics.
     */
    public function getFacebookHandleAttribute(): string
    {
        $url = trim((string) ($this->facebook_page_url ?: $this->website_url));
        if ($url !== '') {
            // Accept ONLY facebook.com hosts (exact or subdomain). A lookalike
            // like "evilfacebook.com" must NOT pass the str_ends_with check.
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $isFacebookHost = $host === 'facebook.com' || str_ends_with($host, '.facebook.com');
            if ($host === '' || $isFacebookHost) {
                $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
                $segments = array_values(array_filter(explode('/', $path)));
                if (! empty($segments) && preg_match('/^[\w.\-]+$/', $segments[0])) {
                    // profile.php?id=123456 has no slug segment — extract the
                    // numeric id instead of returning the literal 'profile.php'.
                    if ($segments[0] === 'profile.php') {
                        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                        $profileId = $query['id'] ?? '';

                        return is_scalar($profileId) && preg_match('/^\d+$/', (string) $profileId)
                            ? (string) $profileId
                            : $this->slug;
                    }

                    return $segments[0];
                }
            }
        }

        return $this->slug;
    }

    /**
     * FAQ list for the evergreen hub + FAQPage schema. Admin-curated faqs
     * win when present; otherwise DYNAMIC smart defaults keep every hub
     * rich and accurate without any manual entry:
     *  - the schedule question quotes the real cadence summary,
     *  - the contact question quotes the real hotline,
     *  - the offers-validity answer is generic-but-true for every chain.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function getFaqsListAttribute(): array
    {
        $curated = $this->faqs;
        if (is_array($curated)) {
            $clean = array_values(array_filter($curated, static fn ($row): bool => is_array($row)
                && trim((string) ($row['question'] ?? '')) !== ''
                && trim((string) ($row['answer'] ?? '')) !== ''));

            if ($clean !== []) {
                return $clean;
            }
        }

        $cleanName = $this->hub_name;

        $scheduleAnswer = trim((string) $this->schedule_summary);
        if ($scheduleAnswer === '') {
            $scheduleAnswer = "تُحدَّث عروض {$cleanName} أسبوعياً مع صدور المجلات الرسمية، ونقوم برصدها ونشر أسعار السلع فور إعلانها مباشرة.";
        }

        $hotline = trim((string) $this->hotline);
        $contactChannel = $hotline !== ''
            ? "يمكنك الاتصال بالخط الساخن المباشر {$hotline}"
            : 'يمكنك مراجعة الصفحة الرسمية للمتجر';

        return [
            [
                'question' => "متى تنزل عروض {$cleanName} الجديدة في مصر؟",
                'answer' => $scheduleAnswer,
            ],
            [
                'question' => "كيف أعرف أقرب فرع لـ {$cleanName} أو أتواصل مع الإدارة؟",
                'answer' => "{$contactChannel} للاستفسار عن الفروع ومواعيد العمل.",
            ],
            [
                'question' => "هل تسري عروض المجلات في جميع فروع {$cleanName}؟",
                'answer' => 'نعم، تسري العروض في فروع الجمهورية حتى نفاذ الكمية المحددة لكل فرع، باستثناء العروض المخصصة لافتتاح فروع محددة.',
            ],
        ];
    }

    /**
     * Hub freshness for the "آخر تحديث" badge + sitemap lastmod: the NEWEST
     * of the retailer row itself and its latest published flyer. A hub whose
     * flyer changed an hour ago must never claim an old update date.
     */
    public function getEffectiveLastmodAttribute(): Carbon
    {
        $latestFlyerUpdate = $this->flyers()
            ->where('status', FlyerStatus::Published)
            ->max('updated_at');

        if ($latestFlyerUpdate === null) {
            return $this->updated_at ?? $this->created_at ?? Carbon::now('Africa/Cairo');
        }

        $flyerCarbon = Carbon::parse((string) $latestFlyerUpdate, 'UTC');
        $retailerCarbon = $this->updated_at instanceof Carbon
            ? $this->updated_at
            : Carbon::parse((string) $this->updated_at, 'UTC');

        return $flyerCarbon->greaterThan($retailerCarbon) ? $flyerCarbon : $retailerCarbon;
    }
}
