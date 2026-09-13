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

// رادار فيسبوك: مسح كل دقيقة عبر Chromium المحلي — منع التداخل + تشغيل خلفي
Schedule::command('flyers:radar-scan')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/facebook_radar.log'));

// السحب المباشر لايف كل 5 دقائق (بدون dry-run): مجلات جديدة إلى raw_facebook_posts
// + تشغيل Gatekeeper/Gemini تلقائياً — بتوقيت القاهرة
Schedule::command('flyers:ingest-direct')
    ->everyFiveMinutes()
    ->timezone('Africa/Cairo')
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/facebook_ingest.log'));
