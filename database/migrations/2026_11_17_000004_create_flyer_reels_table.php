<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flyer_reels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flyer_id')->constrained()->cascadeOnDelete();
            // R2 path: flyers/{id}/reels/reel_{ulid}.mp4
            $table->string('video_path')->nullable();
            $table->text('script_text');
            $table->string('voice_name', 50)->default('Tavi');
            $table->decimal('audio_duration', 5, 2)->nullable();
            $table->decimal('video_duration', 5, 2)->nullable();
            // pending, processing, ready, failed
            $table->string('status', 30)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flyer_reels');
    }
};
