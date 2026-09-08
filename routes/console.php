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
    ->timezone('Africa/Cairo');
