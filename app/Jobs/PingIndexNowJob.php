<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Flyer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PingIndexNowJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 30;

    /**
     * Accepts one of:
     *  - string      : a single full URL
     *  - int|string  : legacy BC — a flyer ID (numeric), resolved to its canonical URL
     *  - list<string>: a batch of full URLs (IndexNow urlList, up to 10,000 per call)
     *
     * @param  string|int|list<string>  $pageUrl
     */
    public function __construct(public readonly mixed $pageUrl) {}

    public function handle(): void
    {
        try {
            $urls = $this->resolveUrls();

            if ($urls === []) {
                return;
            }

            // Config-only key (never env() — unavailable after config:cache).
            $apiKey = trim((string) config('services.indexnow.key', ''));
            $base = rtrim((string) config('app.url'), '/');
            $host = (string) parse_url($base, PHP_URL_HOST);

            if ($apiKey === '') {
                Log::warning('PingIndexNowJob: INDEXNOW_KEY not configured, skipping.', [
                    'url_count' => count($urls),
                ]);

                return;
            }

            // Canonical host from config('app.url') ONLY. Never fall back to a
            // placeholder host (offers.example) or a request-derived one:
            // pinging IndexNow for a host we do not own is a protocol violation
            // and CLI workers on the VPS would resolve 127.0.0.1.
            if ($host === '') {
                Log::warning('PingIndexNowJob: APP_URL host is empty, skipping IndexNow ping.', [
                    'url_count' => count($urls),
                ]);

                return;
            }

            $payload = [
                'host' => $host,
                'key' => $apiKey,
                'keyLocation' => $base."/{$apiKey}.txt",
                'urlList' => $urls,
            ];

            $response = Http::timeout(10)->post('https://api.indexnow.org/indexnow', $payload);

            if ($response->successful()) {
                Log::info('IndexNow pinged successfully.', [
                    'host' => $host,
                    'url_count' => count($urls),
                ]);

                return;
            }

            Log::warning('IndexNow ping failed.', [
                'status' => $response->status(),
                'url_count' => count($urls),
            ]);

            if ($response->status() >= 500) {
                throw new \RuntimeException('IndexNow server error: HTTP '.$response->status());
            }
        } catch (Throwable $e) {
            Log::error('PingIndexNowJob failed.', ['error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * Normalize the constructor input into a de-duplicated list of absolute URLs.
     *
     * @return list<string>
     */
    private function resolveUrls(): array
    {
        $input = $this->pageUrl;

        // Legacy BC: flyer ID => resolve its canonical /offers/{slug} URL from
        // the config base (route() without arguments stays request-independent).
        if (is_int($input) || (is_string($input) && ctype_digit($input))) {
            $flyer = Flyer::query()->find((int) $input);

            if ($flyer === null || (string) $flyer->slug === '') {
                Log::warning('PingIndexNowJob: Flyer not found for ID.', ['pageUrl' => $input]);

                return [];
            }

            return [self::baseUrl().route('flyers.show', ['slug' => (string) $flyer->slug], false)];
        }

        if (is_string($input)) {
            $input = [$input];
        }

        if (! is_array($input)) {
            return [];
        }

        $base = self::baseUrl();
        $out = [];

        foreach ($input as $u) {
            $t = trim((string) $u);

            if ($t === '') {
                continue;
            }

            // Relative paths get the canonical config base prepended.
            if (! preg_match('#^https?://#i', $t)) {
                $t = $base.'/'.ltrim($t, '/');
            }

            $out[] = $t;
        }

        return array_values(array_unique($out));
    }

    /**
     * Canonical host from config only — never request-bound.
     */
    private static function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public function failed(Throwable $exception): void
    {
        Log::error('PingIndexNowJob permanently failed.', ['error' => $exception->getMessage()]);
    }
}
