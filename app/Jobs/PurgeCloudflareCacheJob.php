<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\CloudflareCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PurgeCloudflareCacheJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 60;

    /**
     * @param list<string> $urls
     */
    public function __construct(public readonly array $urls) {}

    public function handle(CloudflareCacheService $cache): void
    {
        try {
            $urls = $this->normalize($this->urls);

            if ($urls === []) {
                Log::info('PurgeCloudflareCacheJob: No URLs to purge.');

                return;
            }

            $purged = $cache->purgeUrls($urls);

            if (! $purged) {
                Log::warning('PurgeCloudflareCacheJob: Cloudflare purge partially failed, still dispatching indexing.', [
                    'urls' => $urls,
                ]);
            }

            // Decoupled: fan out one independent IndexNow worker per URL.
            // No synchronous network calls here — each PingIndexNowJob runs
            // on its own worker with its own retries.
            foreach ($urls as $url) {
                try {
                    PingIndexNowJob::dispatch($url);
                } catch (Throwable $e) {
                    Log::warning('PurgeCloudflareCacheJob: Failed to dispatch IndexNow.', [
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('PurgeCloudflareCacheJob: Completed.', [
                'urls_count' => count($urls),
                'purged' => $purged,
            ]);
        } catch (Throwable $e) {
            Log::error('PurgeCloudflareCacheJob: Unexpected failure.', [
                'urls' => $this->urls,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private function normalize(array $urls): array
    {
        $out = [];
        foreach ($urls as $u) {
            $t = trim((string) $u);
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return array_values(array_unique($out));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('PurgeCloudflareCacheJob: Permanently failed.', [
            'urls' => $this->urls,
            'error' => $exception->getMessage(),
        ]);
    }
}
