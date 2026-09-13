<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retailers', function (Blueprint $table): void {
            $table->string('facebook_page_url')->nullable()->after('website_url');
            $table->boolean('auto_ingest_enabled')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('retailers', function (Blueprint $table): void {
            $table->dropColumn(['facebook_page_url', 'auto_ingest_enabled']);
        });
    }
};
