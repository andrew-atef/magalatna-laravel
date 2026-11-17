<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flyers', function (Blueprint $table): void {
            // Google Discover hero composite (1280x720, 16:9) generated from
            // the cover (+ page 2). Flyer-level asset stored on R2 with the
            // `discover_{ulid}.webp` key — the flyer_cards thumb_path lives on
            // flyer_pages (per-page), so the correct flyer-level anchor here
            // is the last flyer-owned column.
            $table->string('discover_image_path')->nullable()->after('editorial_overview');
        });
    }

    public function down(): void
    {
        Schema::table('flyers', function (Blueprint $table): void {
            $table->dropColumn('discover_image_path');
        });
    }
};
