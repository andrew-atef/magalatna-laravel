<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FlyerStatus;
use App\Jobs\GenerateFlyerReelJob;
use App\Models\Flyer;
use App\Models\FlyerReel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GenerateFlyerReelCommand extends Command
{
    protected $signature = 'flyers:generate-reel
                            {flyer_id : معرّف المجلة المطلوب توليد الريل لها}
                            {--force : إعادة التوليد حتى لو كان الريل جاهزاً بالفعل}';

    protected $description = 'توليد ريل عمودي 9:16 بصوت إعلاني مصري (Gemini TTS + ffmpeg) لمجلة واحدة من الـ CLI';

    public function handle(): int
    {
        $flyerId = (int) $this->argument('flyer_id');
        $force = (bool) $this->option('force');

        $flyer = Flyer::query()->find($flyerId);

        if ($flyer === null) {
            $this->error("المجلة [ID: {$flyerId}] غير موجودة.");

            return self::FAILURE;
        }

        if ($flyer->status !== FlyerStatus::Published) {
            $this->warn("المجلة [ID: {$flyerId}] غير منشورة (الحالة: {$flyer->status->value}) — سيتم المحاولة مع ذلك.");
        }

        $reel = FlyerReel::query()->where('flyer_id', $flyerId)->first();

        if ($reel !== null && $reel->isReady() && ! $force) {
            $this->warn("الريل جاهز بالفعل للمجلة [ID: {$flyerId}] ({$reel->video_path}). استخدم --force لإعادة التوليد.");

            return self::SUCCESS;
        }

        $this->info("بدء توليد الريل للمجلة [ID: {$flyerId}] ({$flyer->title})...");

        try {
            GenerateFlyerReelJob::dispatchSync($flyerId);
        } catch (Throwable $e) {
            $this->error('فشل توليد الريل: '.$e->getMessage());

            Log::error('GenerateFlyerReelCommand failed.', [
                'flyer_id' => $flyerId,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        $reel = FlyerReel::query()->where('flyer_id', $flyerId)->first();

        if ($reel !== null && $reel->isReady()) {
            $this->info('✔ تم التوليد بنجاح.');
            $this->line("  المسار على R2: {$reel->video_path}");
            $this->line("  مدة الصوت: {$reel->audio_duration} ثانية");
            $this->line("  مدة الفيديو: {$reel->video_duration} ثانية");
            $this->line('  وصف التيكتوك (انسخه مع الفيديو):');

            foreach (preg_split('/\r?\n/u', (string) $reel->caption) ?: [] as $captionLine) {
                if (trim($captionLine) !== '') {
                    $this->line('    '.$captionLine);
                }
            }

            return self::SUCCESS;
        }

        $this->error('انتهى التنفيذ لكن الريل ليس جاهزاً.'.($reel !== null && $reel->error_message ? ' السبب: '.$reel->error_message : ''));

        return self::FAILURE;
    }
}
