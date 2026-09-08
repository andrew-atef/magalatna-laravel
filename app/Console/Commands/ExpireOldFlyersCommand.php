<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Flyer;
use Carbon\Carbon;
use Illuminate\Console\Command;

final class ExpireOldFlyersCommand extends Command
{
    protected $signature = 'flyers:expire';

    protected $description = 'تحويل المجلات والعروض المنتهية الصلاحية بتوقيت القاهرة إلى حالة expired';

    public function handle(): int
    {
        $cairoToday = Carbon::today('Africa/Cairo')->toDateString();

        $affectedRows = Flyer::where('status', 'published')
            ->where('valid_until', '<', $cairoToday)
            ->update(['status' => 'expired']);

        $this->info("تمت أرشفة وتحديث {$affectedRows} مجلة منتهية الصلاحية.");

        return self::SUCCESS;
    }
}
