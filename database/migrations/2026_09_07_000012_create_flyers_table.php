<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flyers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('retailer_id')->constrained('retailers')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('title');
            $table->string('slug')->unique();
            $table->date('valid_from')->index();
            $table->date('valid_until')->index();
            $table->json('applicable_governorates')->nullable();
            $table->enum('status', ['draft', 'pending_review', 'published', 'expired'])->default('draft')->index();
            $table->unsignedInteger('total_pages')->default(1);
            $table->text('bluf_summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flyers');
    }
};
