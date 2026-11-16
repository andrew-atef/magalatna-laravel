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

        if ($date instanceof \DateTimeInterface) {
            return Carbon::instance($date)->setTimezone('Africa/Cairo')->toIso8601String();
        }

        $str = trim((string) $date);

        // Date-only strings are calendar dates with NO time/offset: parse them
        // explicitly in Cairo so they never depend on the app timezone config.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str) === 1) {
            return Carbon::createFromFormat('Y-m-d', $str, 'Africa/Cairo')
                ->startOfDay()
                ->setTimezone('Africa/Cairo')
                ->toIso8601String();
        }

        // Raw DB datetime strings (Y-m-d H:i:s) carry NO offset: they were
        // written in the application timezone — config('app.timezone') — which
        // for this app is Africa/Cairo, NOT always UTC. Parsing them as
        // hardcoded UTC double-shifts Cairo timestamps +2/+3h into the future.
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $str) === 1) {
            $appTz = trim((string) config('app.timezone', 'UTC'));

            if ($appTz === '' || strcasecmp($appTz, 'UTC') === 0) {
                return Carbon::createFromFormat('Y-m-d H:i:s', $str, 'UTC')
                    ->setTimezone('Africa/Cairo')
                    ->toIso8601String();
            }

            return Carbon::createFromFormat('Y-m-d H:i:s', $str, $appTz)
                ->setTimezone('Africa/Cairo')
                ->toIso8601String();
        }

        return Carbon::parse($str)->setTimezone('Africa/Cairo')->toIso8601String();
    }
}
