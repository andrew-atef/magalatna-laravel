<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

final class ArabicDateHelper
{
    /**
     * Arabic month name (1-12), e.g. 9 => سبتمبر.
     */
    public static function monthName(int $month): string
    {
        return match ($month) {
            1 => 'يناير',
            2 => 'فبراير',
            3 => 'مارس',
            4 => 'أبريل',
            5 => 'مايو',
            6 => 'يونيو',
            7 => 'يوليو',
            8 => 'أغسطس',
            9 => 'سبتمبر',
            10 => 'أكتوبر',
            11 => 'نوفمبر',
            12 => 'ديسمبر',
            default => 'يناير',
        };
    }

    /**
     * Arabic weekday name for the given date, e.g. الأحد.
     */
    public static function dayName(CarbonInterface $date): string
    {
        return match ((int) $date->dayOfWeek) {
            0 => 'الأحد',
            1 => 'الإثنين',
            2 => 'الثلاثاء',
            3 => 'الأربعاء',
            4 => 'الخميس',
            5 => 'الجمعة',
            6 => 'السبت',
            default => $date->locale('ar')->isoFormat('dddd'),
        };
    }

    /**
     * Arabic date, e.g. الأحد 13 سبتمبر 2026.
     */
    public static function formatArabicDate(CarbonInterface $date): string
    {
        return sprintf(
            '%s %d %s %d',
            self::dayName($date),
            (int) $date->day,
            self::monthName((int) $date->month),
            (int) $date->year
        );
    }

    /**
     * Arabic grammatical number pluralization (تمييز العدد):
     *   1 → "1 صفحة" · 2 → "صفحتان" · 3..10 → "3 صفحات" · 11+ → "25 صفحة"
     *
     * Arabic uses SINGULAR after 11 (seventeen page, not pages) — naive
     * "X صفحات" for every count reads like machine translation and Google
     * Discover/Translate both rank the grammatical phrasing better.
     */
    public static function pluralizeCount(int $count, string $singular, string $dual, string $plural, string $plural11Plus): string
    {
        if ($count === 1) {
            return "{$count} {$singular}";
        }

        if ($count === 2) {
            return $dual;
        }

        if ($count >= 3 && $count <= 10) {
            return "{$count} {$plural}";
        }

        return "{$count} {$plural11Plus}";
    }
}
