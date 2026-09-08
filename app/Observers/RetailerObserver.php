<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Retailer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RetailerObserver
{
    public function saved(Retailer $retailer): void
    {
        $this->handlePurge($retailer, 'saved');
    }

    public function updated(Retailer $retailer): void
    {
        // saved covers updated, keep for spec compliance without double dispatch
    }

    private function handlePurge(Retailer $retailer, string $event): void
    {
        try {
            Cache::forget('sitemap_xml_content');

            $urls = $this->collectUrls($retailer);

            if ($urls === []) {
                return;
            }

            PurgeCloudflareCacheJob::dispatch($urls);

            Log::info('RetailerObserver: Dispatched purge.', [
                'retailer_id' => $retailer->id,
                'event' => $event,
                'urls_count' => count($urls),
            ]);
        } catch (Throwable $e) {
            Log::error('RetailerObserver: handlePurge failed.', [
                'retailer_id' => $retailer->id ?? 'unknown',
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function collectUrls(Retailer $retailer): array
    {
        $urls = [];

        try {
            $slug = (string) ($retailer->slug ?? '');
            if ($slug !== '') {
                try {
                    $urls[] = route('retailers.show', $slug);
                } catch (Throwable $e) {
                    $urls[] = rtrim((string) config('app.url'), '/') . '/' . $slug;
                }
            }
        } catch (Throwable $e) {
            Log::warning('RetailerObserver: retailer URL failed.', ['error' => $e->getMessage()]);
        }

        try {
            $urls[] = url('/');
            $urls[] = rtrim((string) url('/'), '/') . '/';
            $appUrl = rtrim((string) config('app.url'), '/');
            if ($appUrl !== '' && $appUrl !== url('/')) {
                $urls[] = $appUrl;
                $urls[] = $appUrl . '/';
            }
            $urls[] = url('/sitemap.xml');
            $urls[] = rtrim((string) config('app.url'), '/') . '/sitemap.xml';
        } catch (Throwable $e) {
            Log::warning('RetailerObserver: homepage/sitemap collection failed.', ['error' => $e->getMessage()]);
        }

        $urls = array_values(array_unique(array_filter(array_map(static fn (string $u): string => trim($u), $urls), static fn (string $u): bool => $u !== '')));

        return $urls;
    }
}
