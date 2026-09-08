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
     * @param  string|int  $pageUrl  Full URL to ping, e.g. https://example.com/flyer/my-slug or flyer ID for BC
     */
    public function __construct(public readonly mixed $pageUrl) {}

    public function handle(): void
    {
        try {
            // Resolve URL: support both string URL and legacy int flyerId
            $url = (string) $this->pageUrl;
            if (is_int($this->pageUrl) || (is_string($this->pageUrl) && ctype_digit((string) $this->pageUrl))) {
                $flyer = Flyer::find((int) $this->pageUrl);
                if ($flyer === null) {
                    Log::warning('PingIndexNowJob: Flyer not found for ID.', ['pageUrl' => $this->pageUrl]);

                    return;
                }
                $url = route('flyers.show', $flyer->slug);
            }

            $apiKey = (string) (config('services.indexnow.key') ?? config('services.indexnow_key') ?? env('INDEXNOW_KEY', ''));
            $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

            if (trim($apiKey) === '') {
                Log::warning('PingIndexNowJob: INDEXNOW_KEY not configured, skipping.', ['pageUrl' => $url]);

                return;
            }

            if (trim($host) === '') {
                $host = (string) parse_url($url, PHP_URL_HOST) ?: 'amanprice.example';
            }

            $payload = [
                'host' => $host,
                'key' => $apiKey,
                'keyLocation' => rtrim((string) config('app.url'), '/')."/{$apiKey}.txt",
                'urlList' => [$url],
            ];

            $response = Http::timeout(10)->post('https://api.indexnow.org/indexnow', $payload);

            if ($response->successful()) {
                Log::info("IndexNow Pinged successfully for URL: {$url}");

                return;
            }

            Log::warning("IndexNow Ping failed for {$url} with status: ".$response->status());

            if ($response->status() >= 500) {
                throw new \RuntimeException('IndexNow server error: HTTP '.$response->status());
            }
        } catch (Throwable $e) {
            Log::error('PingIndexNowJob failed.', ['pageUrl' => $this->pageUrl, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('PingIndexNowJob permanently failed.', ['pageUrl' => $this->pageUrl, 'error' => $exception->getMessage()]);
    }
}
