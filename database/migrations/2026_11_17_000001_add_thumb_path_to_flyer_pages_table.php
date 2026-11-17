<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flyer_pages', function (Blueprint $table): void {
            // Lightweight 400px cover thumbnail (page 1 only). Null for every
            // non-cover page; grid cards fall back to image_path when null.
            $table->string('thumb_path')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('flyer_pages', function (Blueprint $table): void {
            $table->dropColumn('thumb_path');
        });
    }
};
