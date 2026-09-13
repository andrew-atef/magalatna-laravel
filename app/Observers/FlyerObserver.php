<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\FlyerStatus;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Flyer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class FlyerObserver
{
    public function saved(Flyer $flyer): void
    {
        // Only trigger CDN Purge if flyer is Published and public-facing attributes changed
        if ($flyer->status === FlyerStatus::Published && $flyer->wasChanged(['status', 'title', 'slug', 'valid_from', 'valid_until'])) {
            $this->handlePurge($flyer, 'saved');
        }
    }

    public function deleted(Flyer $flyer): void
    {
        try {
            Cache::forget('sitemap_xml_content');
            Cache::forget('llms_txt_content');

            // Bulk Delete: Send all R2 paths in a single API call instead of an O(N) loop
            $paths = $flyer->pages()->whereNotNull('image_path')->pluck('image_path')->filter()->all();
            if ($paths !== []) {
                try {
                    Storage::disk('r2')->delete($paths);
                } catch (Throwable $e) {
                    Log::warning('FlyerObserver: Bulk R2 delete failed.', [
                        'flyer_id' => $flyer->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $urls = $this->collectUrls($flyer);
            $this->dispatchPurge($urls, 'deleted');
        } catch (Throwable $e) {
            Log::error('FlyerObserver: deleted event failure.', [
                'flyer_id' => $flyer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handlePurge(Flyer $flyer, string $event): void
    {
        try {
            Cache::forget('sitemap_xml_content');
            Cache::forget('llms_txt_content');

            $urls = $this->collectUrls($flyer);
            $this->dispatchPurge($urls, $event);
        } catch (Throwable $e) {
            Log::error('FlyerObserver: handlePurge failure.', [
                'flyer_id' => $flyer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function collectUrls(Flyer $flyer): array
    {
        $urls = [];
        $slug = (string) ($flyer->slug ?? '');

        if ($slug !== '') {
            $urls[] = route('flyers.show', ['slug' => $slug]);
        }

        if ($flyer->retailer_id !== null) {
            $retailer = $flyer->relationLoaded('retailer') ? $flyer->retailer : $flyer->retailer()->first(['slug']);
            if ($retailer !== null && ! empty($retailer->slug)) {
                $urls[] = route('retailers.show', ['retailer' => $retailer->slug]);
            }
        }

        $urls[] = url('/');
        $urls[] = url('/sitemap.xml');
        $urls[] = url('/llms.txt');

        return array_values(array_unique(array_filter(array_map('trim', $urls))));
    }

    /**
     * @param list<string> $urls
     */
    private function dispatchPurge(array $urls, string $event): void
    {
        if ($urls === []) {
            return;
        }

        try {
            PurgeCloudflareCacheJob::dispatch($urls);
        } catch (Throwable $e) {
            Log::error('FlyerObserver: Failed to dispatch PurgeCloudflareCacheJob.', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
