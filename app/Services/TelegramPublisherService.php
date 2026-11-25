<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Flyer;
use App\Models\FlyerPage;
use App\Support\R2Url;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class TelegramPublisherService
{
    private string $baseUrl;

    public function __construct()
    {
        $botToken = config('services.telegram.bot_token');
        if (! $botToken) {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }
        $this->baseUrl = "https://api.telegram.org/bot{$botToken}/";
    }

    /**
     * Build clean, engaging Arabic caption formatted in HTML (Telegram parse_mode: HTML).
     * STRICT 1024-CHAR LIMIT: Telegram rejects captions > 1024 chars!
     * Enforce word-safe truncation so the final string (including HTML tags) is strictly <= 1000 characters.
     * Escape user-generated text using htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').
     */
    public function buildFlyerCaption(Flyer $flyer): string
    {
        // Load relations if not already loaded
        $flyer->loadMissing(['retailer', 'items', 'pages']);

        $title = htmlspecialchars((string) ($flyer->title ?? 'العرض'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $retailerName = htmlspecialchars((string) ($flyer->retailer?->name ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Format dates in Arabic (Cairo timezone)
        $fromText = $this->formatArabicDate($flyer->valid_from);
        $untilText = $this->formatArabicDate($flyer->valid_until);

        // Get max discount and top products
        $items = $flyer->items;
        $maxDiscount = $items->where('sale_price', '>', 0)->max('discount_percent');
        $hasRealDiscount = $maxDiscount !== null && (float) $maxDiscount > 0;
        $discount = (string) round((float) ($maxDiscount ?? 0));

        $discountSentence = $hasRealDiscount
            ? "بخصومات تصل إلى {$discount}%."
            : 'بأسعار تنافسية مخفضة.';

        $topProducts = $items->where('sale_price', '>', 0)
            ->sortByDesc('discount_percent')
            ->take(5)
            ->pluck('product_name')
            ->filter()
            ->map(fn ($name) => htmlspecialchars((string) $name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            ->implode('، ');
        $topProducts = $topProducts !== '' ? $topProducts : 'سلع متنوعة';

        // Determine if upcoming
        $isUpcoming = false;
        if ($flyer->valid_from) {
            $fromCarbon = $flyer->valid_from instanceof \Carbon\Carbon
                ? $flyer->valid_from->copy()->setTimezone('Africa/Cairo')
                : \Carbon\Carbon::parse((string) $flyer->valid_from, 'Africa/Cairo');
            $isUpcoming = now('Africa/Cairo')->startOfDay()->lt($fromCarbon->copy()->startOfDay());
        }

        // Build BLUF text
        if ($isUpcoming) {
            $blufText = "تصفح {$title} يبدأ في مصر يوم {$fromText} وحتى {$untilText}، {$discountSentence} يشمل العرض تخفيضات قوية على {$topProducts} بجميع الفروع وحتى نفاذ الكمية.";
        } else {
            $blufText = "تصفح {$title} الساري في مصر حتى {$untilText}، {$discountSentence} يشمل العرض تخفيضات قوية على {$topProducts} بجميع الفروع وحتى نفاذ الكمية.";
        }

        // Build canonical flyer URL
        $canonicalUrl = $flyer->slug
            ? config('app.url').'/flyers/'.$flyer->slug
            : config('app.url');

        // Build full caption with HTML formatting
        $caption = "<b>{$title}</b>\n\n";
        $caption .= "📅 سارٍ من {$fromText} حتى {$untilText} بتوقيت القاهرة\n\n";
        $caption .= "{$blufText}\n\n";
        $caption .= "🔗 <b>تصفح أسعار السلع وقارنها بالكامل:</b>\n";
        $caption .= "{$canonicalUrl}";

        // Enforce 1000 character limit (safe margin under Telegram's 1024)
        return $this->truncateHtmlSafe($caption, 1000);
    }

    /**
     * Publish flyer to Telegram channel/group.
     * Returns array of sent message IDs.
     */
    public function publishFlyer(Flyer $flyer, ?string $chatId = null): array
    {
        $chatId = $chatId ?? config('services.telegram.chat_id');
        if (! $chatId) {
            throw new RuntimeException('TELEGRAM_CHAT_ID is not configured.');
        }

        $flyer->loadMissing(['pages' => fn ($q) => $q->orderBy('page_number')]);

        $imageUrls = $flyer->pages
            ->map(fn (FlyerPage $p) => R2Url::asset($p->image_path))
            ->filter()
            ->values()
            ->all();

        if ($imageUrls === []) {
            throw new RuntimeException('Flyer has no page images.');
        }

        $caption = $this->buildFlyerCaption($flyer);

        // Case A — Single Image (1 page flyer)
        if (count($imageUrls) === 1) {
            return [$this->sendPhoto($chatId, $imageUrls[0], $caption)];
        }

        // Case B — Multiple Images (2+ pages): use sendMediaGroup with chunks of max 10
        return $this->sendMediaGroup($chatId, $imageUrls, $caption, $flyer);
    }

    /**
     * Send single photo via /sendPhoto
     */
    private function sendPhoto(string $chatId, string $photoUrl, string $caption): int
    {
        $response = Http::timeout(config('services.telegram.timeout', 30))
            ->post($this->baseUrl.'sendPhoto', [
                'chat_id' => $chatId,
                'photo' => $photoUrl,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ]);

        if (! $response->successful()) {
            $error = $response->json('description') ?? 'Unknown Telegram API error';
            throw new RuntimeException("Telegram sendPhoto failed: {$error}");
        }

        return (int) $response->json('result.message_id');
    }

    /**
     * Send media group via /sendMediaGroup with chunking (max 10 per request)
     */
    private function sendMediaGroup(string $chatId, array $imageUrls, string $caption, Flyer $flyer): array
    {
        $chunks = array_chunk($imageUrls, 10);
        $sentMessageIds = [];

        foreach ($chunks as $chunkIndex => $chunk) {
            $media = [];
            $startPage = $chunkIndex * 10 + 1;
            $endPage = min($startPage + count($chunk) - 1, count($imageUrls));

            foreach ($chunk as $itemIndex => $url) {
                $item = [
                    'type' => 'photo',
                    'media' => $url,
                ];

                // First chunk, first image gets the full caption
                if ($chunkIndex === 0 && $itemIndex === 0) {
                    $item['caption'] = $caption;
                    $item['parse_mode'] = 'HTML';
                }
                // Subsequent chunks: helper caption on first image
                elseif ($chunkIndex > 0 && $itemIndex === 0) {
                    $title = htmlspecialchars((string) $flyer->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $helperCaption = "<b>{$title} — تابع باقي الصفحات (صفحة {$startPage} إلى {$endPage})</b>";
                    $item['caption'] = $helperCaption;
                    $item['parse_mode'] = 'HTML';
                }

                $media[] = $item;
            }

            $response = Http::timeout(config('services.telegram.timeout', 30))
                ->post($this->baseUrl.'sendMediaGroup', [
                    'chat_id' => $chatId,
                    'media' => json_encode($media),
                ]);

            if (! $response->successful()) {
                $error = $response->json('description') ?? 'Unknown Telegram API error';
                throw new RuntimeException("Telegram sendMediaGroup failed: {$error}");
            }

            $results = $response->json('result', []);
            foreach ($results as $result) {
                if (isset($result['message_id'])) {
                    $sentMessageIds[] = (int) $result['message_id'];
                }
            }

            // Respect Telegram group rate limits
            if ($chunkIndex < count($chunks) - 1) {
                usleep(500_000); // 0.5s pause between chunks
            }
        }

        return $sentMessageIds;
    }

    /**
     * Format date to Arabic (Cairo timezone)
     */
    private function formatArabicDate($date): string
    {
        if ($date === null || (is_string($date) && trim($date) === '')) {
            return 'إشعار لاحق';
        }

        try {
            $carbon = $date instanceof \Carbon\Carbon
                ? $date->copy()->setTimezone('Africa/Cairo')
                : \Carbon\Carbon::parse((string) $date, 'Africa/Cairo');

            $arabic = $carbon->locale('ar')->isoFormat('dddd D MMMM YYYY');
            if (! preg_match('/[\x{0600}-\x{06FF}]/u', $arabic)) {
                return \App\Support\ArabicDateHelper::formatArabicDate($carbon);
            }

            return $arabic;
        } catch (\Throwable $e) {
            return (string) $date;
        }
    }

    /**
     * Truncate HTML string safely at word boundary without breaking tags.
     * Returns string <= $maxLength characters (including HTML tags).
     */
    private function truncateHtmlSafe(string $html, int $maxLength): string
    {
        if (mb_strlen($html) <= $maxLength) {
            return $html;
        }

        // We need to truncate the HTML string while preserving valid tag structure
        // Strategy: iterate through the HTML char by char, track tag stack,
        // and stop when we reach maxLength, then close any open tags.
        
        $result = '';
        $tagStack = [];
        $inTag = false;
        $tagBuffer = '';

        for ($i = 0; $i < mb_strlen($html); $i++) {
            $char = mb_substr($html, $i, 1);

            if ($char === '<') {
                $inTag = true;
                $tagBuffer = '<';
                continue;
            }

            if ($inTag) {
                $tagBuffer .= $char;
                if ($char === '>') {
                    $inTag = false;
                    // Handle tag stack
                    if (preg_match('/^<\s*\/?\s*([a-zA-Z0-9]+)/', $tagBuffer, $matches)) {
                        $tagName = strtolower($matches[1]);
                        if (str_starts_with($tagBuffer, '</')) {
                            // Closing tag
                            if (! empty($tagStack) && end($tagStack) === $tagName) {
                                array_pop($tagStack);
                            }
                        } elseif (! str_ends_with($tagBuffer, '/>') && ! in_array($tagName, ['br', 'hr', 'img', 'input', 'meta', 'link'])) {
                            // Opening tag
                            $tagStack[] = $tagName;
                        }
                    }
                }
            }

            // Check if adding this char would exceed maxLength
            if (mb_strlen($result) + 1 > $maxLength) {
                break;
            }

            $result .= $char;
        }

        // Close any unclosed tags in reverse order
        $result = rtrim($result, " \t\n\r");
        foreach (array_reverse($tagStack) as $tag) {
            $result .= "</{$tag}>";
        }

        // Final safety check - if still over limit, hard truncate at word boundary
        if (mb_strlen($result) > $maxLength) {
            $result = mb_substr($result, 0, $maxLength);
            $lastSpace = mb_strrpos($result, ' ');
            if ($lastSpace !== false && $lastSpace > 50) {
                $result = mb_substr($result, 0, $lastSpace);
            }
            // Re-close any tags that might have been cut
            $openTags = [];
            preg_match_all('/<([a-zA-Z0-9]+)[^>]*>/', $result, $matches);
            foreach ($matches[1] as $tag) {
                $tag = strtolower($tag);
                if (! in_array($tag, ['br', 'hr', 'img', 'input', 'meta', 'link'])) {
                    $openTags[] = $tag;
                }
            }
            preg_match_all('/<\/([a-zA-Z0-9]+)>/', $result, $closeMatches);
            foreach ($closeMatches[1] as $tag) {
                $tag = strtolower($tag);
                $key = array_search($tag, $openTags);
                if ($key !== false) {
                    unset($openTags[$key]);
                }
            }
            foreach (array_reverse($openTags) as $tag) {
                $result .= "</{$tag}>";
            }
        }

        return $result;
    }
}