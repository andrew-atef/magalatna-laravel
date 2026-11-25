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
        'discover_image_path',
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
     * Vertical Reel/Short video (one per flyer) — hasOne keeps history-free
     * regeneration: the job always updates the single row in place.
     *
     * @return HasOne<FlyerReel, $this>
     */
    public function reel(): HasOne
    {
        return $this->hasOne(FlyerReel::class);
    }

    /**
     * Telegram publishing record (one per flyer) — tracks message IDs,
     * caption sent, and publish status for idempotency.
     *
     * @return HasOne<FlyerTelegramPost, $this>
     */
    public function telegramPost(): HasOne
    {
        return $this->hasOne(FlyerTelegramPost::class);
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
        // ZERO SQL: relations are consumed ONLY when the caller already
        // eager-loaded them (an accessor must never trigger lazy loads).
        if ($this->isExpired()) {
            $titleForBluf = $this->attributes['title'] ?? 'العرض';
            $retailerName = $this->relationLoaded('retailer')
                ? (string) ($this->retailer?->name ?? '')
                : '';

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

            if ($this->relationLoaded('items')) {
                $itemsCount = $this->items->count();

                return "انتهت فترة سريان {$titleForBluf} من {$retailerName} بتاريخ {$untilArabic}. الأسعار المذكورة أدناه ({$itemsCount} سلعة) محفوظة كأرشيف تاريخي لمقارنة الأسعار في مصر.";
            }

            return "انتهت فترة سريان {$titleForBluf} من {$retailerName} بتاريخ {$untilArabic}. الأسعار المذكورة أدناه محفوظة كأرشيف تاريخي لمقارنة الأسعار في مصر.";
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

        // UPCOMING flyer (published before its start date): "الساري" is
        // factually WRONG until valid_from. Same Cairo-day comparison as the
        // Gatekeeper builders — a flyer starting tomorrow must never read
        // "سارٍ" today.
        $fromRaw = $this->attributes['valid_from'] ?? null;
        $isUpcoming = false;
        $fromText = '';
        if ($fromRaw !== null) {
            try {
                $fromCarbon = $fromRaw instanceof Carbon
                    ? $fromRaw->copy()
                    : Carbon::parse((string) $fromRaw, 'Africa/Cairo');
                $isUpcoming = now('Africa/Cairo')->startOfDay()->lt($fromCarbon->copy()->startOfDay());
                if ($isUpcoming) {
                    $fromText = $fromCarbon->locale('ar')->isoFormat('dddd D MMMM YYYY');
                    if (! preg_match('/[\x{0600}-\x{06FF}]/u', $fromText)) {
                        $fromText = ArabicDateHelper::formatArabicDate($fromCarbon);
                    }
                }
            } catch (\Throwable $e) {
                $isUpcoming = false;
            }
        }

        if ($isUpcoming) {
            return "تصفح {$titleForBluf} يبدأ في مصر يوم {$fromText} وحتى {$untilText}، {$discountSentence} يشمل العرض تخفيضات قوية على {$topProducts} بجميع الفروع وحتى نفاذ الكمية.";
        }

        return "تصفح {$titleForBluf} الساري في مصر حتى {$untilText}، {$discountSentence} يشمل العرض تخفيضات قوية على {$topProducts} بجميع الفروع وحتى نفاذ الكمية.";
    }

    public function getEditorialOverviewAttribute(): ?string
    {
        // EXPIRED GUARD: editorial copy (stored or generated) always speaks in
        // the present tense ("عروضاً حصرية سارية في مصر") — a dead offer must
        // never carry it. Zero SQL: only already-loaded relations/attributes.
        if ($this->isExpired()) {
            $retailerName = $this->relationLoaded('retailer') && $this->retailer
                ? (string) $this->retailer->name
                : 'المتجر';

            $rawUntil = $this->attributes['valid_until'] ?? null;
            $untilArabic = '';
            if (is_string($rawUntil) && trim($rawUntil) !== '') {
                try {
                    $untilCarbon = $rawUntil instanceof Carbon
                        ? $rawUntil->copy()
                        : Carbon::parse((string) $rawUntil, 'Africa/Cairo');
                    $untilArabic = $untilCarbon->locale('ar')->isoFormat('D MMMM YYYY');
                    if (! preg_match('/[\x{0600}-\x{06FF}]/u', $untilArabic)) {
                        $untilArabic = ArabicDateHelper::formatArabicDate($untilCarbon);
                    }
                } catch (\Throwable $e) {
                    $untilArabic = (string) $rawUntil;
                }
            }

            return "أرشيف عروض {$retailerName} (انتهى السريان بتاريخ {$untilArabic})";
        }

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

            // UPCOMING phrasing: a flyer published before its start date must
            // never read "سارية" — same Cairo-day comparison as the BLUF.
            try {
                $fromCarbonFull = $validFrom instanceof Carbon ? $validFrom->copy() : Carbon::parse((string) $validFrom, 'Africa/Cairo');
                $isUpcoming = now('Africa/Cairo')->startOfDay()->lt($fromCarbonFull->copy()->startOfDay());
            } catch (\Throwable $e) {
                $fromCarbonFull = $validFrom instanceof Carbon ? $validFrom->copy() : Carbon::parse((string) $validFrom, 'Africa/Cairo');
                $isUpcoming = false;
            }
            if ($isUpcoming) {
                $fromDayName = $fromCarbonFull->locale('ar')->isoFormat('dddd');
                if (! preg_match('/[\x{0600}-\x{06FF}]/u', $fromDayName)) {
                    $fromDayName = ArabicDateHelper::dayName($fromCarbonFull);
                }
                $windowPhrase = "عروضاً حصرية تبدأ في مصر يوم {$fromDayName} {$from} وحتى {$until}";
            } else {
                $windowPhrase = "عروضاً حصرية سارية في مصر من {$from} حتى {$until}";
            }

            $p1 = "تقدم مجلة {$title} من {$retailerName} {$windowPhrase}، {$discountPhrase} على تشكيلة واسعة من السلع الغذائية والمستلزمات المنزلية.";
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

    /**
     * High-CTR meta description with REAL prices: Google rewrites ~70% of
     * meta descriptions, but pages whose meta already carries exact item
     * prices ("زيت كريستال بـ 219.95 ج.م") win the SERP answer-box because
     * the snippet IS the answer — competitors ship vague "خصومات تصل إلى"
     * copy instead.
     *
     * Length guard: 135–155 chars, word-boundary safe, NO trailing ellipsis
     * (a cut "…" reads like machine output and Google truncates at its own
     * pixel limit anyway).
     *
     * Zero extra SQL when the caller (show view) already eager-loaded items —
     * the relationLoaded guard keeps list-style renders from N+1ing.
     */
    public function generateMetaDescription(): string
    {
        $retailerClean = $this->relationLoaded('retailer') && $this->retailer
            ? (string) ($this->retailer->clean_name ?: $this->retailer->name)
            : 'المتجر';

        $items = $this->relationLoaded('items')
            ? $this->items->filter(static fn ($item): bool => (float) $item->sale_price > 0 && trim((string) $item->product_name) !== '')
            : $this->items()->where('sale_price', '>', 0)->whereNotNull('product_name')->get();

        $sortedItems = $items->sortByDesc(static fn ($item): float => (float) $item->discount_percent)->values();

        $formatted = $sortedItems->take(5)->map(static function ($item): string {
            $price = rtrim(rtrim(number_format((float) $item->sale_price, 2, '.', ''), '0'), '.');
            if ($price === '' || $price === '.') {
                $price = '0';
            }

            return trim((string) $item->product_name).' بـ '.$price.' ج.م';
        })->all();

        $prefix = '';
        $suffix = '';
        if ($this->isExpired()) {
            $rawUntil = $this->attributes['valid_until'] ?? null;
            $untilAr = '';
            try {
                $untilCarbon = $rawUntil instanceof Carbon
                    ? $rawUntil->copy()
                    : Carbon::parse((string) $rawUntil, 'Africa/Cairo');
                $untilAr = $untilCarbon->locale('ar')->isoFormat('D MMMM YYYY');
                if (! preg_match('/[\x{0600}-\x{06FF}]/u', $untilAr)) {
                    $untilAr = ArabicDateHelper::formatArabicDate($untilCarbon);
                }
            } catch (\Throwable $e) {
                $untilAr = (string) $rawUntil;
            }

            $prefix = "أرشيف أسعار عروض {$retailerClean} (انتهى {$untilAr}): ";
            $suffix = '. قارن تطور الأسعار في مصر عبر مجلاتنا.';
        } else {
            $rawFrom = $this->attributes['valid_from'] ?? null;
            try {
                $fromCarbon = $rawFrom instanceof Carbon
                    ? $rawFrom->copy()
                    : Carbon::parse((string) $rawFrom, 'Africa/Cairo');
                $dayName = $fromCarbon->locale('ar')->isoFormat('dddd');
                if (! preg_match('/[\x{0600}-\x{06FF}]/u', $dayName)) {
                    $dayName = ArabicDateHelper::dayName($fromCarbon);
                }
                $dateAr = $fromCarbon->day.' '.ArabicDateHelper::monthName((int) $fromCarbon->month);
            } catch (\Throwable $e) {
                $dayName = '';
                $dateAr = '';
            }

            $datePhrase = $dayName !== '' ? " {$dayName} {$dateAr}" : '';
            $prefix = "أسعار عروض {$retailerClean}{$datePhrase}: ";
            $suffix = '. تصفح المجلة والأسعار كاملة.';
        }

        $build = static function (int $count) use ($prefix, $suffix, $formatted): string {
            $itemsString = $count > 0 ? implode('، ', array_slice($formatted, 0, $count)) : 'سلع متنوعة';

            return $prefix.$itemsString.$suffix;
        };

        // Start with the top 3, then grow toward the 135-char floor while the
        // result still fits under the 155 hard cap (max 5 — the meta is a
        // highlight, not the table).
        $count = min(3, count($formatted));
        while ($count < count($formatted) && mb_strlen($build($count + 1)) <= 155) {
            $count++;
        }
        $text = $build($count);

        // 155-char hard cap: drop the LAST item first (the weakest discount
        // signal), then a final word-boundary cut WITHOUT any ellipsis.
        while ($count > 1 && mb_strlen($text) > 155) {
            $count--;
            $text = $build($count);
        }

        if (mb_strlen($text) > 155) {
            $cut = mb_substr($text, 0, 155);
            $space = mb_strrpos($cut, ' ');
            $text = $space !== false && $space > 100 ? mb_substr($cut, 0, $space) : $cut;
        }

        return $text;
    }
}
