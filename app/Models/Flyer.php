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
use Illuminate\Support\Str;

class Flyer extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

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
                    $flyer->slug = Str::slug((string) $flyer->title).'-'.substr(Str::ulid()->toString(), -6);
                }
            } elseif (empty($flyer->slug)) {
                $flyer->slug = Str::slug((string) ($flyer->title ?? 'flyer')).'-'.substr(Str::ulid()->toString(), -6);
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
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<FlyerPage, $this>
     */
    public function coverPage(): \Illuminate\Database\Eloquent\Relations\HasOne
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
     * Bulletproof Arabic Fallback Generator — يمنع الإنجليزية تماماً ويصلح HTML Entities.
     *
     * O(1): never touches the database. Reads only the stored attribute plus
     * relations that the caller ALREADY eager-loaded (relationLoaded guard).
     */
    public function getBlufSummaryAttribute(): string
    {
        $raw = $this->attributes['bluf_summary'] ?? null;

        if (is_string($raw) && trim($raw) !== '') {
            $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $decoded = str_replace(['"', '"', '"', '&quot;', '&#34;'], '', $decoded);
            $decoded = trim($decoded);
            // Strict check: must be 2 sentences, contain discount and no English, not just title
            $isTwoSentences = count(preg_split('/[.!؟]+/u', $decoded, -1, PREG_SPLIT_NO_EMPTY)) >= 2;
            $hasDiscount = str_contains($decoded, '%') && str_contains($decoded, 'بخصومات');
            $isShallowTitle = $decoded === ($this->attributes['title'] ?? '') || mb_strlen($decoded) < 30;
            if ($decoded !== '' && ! preg_match('/[a-zA-Z]/', $decoded) && $isTwoSentences && $hasDiscount && ! $isShallowTitle) {
                return $decoded;
            }
        }

        // Fallback high-CTR atomic 2-sentence — 2026 SEO (attribute/local data only)
        $titleForBluf = $this->attributes['title'] ?? 'العرض';
        try {
            $untilCarbon = Carbon::parse($this->attributes['valid_until'] ?? '');
            $untilText = $untilCarbon->locale('ar')->isoFormat('dddd D MMMM YYYY');
            if (! preg_match('/[\x{0600}-\x{06FF}]/u', $untilText)) {
                $untilText = ArabicDateHelper::formatArabicDate($untilCarbon);
            }
        } catch (\Throwable $e) {
            $untilText = (string) ($this->attributes['valid_until'] ?? '');
        }

        // Only use items when already loaded in memory — never query from an accessor.
        $loadedItems = $this->relationLoaded('items') ? $this->items : collect();
        $maxDiscount = $loadedItems->max('discount_percent');
        $discount = number_format((float) ($maxDiscount ?? 0), 2, '.', '');
        $discount = rtrim(rtrim($discount, '0'), '.');
        $topProducts = $loadedItems->take(5)->pluck('product_name')->filter()->implode('، ');
        $topProducts = $topProducts !== '' ? $topProducts : 'سلع متنوعة';

        return "تصفح {$titleForBluf} الساري في مصر حتى {$untilText}، بخصومات تصل إلى {$discount}%. يشمل العرض تخفيضات قوية على {$topProducts} بجميع الفروع وحتى نفاذ الكمية.";
    }

    public function getEditorialOverviewAttribute(): ?string
    {
        $raw = $this->attributes['editorial_overview'] ?? null;
        if (is_string($raw) && trim($raw) !== '' && ! preg_match('/[a-zA-Z]/', $raw)) {
            $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $decoded = str_replace(['"', '"', '"', '&quot;'], '', $decoded);
            $decoded = (string) preg_replace('/(\d+)\.\s+(\d+%)/u', '$1.$2', $decoded);
            if (trim($decoded) !== '' && ! preg_match('/[a-zA-Z]/', $decoded)) {
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
            $from = Carbon::parse($validFrom)->locale('ar')->isoFormat('D MMMM YYYY');
            $until = Carbon::parse($validUntil)->locale('ar')->isoFormat('D MMMM YYYY');
            // Only use items when already loaded in memory — never query from an accessor.
            $loadedItems = $this->relationLoaded('items') ? $this->items : collect();
            $discount = number_format((float) ($loadedItems->max('discount_percent') ?? 0), 2, '.', '');
            $discount = rtrim(rtrim($discount, '0'), '.');
            $topItems = $loadedItems->take(4);
            if ($topItems->isEmpty()) {
                $topBullets = "- سلع غذائية متنوعة بأسعار مخفضة بجميع الفروع\n- منتجات ألبان ومخبوزات بعروض حصرية\n- منظفات ومستلزمات منزلية بخصومات قوية\n- تخفيضات على اللحوم والدواجن الطازجة";
            } else {
                $topBullets = $topItems->map(function ($item): string {
                    $sale = number_format((float) $item->sale_price, 2, '.', '').' ج.م';
                    $sale = rtrim(rtrim($sale, '0'), '.');
                    $discount = $item->discount_percent ? ' (خصم '.round((float) $item->discount_percent).'%)' : '';

                    return "- {$item->product_name} بسعر {$sale}{$discount}";
                })->implode("\n");
            }
            $retailerName = $this->relationLoaded('retailer') && $this->retailer ? $this->retailer->name : 'المتجر';
            $p1 = "تقدم مجلة {$title} من {$retailerName} عروضاً حصرية سارية في مصر من {$from} حتى {$until}، بخصومات {$discount}% على تشكيلة واسعة من السلع الغذائية والمستلزمات المنزلية.";
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
        if ($this->status === \App\Enums\FlyerStatus::Expired) {
            return true;
        }

        if (empty($this->valid_until)) {
            return false;
        }

        // A flyer is only expired if the current Cairo time has passed the END of the valid_until day (23:59:59)
        return \Carbon\Carbon::parse($this->valid_until, 'Africa/Cairo')->endOfDay()->isPast();
    }
}
