<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flyer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flyer_id')->constrained('flyers')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('flyer_page_id')->nullable()->constrained('flyer_pages')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete()->cascadeOnUpdate();
            $table->string('product_name');
            $table->string('slug')->index();
            $table->string('normalized_name')->index();
            $table->decimal('sale_price', 10, 2)->index();
            $table->decimal('old_price', 10, 2)->nullable();
            $table->decimal('savings_amount', 10, 2)->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->string('unit')->nullable();
            $table->string('bundle_condition')->nullable();
            $table->json('extra_attributes')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->timestamps();

            $table->index('flyer_page_id');
            $table->index('brand_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flyer_items');
    }
};
