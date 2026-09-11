<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_facebook_posts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('retailer_id')->constrained('retailers')->cascadeOnDelete();
            $table->string('facebook_post_id')->index();
            $table->longText('post_text')->nullable();
            $table->json('image_urls');
            $table->timestamp('published_at')->nullable();
            $table->string('status')->default('pending');
            $table->json('ai_classification')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('flyer_id')->nullable()->constrained('flyers')->nullOnDelete();
            $table->timestamps();

            $table->unique(['retailer_id', 'facebook_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_facebook_posts');
    }
};
