<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flyer_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flyer_id')->constrained('flyers')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unsignedInteger('page_number')->index();
            $table->string('image_path');
            $table->unsignedInteger('width')->default(1200);
            $table->unsignedInteger('height')->default(1600);
            $table->timestamps();

            $table->unique(['flyer_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flyer_pages');
    }
};
