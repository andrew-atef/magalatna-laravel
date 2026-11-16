<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Brand extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // AutoInternalLinkerService caches brand names for 24h — a renamed or
        // deleted brand must never keep injecting stale/404 internal links.
        // Invalidation runs AFTER commit so a rolled-back write never purges.
        $invalidate = static function (): void {
            DB::afterCommit(static function (): void {
                Cache::forget('auto_linker:brands');
            });
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'logo_path',
    ];

    /**
     * @return HasMany<FlyerItem, $this>
     */
    public function flyerItems(): HasMany
    {
        return $this->hasMany(FlyerItem::class);
    }
}
