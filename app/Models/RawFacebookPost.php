<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawFacebookPost extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'image_urls' => 'array',
            'ai_classification' => 'array',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * Cairo-local representation of the UTC stored timestamp.
     *
     * Reads the RAW database digits (always UTC) — never the cast value, which
     * the app timezone would mislabel. Timezone conversion (never manual hour
     * math) tracks Egypt DST dynamically.
     */
    public function getPublishedAtCairoAttribute(): ?Carbon
    {
        $raw = $this->attributes['published_at'] ?? null;
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        $utc = $raw instanceof \DateTimeInterface
            ? Carbon::instance($raw)->setTimezone('UTC')
            : Carbon::parse(trim((string) $raw), 'UTC');

        return $utc->setTimezone('Africa/Cairo');
    }

    /**
     * @return BelongsTo<Retailer, $this>
     */
    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    /**
     * @return BelongsTo<Flyer, $this>
     */
    public function flyer(): BelongsTo
    {
        return $this->belongsTo(Flyer::class);
    }
}
