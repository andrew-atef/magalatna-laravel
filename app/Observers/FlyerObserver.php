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
        $this->handlePurge($flyer, 'saved');
    }

    public function updated(Flyer $flyer): void
    {
        // saved already covers updated (Eloquent fires saved on update), avoid double purge
        // Keep method for spec compliance, but no-op to prevent duplicate queue jobs
    }

    public function deleted(Flyer $flyer): void
    {
        try {
            Cache::forget('sitemap_xml_content');
            Cache::forget('llms_txt_content');

            // Delete all associated flyer_pages images from R2
            try {
                $pages = $flyer->pages()->get(['image_path']);
                foreach ($pages as $page) {
                    $path = (string) $page->image_path;
                    if (trim($path) !== '') {
                        try {
                            Storage::disk('r2')->delete($path);
                        } catch (Throwable $e) {
                            Log::warning('FlyerObserver: Failed to delete R2 image on flyer delete.', [
                                'flyer_id' => $flyer->id,
                                'path' => $path,
                                'error' => $e->getMessage(),
                            ]);
                            // Fallback try public disk
                            try {
                                Storage::disk('public')->delete($path);
                            } catch (Throwable $e2) {
                                Log::warning('FlyerObserver: Fallback delete also failed.', ['path' => $path, 'error' => $e2->getMessage()]);
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::error('FlyerObserver: Failed to cleanup flyer pages R2 images.', [
                    'flyer_id' => $flyer->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $urls = $this->collectUrls($flyer);
            $this->dispatchPurge($urls, 'deleted');
        } catch (Throwable $e) {
            Log::error('FlyerObserver: deleted handler failed.', [
                'flyer_id' => $flyer->id ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handlePurge(Flyer $flyer, string $event): void
    {
        // Only purge public cache and notify search engines if the flyer is actually published
        if ($flyer->status !== FlyerStatus::Published && $event !== 'deleted') {
            return;
        }

        try {
            Cache::forget('sitemap_xml_content');
            Cache::forget('llms_txt_content');

            $urls = $this->collectUrls($flyer);

            $this->dispatchPurge($urls, $event);
        } catch (Throwable $e) {
            Log::error('FlyerObserver: handlePurge failed.', [
                'flyer_id' => $flyer->id ?? 'unknown',
                'event' => $event,
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

        try {
            // a. Flyer URL
            $slug = (string) ($flyer->slug ?? '');
            if ($slug !== '') {
                try {
                    $urls[] = route('flyers.show', $slug);
                } catch (Throwable $e) {
                    Log::warning('FlyerObserver: Failed to generate flyer URL.', ['slug' => $slug, 'error' => $e->getMessage()]);
                    $urls[] = rtrim((string) config('app.url'), '/') . '/offers/' . $slug;
                }
            }
        } catch (Throwable $e) {
            Log::warning('FlyerObserver: flyer URL collection failed.', ['error' => $e->getMessage()]);
        }

        try {
            // b. Retailer Hub URL
            $retailer = null;
            try {
                $retailer = $flyer->retailer;
            } catch (Throwable $e) {
                $retailer = null;
            }

            if ($retailer === null && isset($flyer->retailer_id)) {
                try {
                    $retailer = \App\Models\Retailer::find($flyer->retailer_id);
                } catch (Throwable $e) {
                    $retailer = null;
                }
            }

            if ($retailer !== null && ! empty($retailer->slug)) {
                try {
                    $urls[] = route('retailers.show', $retailer->slug);
                } catch (Throwable $e) {
                    $urls[] = rtrim((string) config('app.url'), '/') . '/' . $retailer->slug;
                }
            }
        } catch (Throwable $e) {
            Log::warning('FlyerObserver: retailer URL collection failed.', ['error' => $e->getMessage()]);
        }

        try {
            // c. Homepage both variants
            $home = rtrim((string) config('app.url'), '/');
            if ($home === '') {
                $home = url('/');
            }
            $urls[] = url('/');
            $urls[] = rtrim((string) url('/'), '/') . '/';
            // Also include APP_URL variants for Cloudflare cache key consistency
            $appUrl = rtrim((string) config('app.url'), '/');
            if ($appUrl !== '' && $appUrl !== url('/')) {
                $urls[] = $appUrl;
                $urls[] = $appUrl . '/';
            }
        } catch (Throwable $e) {
            Log::warning('FlyerObserver: homepage URL collection failed.', ['error' => $e->getMessage()]);
        }

        try {
            // d. Sitemap
            $urls[] = url('/sitemap.xml');
            $appSitemap = rtrim((string) config('app.url'), '/') . '/sitemap.xml';
            $urls[] = $appSitemap;
        } catch (Throwable $e) {
            Log::warning('FlyerObserver: sitemap URL collection failed.', ['error' => $e->getMessage()]);
        }

        try {
            // e. LLMs.txt
            $urls[] = url('/llms.txt');
            $appLlms = rtrim((string) config('app.url'), '/') . '/llms.txt';
            $urls[] = $appLlms;
        } catch (Throwable $e) {
            Log::warning('FlyerObserver: llms URL collection failed.', ['error' => $e->getMessage()]);
        }

        // Deduplicate and normalize
        $urls = array_values(array_unique(array_filter(array_map(static fn (string $u): string => trim($u), $urls), static fn (string $u): bool => $u !== '')));

        return $urls;
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
            Log::info('FlyerObserver: Dispatched PurgeCloudflareCacheJob.', [
                'event' => $event,
                'flyer_id' => $urls[0] ?? 'unknown',
                'urls_count' => count($urls),
            ]);
        } catch (Throwable $e) {
            Log::error('FlyerObserver: Failed to dispatch PurgeCloudflareCacheJob.', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
