<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Flyer;
use Carbon\Carbon;
use Illuminate\Support\Str;

final class FlyerSlugService
{
    /**
     * توليد Slug إنجليزي نظيف تماماً بناءً على اسم المتجر وفترة العرض
     *
     * أمثلة المخرجات:
     * - يوم واحد: kazyon-6-september-2026
     * - خلال نفس الشهر: kazyon-3-7-september-2026
     * - ممتد بين شهرين: carrefour-28-february-5-march-2026
     */
    public function generate(string $retailerSlug, string $validFromDate, string $validUntilDate): string
    {
        // تنظيف slug المتجر (حذف كلمة egypt إن وجدت للاختصار)
        $cleanRetailer = Str::of($retailerSlug)
            ->replace('-egypt', '')
            ->replace('egypt', '')
            ->trim('-')
            ->toString();

        $from = Carbon::parse($validFromDate, 'Africa/Cairo');
        $until = Carbon::parse($validUntilDate, 'Africa/Cairo');

        $baseSlug = match (true) {
            // سيناريو 1: العرض ليوم واحد فقط (أو تاريخ البداية والنهاية متطابقين)
            $from->isSameDay($until) => sprintf(
                '%s-%d-%s-%d',
                $cleanRetailer,
                $from->day,
                strtolower($from->englishMonth),
                $from->year
            ),

            // سيناريو 2: العرض خلال نفس الشهر ونفس السنة (مثال: من 3 إلى 7 سبتمبر 2026)
            $from->month === $until->month && $from->year === $until->year => sprintf(
                '%s-%d-%d-%s-%d',
                $cleanRetailer,
                $from->day,
                $until->day,
                strtolower($from->englishMonth),
                $from->year
            ),

            // سيناريو 3: العرض يمتد بين شهرين في نفس السنة (مثال: 28 فبراير إلى 5 مارس 2026)
            $from->year === $until->year => sprintf(
                '%s-%d-%s-%d-%s-%d',
                $cleanRetailer,
                $from->day,
                strtolower($from->englishMonth),
                $until->day,
                strtolower($until->englishMonth),
                $from->year
            ),

            // سيناريو 4: يمتد بين سنتين (نهاية ديسمبر إلى أول يناير)
            default => sprintf(
                '%s-%d-%s-%d-%d-%s-%d',
                $cleanRetailer,
                $from->day,
                strtolower($from->englishMonth),
                $from->year,
                $until->day,
                strtolower($until->englishMonth),
                $until->year
            ),
        };

        // التأكد من تفرد الـ Slug إذا كان المتجر نشر مجلتين مختلفتين في نفس التواريخ تماماً
        return $this->ensureUniqueSlug($baseSlug);
    }

    private function ensureUniqueSlug(string $slug): string
    {
        $originalSlug = $slug;
        $counter = 2;

        while (Flyer::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-part-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
