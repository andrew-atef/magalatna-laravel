<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use HasFactory;

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
