<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlyerPage extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'flyer_id',
        'page_number',
        'image_path',
        'width',
        'height',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Flyer, $this>
     */
    public function flyer(): BelongsTo
    {
        return $this->belongsTo(Flyer::class);
    }

    /**
     * @return HasMany<FlyerItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(FlyerItem::class);
    }
}
