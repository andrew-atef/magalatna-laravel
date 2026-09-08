<?php

declare(strict_types=1);

namespace App\Models;

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
        'currency',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
            ->where('status', 'published')
            ->whereDate('valid_until', '>=', now('Africa/Cairo')->toDateString())
            ->latest('valid_from')
            ->first();
    }
}
