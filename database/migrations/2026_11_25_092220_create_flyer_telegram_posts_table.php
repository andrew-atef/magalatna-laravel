<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flyer_telegram_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flyer_id')->constrained()->cascadeOnDelete();
            $table->string('chat_id');
            $table->json('message_ids')->nullable(); // Array of sent Telegram message IDs
            $table->text('caption');
            $table->unsignedSmallInteger('photo_count')->default(0);
            $table->string('status', 30)->default('pending'); // pending, published, failed
            $table->text('error_message')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flyer_telegram_posts');
    }
};