<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlyerTelegramPost extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'flyer_id',
        'chat_id',
        'message_ids',
        'caption',
        'photo_count',
        'status',
        'error_message',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'message_ids' => 'array',
            'photo_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Flyer, $this>
     */
    public function flyer(): BelongsTo
    {
        return $this->belongsTo(Flyer::class);
    }
}