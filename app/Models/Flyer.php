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
            // فك أي ترميز مزدوج سابق (Double Escaping) وتحويل &quot; إلى نص صافي
            $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // إزالة علامات الاقتباس الإنجليزية المسببة لـ &quot; واستبدالها بفاصلة عربية أو بدونها
            $decoded = str_replace(['"', '"', '"', '&quot;', '&#34;'], '', $decoded);
            $decoded = trim($decoded);
            if ($decoded !== '' && ! preg_match('/[a-zA-Z]/', $decoded)) {
                return $decoded;
            }
        }

        // Fallback determinist عربي 100% بدون إنجليزية
        $retailerName = $this->retailer?->name ?? $this->retailer()->first()?->name ?? 'المتجر';
        $title = $this->title ?? 'العرض';
        $from = $this->valid_from instanceof Carbon ? $this->valid_from->format('d/m/Y') : (string) $this->valid_from;
        $until = $this->valid_until instanceof Carbon ? $this->valid_until->format('d/m/Y') : (string) $this->valid_until;
        $pages = $this->total_pages ?? 1;

        // Ensure valid_from/until are Carbon for format
        try {
            $from = Carbon::parse($this->valid_from)->format('d/m/Y');
            $until = Carbon::parse($this->valid_until)->format('d/m/Y');
        } catch (\Throwable $e) {
            // keep as is
        }

        $count = $this->relationLoaded('items') ? $this->items->count() : $this->items()->count();
        $maxDiscount = $this->relationLoaded('items') ? $this->items->max('discount_percent') : $this->items()->max('discount_percent');

        // أهم 3 سلع للـ CTR بدون رموز HTML
        $topProducts = $this->relationLoaded('items')
            ? $this->items->take(3)->pluck('product_name')->filter()->implode('، ')
            : $this->items()->limit(3)->pluck('product_name')->implode('، ');
        $productsPart = $topProducts !== '' ? " وأبرزها {$topProducts}" : '';

        return "تصفح عروض {$retailerName} {$title} السارية في مصر من {$from} حتى {$until} بـ {$pages} صفحات{$productsPart}. تشمل المجلة {$count} عرضاً بتخفيضات تصل إلى ".round((float) ($maxDiscount ?? 0)).'% على أبرز السلع والمستلزمات.';
    }
}
