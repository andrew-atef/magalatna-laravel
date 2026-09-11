<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;

final class CairoTime
{
    /**
     * Convert any database timestamp or datetime into a strict Africa/Cairo ISO-8601 string.
     * Ensures UTC raw database digits are correctly shifted by +3 hours during Egypt DST.
     */
    public static function toIso8601(mixed $date): string
    {
        if ($date === null) {
            return now('Africa/Cairo')->toIso8601String();
        }

        if ($date instanceof Carbon) {
            // If already carbon, ensure it's evaluated against Africa/Cairo
            return $date->copy()->setTimezone('Africa/Cairo')->toIso8601String();
        }

        $str = trim((string) $date);

        // If stored as raw UTC string from MySQL (Y-m-d H:i:s), parse explicitly as UTC then convert
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $str) === 1) {
            return Carbon::createFromFormat('Y-m-d H:i:s', $str, 'UTC')
                ->setTimezone('Africa/Cairo')
                ->toIso8601String();
        }

        return Carbon::parse($str)->setTimezone('Africa/Cairo')->toIso8601String();
    }
}
