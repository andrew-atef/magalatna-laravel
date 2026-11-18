<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flyer_reels', function (Blueprint $table): void {
            // TikTok/Reels post description: hook + CTA + hashtags.
            $table->text('caption')->nullable()->after('script_text');
        });
    }

    public function down(): void
    {
        Schema::table('flyer_reels', function (Blueprint $table): void {
            $table->dropColumn('caption');
        });
    }
};
