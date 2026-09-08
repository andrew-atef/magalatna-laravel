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
            $table->text('editorial_overview')->nullable()->after('bluf_summary');
        });
    }

    public function down(): void
    {
        Schema::table('flyers', function (Blueprint $table): void {
            $table->dropColumn('editorial_overview');
        });
    }
};
