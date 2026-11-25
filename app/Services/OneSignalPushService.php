<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Flyer;
use App\Support\R2Url;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class OneSignalPushService
{
    /**
     * Send a rich push notification for a newly published flyer.
     *
     * @throws RuntimeException on HTTP >= 400 or missing config
     * @return array<string, mixed> OneSignal API response
     */
    public function sendFlyerNotification(Flyer $flyer): array
    {
        $config = config('services.onesignal');

        $appId = (string) ($config['app_id'] ?? '');
        $restApiKey = (string) ($config['rest_api_key'] ?? '');

        if ($appId === '') {
            throw new RuntimeException('OneSignal app_id not configured (services.onesignal.app_id)');
        }
        if ($restApiKey === '') {
            throw new RuntimeException('OneSignal rest_api_key not configured (services.onesignal.rest_api_key)');
        }

        // Build canonical URL (NO utm params — hit warmed Cloudflare HTML cache)
        $baseUrl = rtrim((string) config('app.url'), '/');
        $url = $baseUrl . route('flyers.show', ['slug' => $flyer->slug], false);

        // Build rich image: discover_image_path > coverPage > null
        $image = null;
        if (trim((string) $flyer->discover_image_path) !== '') {
            $image = R2Url::asset($flyer->discover_image_path);
        } elseif ($flyer->coverPage && trim((string) $flyer->coverPage->image_path) !== '') {
            $image = R2Url::asset($flyer->coverPage->image_path);
        }

        // Build high-CTR Arabic copy from verified items (NO hallucination)
        $retailerClean = $flyer->retailer?->clean_name ?: $flyer->retailer?->name ?: 'المتجر';
        $maxDiscount = round((float) ($flyer->items()->max('discount_percent') ?? 0));
        $untilAr = '';
        try {
            $untilCarbon = $flyer->valid_until instanceof \Carbon\Carbon
                ? $flyer->valid_until->copy()->setTimezone('Africa/Cairo')
                : \Carbon\Carbon::parse((string) $flyer->valid_until, 'Africa/Cairo');
            $untilAr = $untilCarbon->locale('ar')->isoFormat('D MMMM YYYY');
            if (! preg_match('/[\x{0600}-\x{06FF}]/u', $untilAr)) {
                $untilAr = \App\Support\ArabicDateHelper::formatArabicDate($untilCarbon);
            }
        } catch (\Throwable $e) {
            $untilAr = (string) $flyer->valid_until;
        }

        $topItem = $flyer->items()->where('sale_price', '>', 0)->orderByDesc('discount_percent')->first();
        $topItemName = $topItem?->product_name ?? 'سلع متنوعة';
        $salePrice = $topItem ? (string) round((float) $topItem->sale_price, 2) : '';

        $heading = "🔥 نزل الآن: عروض {$retailerClean} الجديدة!";
        $discountPart = $maxDiscount > 0 ? "خصومات تصل إلى {$maxDiscount}% " : '';
        $pricePart = $salePrice !== '' ? " — {$topItemName} بـ {$salePrice} ج.م" : '';
        $content = "{$discountPart}حتى {$untilAr}{$pricePart}. تصفح المجلة كاملة الآن!";

        // Prepare OneSignal payload
        $payload = [
            'app_id' => $appId,
            'target_channel' => 'push',
            'included_segments' => [ $config['segment'] ?? 'Total Subscriptions' ],
            'headings' => ['en' => $heading, 'ar' => $heading],
            'contents' => ['en' => $content, 'ar' => $content],
            'url' => $url,
            'chrome_web_icon' => $baseUrl . '/img/icon-192.png',
            'collapse_id' => "flyer_{$flyer->id}",
            'ttl' => 86400,
        ];

        if ($image !== null) {
            $payload['chrome_web_image'] = $image;
            $payload['big_picture'] = $image;
            $payload['ios_attachments'] = ['id' => $image];
        }

        // Send to OneSignal REST API
        $response = Http::withHeaders([
            'Authorization' => "Key {$restApiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(30)->post('https://api.onesignal.com/notifications', $payload);

        $responseData = $response->json();

        if (! $response->successful()) {
            $errorMsg = $responseData['errors'] ?? $response->body();
            Log::error('OneSignal push failed', [
                'flyer_id' => $flyer->id,
                'status' => $response->status(),
                'error' => $errorMsg,
            ]);
            throw new RuntimeException("OneSignal API error (HTTP {$response->status()}): {$errorMsg}");
        }

        Log::info('OneSignal push sent', [
            'flyer_id' => $flyer->id,
            'notification_id' => $responseData['id'] ?? null,
            'recipients' => $responseData['recipients'] ?? null,
        ]);

        return $responseData;
    }
}