<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlyerReel extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'flyer_id',
        'video_path',
        'script_text',
        'voice_name',
        'audio_duration',
        'video_duration',
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
            'audio_duration' => 'decimal:2',
            'video_duration' => 'decimal:2',
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

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function markProcessing(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSING,
            'error_message' => null,
        ])->saveQuietly();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => mb_substr($message, 0, 2000),
        ])->saveQuietly();
    }

    public function markReady(string $videoPath, float $audioDuration, float $videoDuration): void
    {
        $this->forceFill([
            'video_path' => $videoPath,
            'audio_duration' => $audioDuration,
            'video_duration' => $videoDuration,
            'status' => self::STATUS_READY,
            'error_message' => null,
            'published_at' => now(),
        ])->saveQuietly();
    }
}
