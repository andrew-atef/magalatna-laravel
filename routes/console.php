<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// التشغيل يومياً في تمام الساعة 12:01 منتصف الليل بتوقيت القاهرة
Schedule::command('flyers:expire')
    ->dailyAt('00:01')
    ->timezone('Africa/Cairo')
    ->withoutOverlapping(10)
    ->onOneServer();

// صيانة يومية فعلية (dry-run معطل قصداً) في 03:00 بتوقيت القاهرة — off-peak
Schedule::command('flyers:deduplicate')
    ->dailyAt('03:00')
    ->timezone('Africa/Cairo')
    ->withoutOverlapping(60)
    ->onOneServer();
