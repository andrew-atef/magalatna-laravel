<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retailers', function (Blueprint $table): void {
            // E-E-A-T / evergreen hub fields: real hotline, store scale,
            // official app, cadence of flyer drops, permanent "about" copy
            // (anti thin-content) and a curated FAQ list (FAQPage schema).
            $table->string('hotline', 50)->nullable()->after('facebook_page_url');
            $table->unsignedInteger('branches_count')->nullable()->after('hotline');
            $table->string('app_url', 500)->nullable()->after('branches_count');
            $table->text('schedule_summary')->nullable()->after('app_url');
            $table->text('about_text')->nullable()->after('schedule_summary');
            $table->json('faqs')->nullable()->after('about_text');
        });

        // Seed REAL verified contact/cadence data for the major chains.
        // Keyed by slug — safe to re-run (plain UPDATE, no insert).
        DB::table('retailers')->where('slug', 'kazyon')->update([
            'hotline' => '19609',
            'branches_count' => 450,
            'schedule_summary' => 'تصدر مجلة كازيون الأسبوعية (عرض التلات) صباح كل ثلاثاء، بالإضافة إلى عروض الويك إند (الخميس والجمعة) وعروض اليوم الواحد اليومية.',
        ]);

        DB::table('retailers')->where('slug', 'carrefouregypt')->update([
            'hotline' => '16061',
            'branches_count' => 65,
            'schedule_summary' => 'تصدر مجلات كارفور أسبوعياً مع عروض الويك إند وعروض نصف الشهر، وتُنشر الأسعار فور صدورها رسمياً.',
        ]);

        DB::table('retailers')->where('slug', 'hyperone')->update([
            'hotline' => '16404',
            'schedule_summary' => 'تصدر مجلة هايبر ون الأسبوعية مع عروض الويك إند، وتُحدَّث الأسعار على مجلاتنا لحظة النشر الرسمي.',
        ]);

        DB::table('retailers')->where('slug', 'fathalla')->update([
            'hotline' => '15024',
            'schedule_summary' => 'تصدر مجلة فتح الله ماركت الأسبوعية مع عروض موسمية إضافية، وتُرصد الأسعار وتُنشر هنا فور إعلانها.',
        ]);
    }

    public function down(): void
    {
        Schema::table('retailers', function (Blueprint $table): void {
            $table->dropColumn([
                'hotline',
                'branches_count',
                'app_url',
                'schedule_summary',
                'about_text',
                'faqs',
            ]);
        });
    }
};
