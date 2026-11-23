<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Instant SSH debugging for the ordered Gemini model waterfall:
 * per-model RPM/RPD usage, circuit-breaker cooldowns and daily-quota state.
 *
 *   php artisan gemini:models-status
 *   php artisan gemini:models-status --reset   (clears RPM/cooldown locks ONLY)
 *
 * RPD counters are keyed by the America/Los_Angeles calendar day because the
 * Gemini free tier resets at midnight Pacific — never Cairo/UTC.
 */
final class GeminiModelsStatusCommand extends Command
{
    protected $signature = 'gemini:models-status {--reset : Clear temporary RPM/cooldown locks}';

    protected $description = 'Ordered Gemini model waterfall: per-model RPM/RPD usage, cooldowns and daily-quota status (Pacific-Time reset)';

    public function handle(): int
    {
        $models = config('services.gemini.models', []);

        if (! is_array($models) || $models === []) {
            $this->error('No Gemini models configured (services.gemini.models).');

            return self::FAILURE;
        }

        $reset = (bool) $this->option('reset');
        $ptNow = now('America/Los_Angeles');
        $ptDate = $ptNow->toDateString();

        $rows = [];
        $priority = 0;

        foreach ($models as $model) {
            if (! is_array($model)) {
                continue;
            }

            $name = trim((string) ($model['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $priority++;
            $rpm = max(0, (int) ($model['rpm'] ?? 5));
            $rpd = max(0, (int) ($model['rpd'] ?? 20));
            $rpmKey = "gemini_rpm:{$name}";
            $rpdKey = "gemini_rpd:{$name}:{$ptDate}";
            $cooldownKey = "gemini_cooldown:{$name}";
            $untilKey = "gemini_cooldown_until:{$name}";

            if ($reset) {
                // Temporary locks ONLY — the daily RPD counter is a real quota
                // mirror and must survive a reset (Google still enforces it).
                RateLimiter::clear($rpmKey);
                Cache::forget($cooldownKey);
                Cache::forget($untilKey);
                $this->line(" - cleared RPM/cooldown locks for {$name}");
            }

            $rpmUsed = RateLimiter::attempts($rpmKey);
            $rpdUsed = (int) Cache::get($rpdKey, 0);
            $cooldown = Cache::get($cooldownKey);

            if ($cooldown !== null) {
                $until = (int) Cache::get($untilKey, 0);
                $seconds = max(0, $until - time());
                $status = "Cooldown {$seconds}s ({$cooldown})";
            } elseif ($rpd > 0 && $rpdUsed >= $rpd) {
                $status = 'Daily Exhausted';
            } elseif ($rpm > 0 && RateLimiter::tooManyAttempts($rpmKey, $rpm)) {
                $status = 'RPM Throttled '.RateLimiter::availableIn($rpmKey).'s';
            } else {
                $status = 'Ready';
            }

            $rows[] = [
                $priority,
                $name,
                $rpmUsed.'/'.($rpm > 0 ? (string) $rpm : '∞'),
                $rpdUsed.'/'.($rpd > 0 ? (string) $rpd : '∞'),
                $status,
            ];
        }

        $this->table(
            ['Priority', 'Model', 'RPM (Used/Max)', 'RPD (Used/Max PT)', 'Status'],
            $rows,
        );

        $this->line('RPD day (America/Los_Angeles): '.$ptDate.' — resets at '.$ptNow->copy()->endOfDay()->toDateTimeString().' PT');

        if ($reset) {
            $this->info('Temporary RPM/cooldown locks cleared. Daily RPD counters were kept.');
        }

        return self::SUCCESS;
    }
}
