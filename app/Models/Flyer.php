<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FlyerStatus;
use App\Services\FlyerSlugService;
use App\Support\ArabicDateHelper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Flyer extends Model
{
    use HasFactory;

    /**
     * Explicit mass-assignment allowlist (matches the flyers schema exactly):
     * id / retailer_id FK / timestamps are never assignable by accident.
     *
     * @var list<string>
     */
    protected $fillable = [
        'retailer_id',
        'title',
        'slug',
        'valid_from',
        'valid_until',
        'applicable_governorates',
        'status',
        'total_pages',
        'bluf_summary',
        'editorial_overview',
    ];

    protected static function booted(): void
    {
        // توليد Slug تلقائي عند الإنشاء إذا لم يُرسل (Filament الآن disabled)
        static::creating(function (Flyer $flyer): void {
            if (empty($flyer->slug) && ! empty($flyer->retailer_id) && ! empty($flyer->valid_from) && ! empty($flyer->valid_until)) {
                try {
                    $retailer = Retailer::find($flyer->retailer_id);
                    $retailerSlug = $retailer?->slug ?? 'flyer';
                    $from = $flyer->valid_from instanceof Carbon ? $flyer->valid_from->toDateString() : (string) $flyer->valid_from;
                    $until = $flyer->valid_until instanceof Carbon ? $flyer->valid_until->toDateString() : (string) $flyer->valid_until;
                    $flyer->slug = app(FlyerSlugService::class)->generate($retailerSlug, $from, $until);
                } catch (\Throwable $e) {
                    $fallback = trim(Str::slug((string) ($flyer->title ?? '')), '-') ?: 'flyer';
                    $flyer->slug = $fallback.'-'.substr(Str::ulid()->toString(), -6);
                }
            } elseif (empty($flyer->slug)) {
                $fallback = trim(Str::slug((string) ($flyer->title ?? '')), '-') ?: 'flyer';
                $flyer->slug = $fallback.'-'.substr(Str::ulid()->toString(), -6);
            }
        });

        // حظر تعديل الـ slug نهائياً بعد الإنشاء
        static::updating(function (Flyer $flyer): void {
            if ($flyer->isDirty('slug')) {
                $flyer->slug = $flyer->getOriginal('slug');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
            'applicable_governorates' => 'array',
            'status' => FlyerStatus::class,
            'total_pages' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Retailer, $this>
     */
    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    /**
     * @return HasMany<FlyerPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(FlyerPage::class);
    }

    /**
     * Cover page only (lowest page_number): O(1) alternative to hydrating
     * the full pages collection for thumbnail cards.
     *
     * @return HasOne<FlyerPage, $this>
     */
    public function coverPage(): HasOne
    {
        return $this->hasOne(FlyerPage::class)->ofMany(['page_number' => 'min', 'id' => 'min']);
    }

    /**
     * @return HasMany<FlyerItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(FlyerItem::class);
    }

    /**
     * Bulletproof Arabic Fallback Generator — يقبل أسماء البراندات اللاتينية
     * داخل نص عربي سليم، ويصلح HTML Entities وأخطاء عد الجمل العشرية.
     *
     * O(1): never touches the database. Reads only the stored attribute plus
     * relations that the caller ALREADY eager-loaded (relationLoaded guard).
     */
    public function getBlufSummaryAttribute(): string
    {
        // EXPIRED ARCHIVE copy — a dead offer must NEVER read "سارٍ". This
        // accessor is the single authority for both the HTML show view and
        // the ?_fmt=md markdown view, so both stay truthful in one place.
        if ($this->isExpired()) {
            $titleForBluf = $this->attributes['title'] ?? 'العرض';
            $retailerName = (string) ($this->retailer?->name ?? '');

            $rawUntil = $this->attributes['valid_until'] ?? null;
            $untilArabic = '';
            if (is_string($rawUntil) && trim($rawUntil) !== '') {
                try {
                    $untilCarbon = Carbon::parse($rawUntil, 'Africa/Cairo');
                    $untilArabic = $untilCarbon->locale('ar')->isoFormat('D MMMM YYYY');
                    if (! preg_match('/[\x{0600}-\x{06FF}]/u', $untilArabic)) {
                        $untilArabic = ArabicDateHelper::formatArabicDate($untilCarbon);
                    }
                } catch (\Throwable $e) {
                    $untilArabic = (string) $rawUntil;
                }
            }

            // Count from the loaded relation when available (O(1) for the show
            // view); only fall back to a COUNT query for unhydrated callers.
            $itemsCount = $this->relationLoaded('items')
                ? $this->items->count()
                : $this->items()->count();

            return "انتهت فترة سريان {$titleForBluf} من {$retailerName} بتاريخ {$untilArabic}. الأسعار المذكورة أدناه ({$itemsCount} سلعة) محفوظة كأرشيف تاريخي لمقارنة الأسعار في مصر.";
        }

        $raw = $this->attributes['bluf_summary'] ?? null;

        if (is_string($raw) && trim($raw) !== '') {
            $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Curly quotes + straight quotes + entity forms are all stripped.
            $decoded = str_replace(["\u{201C}", "\u{201D}", '"', '&quot;', '&#34;'], '', $decoded);
            $decoded = trim($decoded);
            // Accept Arabic copy even when it legitimately contains Latin brand
            // names/specs (LG, iPhone, 4K, SSD) — the hard requirement is that
            // the text IS Arabic, two sentences, discount-bearing, not shallow.
            $hasArabic = preg_match('/[\x{0600}-\x{06FF}]/u', $decoded) === 1;
            // Decimal-safe sentence split: "15.5%" must not count as two sentences.
            $isTwoSentences = count(preg_split('/(?<!\d)[.!؟]+(?!\d)/u', $decoded, -1, PREG_SPLIT_NO_EMPTY)) >= 2;
            // Discount-bearing copy (% + بخصومات) AND the no-discount variant
            // ("بأسعار تنافسية") are both valid — otherwise stored summaries
            // for flyers without a % discount fail validation on EVERY read
            // and get discarded in favour of the fallback generator.
            $hasDiscount = (str_contains($decoded, '%') && str_contains($decoded, 'بخصومات'))
                || str_contains($decoded, 'بأسعار تنافسية');
            $isShallowTitle = $decoded === ($this->attributes['title'] ?? '') || mb_strlen($decoded) < 30;
            if ($decoded !== '' && $hasArabic && $isTwoSentences && $hasDiscount && ! $isShallowTitle) {
                return $decoded;
            }
        }

        // Fallback high-CTR atomic 2-sentence — 2026 SEO (attribute/local data only)
        $titleForBluf = $this->attributes['title'] ?? 'العرض';
        $rawUntil = $this->attributes['valid_until'] ?? null;

        // GUARD: Carbon::parse('') silently evaluates to TODAY — an empty/null
        // valid_until must never render as today's date.
        if ($rawUntil === null || (is_string($rawUntil) && trim($rawUntil) === '')) {
            $untilText = 'إشعار لاحق';
        } else {
            try {
                $untilCarbon = $rawUntil instanceof Carbon
                    ? $rawUntil->copy()
                    : Carbon::parse((string) $rawUntil, 'Africa/Cairo');
                $untilText = $untilCarbon->locale('ar')->isoFormat('dddd D MMMM YYYY');
                if (! preg_match('/[\x{0600}-\x{06FF}]/u', $untilText)) {
                    $untilText = ArabicDateHelper::formatArabicDate($untilCarbon);
                }
            } catch (\Throwable $e) {
                $untilText = (string) $rawUntil;
            }
        }

        // Only use items when already loaded in memory — never query from an accessor.
        $loadedItems = $this->relationLoaded('items') ? $this->items : collect();
        $maxDiscount = $loadedItems->max('discount_percent');
        $hasRealDiscount = $maxDiscount !== null && (float) $maxDiscount > 0;
        // Nearest-integer rounding: "بخصومات تصل إلى 25%" — fake-precision
        // decimals (24.69%) read like machine output and get quoted by AI
        // answer engines verbatim. Same rule as the Gatekeeper BLUF builders.
        $discount = (string) round((float) ($maxDiscount ?? 0));

        // Never emit "بخصومات تصل إلى 0%." — a flyer with no loaded items or no
        // real discount still deserves credible, indexable copy.
        $discountSentence = $hasRealDiscount
            ? "بخصومات تصل إلى {$discount}%."
            : 'بأسعار تنافسية مخفضة.';

        $topProducts = $loadedItems->take(5)->pluck('product_name')->filter()->implode('، ');
        $topProducts = $topProducts !== '' ? $topProducts : 'سلع متنوعة';

        return "تصفح {$titleForBluf} الساري في مصر حتى {$untilText}، {$discountSentence} يشمل العرض تخفيضات قوية على {$topProducts} بجميع الفروع وحتى نفاذ الكمية.";
    }

    public function getEditorialOverviewAttribute(): ?string
    {
        $raw = $this->attributes['editorial_overview'] ?? null;

        // Accept Arabic editorial copy that legitimately contains Latin brand
        // names (LG, iPhone, 4K) — only require that it IS Arabic and real.
        if (is_string($raw) && trim($raw) !== '' && preg_match('/[\x{0600}-\x{06FF}]/u', $raw) === 1) {
            $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $decoded = str_replace(["\u{201C}", "\u{201D}", '"', '&quot;'], '', $decoded);
            $decoded = (string) preg_replace('/(\d+)\.\s+(\d+%)/u', '$1.$2', $decoded);
            if (trim($decoded) !== '' && preg_match('/[\x{0600}-\x{06FF}]/u', $decoded) === 1) {
                return $decoded;
            }
        }

        // Fallback 150-word 2 paragraphs if not yet generated (attribute/local data only)
        $title = $this->attributes['title'] ?? null;
        $validFrom = $this->attributes['valid_from'] ?? null;
        $validUntil = $this->attributes['valid_until'] ?? null;
        if (empty($title) || empty($validFrom) || empty($validUntil)) {
            return null;
        }

        try {
            // Explicit Cairo parsing: date strings are stored Y-m-d; parsing in
            // the app timezone drifts the rendered Arabic day/month around DST.
            $from = ($validFrom instanceof Carbon ? $validFrom->copy() : Carbon::parse((string) $validFrom, 'Africa/Cairo'))
                ->locale('ar')->isoFormat('D MMMM YYYY');
            $until = ($validUntil instanceof Carbon ? $validUntil->copy() : Carbon::parse((string) $validUntil, 'Africa/Cairo'))
                ->locale('ar')->isoFormat('D MMMM YYYY');
            // Only use items when already loaded in memory — never query from an accessor.
            $loadedItems = $this->relationLoaded('items') ? $this->items : collect();
            $maxDiscount = $loadedItems->max('discount_percent');
            $hasRealDiscount = $maxDiscount !== null && (float) $maxDiscount > 0;
            $discount = (string) round((float) ($maxDiscount ?? 0));
            $discountPhrase = $hasRealDiscount ? "بخصومات {$discount}%" : 'بأسعار تنافسية مخفضة';
            $topItems = $loadedItems->take(4);
            if ($topItems->isEmpty()) {
                $topBullets = "- سلع غذائية متنوعة بأسعار مخفضة بجميع الفروع\n- منتجات ألبان ومخبوزات بعروض حصرية\n- منظفات ومستلزمات منزلية بخصومات قوية\n- تخفيضات على اللحوم والدواجن الطازجة";
            } else {
                $topBullets = $topItems->map(function ($item): string {
                    // Trim the ".00" BEFORE appending the currency — trimming
                    // after appending never matches (string ends in 'م').
                    $sale = rtrim(rtrim(number_format((float) $item->sale_price, 2, '.', ''), '0'), '.');
                    if ($sale === '' || $sale === '.') {
                        $sale = '0';
                    }
                    $sale .= ' ج.م';
                    $discount = $item->discount_percent ? ' (خصم '.round((float) $item->discount_percent).'%)' : '';

                    return "- {$item->product_name} بسعر {$sale}{$discount}";
                })->implode("\n");
            }
            $retailerName = $this->relationLoaded('retailer') && $this->retailer ? $this->retailer->name : 'المتجر';
            $p1 = "تقدم مجلة {$title} من {$retailerName} عروضاً حصرية سارية في مصر من {$from} حتى {$until}، {$discountPhrase} على تشكيلة واسعة من السلع الغذائية والمستلزمات المنزلية.";
            $p2 = "أبرز الصفقات في هذا العدد:\n{$topBullets}";
            $text = $p1."\n\n".$p2;
            $text = (string) preg_replace('/(\d+)\.\s+(\d+%)/u', '$1.$2', $text);

            return $text;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function isExpired(): bool
    {
        if ($this->status === FlyerStatus::Expired) {
            return true;
        }

        $rawUntil = $this->attributes['valid_until'] ?? null;

        if ($rawUntil === null || (is_string($rawUntil) && trim($rawUntil) === '')) {
            return false;
        }

        // Compare on the Y-m-d STRING (never a parsed "today" fallback) against
        // the END of the valid_until day in Cairo time.
        $dateStr = $rawUntil instanceof Carbon ? $rawUntil->toDateString() : substr(trim((string) $rawUntil), 0, 10);

        if (! Carbon::hasFormat($dateStr, 'Y-m-d')) {
            return false;
        }

        return Carbon::createFromFormat('Y-m-d', $dateStr, 'Africa/Cairo')
            ->endOfDay()
            ->isPast();
    }
}
