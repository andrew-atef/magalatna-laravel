<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\FlyerStatus;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Flyer;
use App\Models\FlyerPage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class FlyerPageObserver
{
    /**
     * Replaced image (retry / Filament re-upload): reap the OLD R2 object and
     * purge the parent flyer — both AFTER the row update commits, both async.
     */
    public function updating(FlyerPage $page): void
    {
        try {
            if (! $page->isDirty('image_path')) {
                return;
            }

            $oldPath = trim((string) $page->getRawOriginal('image_path'));
            $newPath = trim((string) $page->image_path);

            if ($oldPath === '' || $oldPath === $newPath) {
                return;
            }

            // 1) Async R2 deletion of the orphaned old object — never
            //    synchronous network I/O inside the surrounding transaction.
            dispatch(static function () use ($oldPath): void {
                try {
                    Storage::disk('r2')->delete($oldPath);
                } catch (Throwable $e) {
                    Log::warning('FlyerPageObserver: replaced-image R2 delete failed.', [
                        'path' => $oldPath,
                        'error' => $e->getMessage(),
                    ]);
                }
            })->afterCommit();

            // 2) Purge the parent flyer's canonical URL when it is public.
            $flyerId = $page->flyer_id;

            if ($flyerId === null) {
                return;
            }

            $flyer = Flyer::query()->whereKey($flyerId)->first(['id', 'slug', 'status']);

            if ($flyer !== null && $flyer->status === FlyerStatus::Published && (string) $flyer->slug !== '') {
                $base = rtrim((string) config('app.url'), '/');
                $url = $base.route('flyers.show', ['slug' => (string) $flyer->slug], false);

                PurgeCloudflareCacheJob::dispatch([$url])->afterCommit();
            }
        } catch (Throwable $e) {
            Log::error('FlyerPageObserver: updating event failure.', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function deleted(FlyerPage $page): void
    {
        try {
            $imagePath = trim((string) ($page->image_path ?? ''));
            $flyerId = $page->flyer_id;

            // 1) Async R2 deletion — never synchronous network I/O inside a
            //    SQLite transaction (Filament repeater deletes run in one).
            if ($imagePath !== '') {
                $path = $imagePath;

                dispatch(static function () use ($path): void {
                    try {
                        Storage::disk('r2')->delete($path);
                    } catch (Throwable $e) {
                        Log::warning('FlyerPageObserver: R2 delete failed.', [
                            'path' => $path,
                            'error' => $e->getMessage(),
                        ]);
                    }
                })->afterCommit();
            }

            // 2) Purge the parent flyer's canonical URL when it is public.
            if ($flyerId === null) {
                return;
            }

            $flyer = Flyer::query()->whereKey($flyerId)->first(['id', 'slug', 'status']);

            if ($flyer !== null && $flyer->status === FlyerStatus::Published && (string) $flyer->slug !== '') {
                $base = rtrim((string) config('app.url'), '/');
                $url = $base.route('flyers.show', ['slug' => (string) $flyer->slug], false);

                PurgeCloudflareCacheJob::dispatch([$url])->afterCommit();
            }
        } catch (Throwable $e) {
            Log::error('FlyerPageObserver: deleted event failure.', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
