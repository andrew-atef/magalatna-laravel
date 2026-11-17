<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flyer_items', function (Blueprint $table): void {
            // Deterministic Egyptian-retail category assigned post-OCR by
            // ProductDataNormalizer::inferCategory() — never trusted from AI
            // alone. Indexed: the item table filter needs O(log n) lookups.
            $table->string('category', 50)->nullable()->index()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('flyer_items', function (Blueprint $table): void {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
