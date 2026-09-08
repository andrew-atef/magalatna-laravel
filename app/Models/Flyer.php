<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FlyerStatus;
use App\Services\FlyerSlugService;
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
     * @return HasMany<FlyerItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(FlyerItem::class);
    }

    /**
     * Bulletproof Arabic Fallback Generator — يمنع الإنجليزية تماماً ويصلح HTML Entities
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
            $isShallowTitle = $decoded === $this->attributes['title'] ?? '' || mb_strlen($decoded) < 30;
            if ($decoded !== '' && ! preg_match('/[a-zA-Z]/', $decoded) && $isTwoSentences && $hasDiscount && ! $isShallowTitle) {
                return $decoded;
            }
        }

        // Fallback high-CTR atomic 2-sentence — 2026 SEO
        $titleForBluf = $this->title ?? 'العرض';
        try {
            $untilCarbon = Carbon::parse($this->valid_until);
            $untilText = $untilCarbon->locale('ar')->isoFormat('dddd D MMMM YYYY');
            if (! preg_match('/[\x{0600}-\x{06FF}]/u', $untilText)) {
                $untilText = $this->arabicDayName($untilCarbon).' '.$untilCarbon->day.' '.$this->arabicMonthName((int) $untilCarbon->month).' '.$untilCarbon->year;
            }
        } catch (\Throwable $e) {
            $untilText = (string) $this->valid_until;
        }
        $maxDiscount = $this->relationLoaded('items') ? $this->items->max('discount_percent') : $this->items()->max('discount_percent');
        $discount = number_format((float) ($maxDiscount ?? 0), 2, '.', '');
        $discount = rtrim(rtrim($discount, '0'), '.');
        $topProducts = $this->relationLoaded('items')
            ? $this->items->take(5)->pluck('product_name')->filter()->implode('، ')
            : $this->items()->limit(5)->pluck('product_name')->implode('، ');
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

        // Fallback 150-word 2 paragraphs if not yet generated
        if (empty($this->title) || empty($this->valid_from) || empty($this->valid_until)) {
            return null;
        }

        try {
            $from = Carbon::parse($this->valid_from)->locale('ar')->isoFormat('D MMMM YYYY');
            $until = Carbon::parse($this->valid_until)->locale('ar')->isoFormat('D MMMM YYYY');
            $discount = number_format((float) ($this->relationLoaded('items') ? $this->items->max('discount_percent') : $this->items()->max('discount_percent') ?? 0), 2, '.', '');
            $discount = rtrim(rtrim($discount, '0'), '.');
            $top = $this->relationLoaded('items') ? $this->items->take(4)->pluck('product_name')->filter()->implode('، ') : $this->items()->limit(4)->pluck('product_name')->implode('، ');
            $top = $top !== '' ? $top : 'سلع متنوعة';
            $p1 = "تقدم مجلة {$this->title} من " . ($this->retailer?->name ?? 'المتجر') . " عروضاً حصرية سارية في مصر من {$from} حتى {$until}، بخصومات {$discount}% على تشكيلة واسعة.";
            $p2 = "يشمل العرض أبرز الصفقات: {$top} بأسعار مخفضة بجميع الفروع وحتى نفاذ الكمية. قارن الأسعار ووفر ميزانيتك.";
            $text = $p1 . "\n\n" . $p2;
            $text = (string) preg_replace('/(\d+)\.\s+(\d+%)/u', '$1.$2', $text);
            return $text;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function arabicMonthName(int $month): string
    {
        return match ($month) {
            1 => 'يناير',
            2 => 'فبراير',
            3 => 'مارس',
            4 => 'أبريل',
            5 => 'مايو',
            6 => 'يونيو',
            7 => 'يوليو',
            8 => 'أغسطس',
            9 => 'سبتمبر',
            10 => 'أكتوبر',
            11 => 'نوفمبر',
            12 => 'ديسمبر',
            default => 'يناير',
        };
    }

    private function arabicDayName(Carbon $date): string
    {
        return match ((int) $date->dayOfWeek) {
            0 => 'الأحد',
            1 => 'الإثنين',
            2 => 'الثلاثاء',
            3 => 'الأربعاء',
            4 => 'الخميس',
            5 => 'الجمعة',
            6 => 'السبت',
            default => $date->locale('ar')->isoFormat('dddd'),
        };
    }
}
