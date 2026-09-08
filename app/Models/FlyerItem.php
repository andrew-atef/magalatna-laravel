<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ArabicNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlyerItem extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'flyer_id',
        'flyer_page_id',
        'brand_id',
        'product_name',
        'slug',
        'normalized_name',
        'sale_price',
        'old_price',
        'savings_amount',
        'discount_percent',
        'unit',
        'bundle_condition',
        'extra_attributes',
        'is_featured',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_price' => 'decimal:2',
            'old_price' => 'decimal:2',
            'savings_amount' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'extra_attributes' => 'array',
            'is_featured' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if ($model->isDirty('product_name') || empty($model->normalized_name)) {
                $model->normalized_name = ArabicNormalizer::normalize((string) $model->product_name);
            }
        });
    }

    /**
     * @return BelongsTo<Flyer, $this>
     */
    public function flyer(): BelongsTo
    {
        return $this->belongsTo(Flyer::class);
    }

    /**
     * @return BelongsTo<FlyerPage, $this>
     */
    public function flyerPage(): BelongsTo
    {
        return $this->belongsTo(FlyerPage::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
