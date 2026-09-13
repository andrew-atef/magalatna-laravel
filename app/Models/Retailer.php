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
        'facebook_page_url',
        'currency',
        'is_active',
        'auto_ingest_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'auto_ingest_enabled' => 'boolean',
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

    public function getCleanNameAttribute(): string
    {
        return trim((string) preg_replace('/\s+مصر$/u', '', (string) $this->name));
    }

    /**
     * Canonical Facebook page handle for scrapers. Prefers the dedicated
     * facebook_page_url; falls back to a Facebook website_url (legacy rows),
     * then to the internal slug. Never mixes website_url semantics.
     */
    public function getFacebookHandleAttribute(): string
    {
        $url = trim((string) ($this->facebook_page_url ?: $this->website_url));
        if ($url !== '') {
            // Accept only facebook.com hosts for the dedicated field; a stray
            // official-site URL must not become a scrape handle.
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '' || str_ends_with($host, 'facebook.com')) {
                $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
                $segments = array_values(array_filter(explode('/', $path)));
                if (! empty($segments) && preg_match('/^[\w.\-]+$/', $segments[0])) {
                    return $segments[0];
                }
            }
        }

        return $this->slug;
    }
}
