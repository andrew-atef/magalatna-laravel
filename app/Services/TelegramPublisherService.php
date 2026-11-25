<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Flyer;
use App\Models\FlyerPage;
use App\Support\ArabicDateHelper;
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
     * STRICT 950-CHAR LIMIT (safe margin under Telegram's 1024).
     * Enforce word-safe truncation so the final string (including HTML tags) is strictly <= 950 characters.
     * Escape all user-generated text using htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').
     */
    public function buildFlyerCaption(Flyer $flyer): string
    {
        // Load relations if not already loaded
        $flyer->loadMissing(['retailer', 'items', 'pages']);

        // Clean retailer name: strip Latin parentheticals like "(Kazyon)"
        $retailerName = $flyer->retailer?->name ?? '';
        $retailerClean = htmlspecialchars(
            trim(preg_replace('/\s*\([^()]*[A-Za-z][^()]*\)/u', '', $retailerName)),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        // Format dates in Cairo timezone
        $fromCarbon = $this->parseDateCarbon($flyer->valid_from);
        $untilCarbon = $this->parseDateCarbon($flyer->valid_until);
        $todayCarbon = now('Africa/Cairo')->startOfDay();

        $isSingleDay = $fromCarbon && $untilCarbon && $fromCarbon->isSameDay($untilCarbon);

        // Get items with prices
        $items = $flyer->items
            ->where('sale_price', '>', 0)
            ->sortByDesc('discount_percent')
            ->values();

        $maxDiscount = $items->max('discount_percent');
        $hasRealDiscount = $maxDiscount !== null && (float) $maxDiscount > 0;
        $maxDiscountInt = $hasRealDiscount ? (string) round((float) $maxDiscount) : '';

        // Page count for CTA
        $pagesCount = $flyer->pages->count();
        $pagesCountAr = ArabicDateHelper::pluralizeCount($pagesCount, 'صفحة', 'صفحتان', 'صفحات', 'صفحة');

        // Build canonical URL using route() - NEVER hardcode /flyers/
        $baseUrl = rtrim((string) config('app.url'), '/');
        $canonicalUrl = $flyer->slug
            ? $baseUrl . route('flyers.show', ['slug' => $flyer->slug], false)
            : $baseUrl;

        // Build caption parts
        $parts = [];

        // --- HEADLINE ---
        if ($isSingleDay) {
            $headline = "🔥 <b>عروض {$retailerClean} — عرض اليوم الواحد</b>";
        } else {
            $headline = "🔥 <b>أحدث عروض {$retailerClean}</b>";
        }
        $parts[] = $headline;

        // --- DATE LINE (ONCE ONLY) ---
        if ($fromCarbon && $untilCarbon) {
            if ($isSingleDay) {
                $dayName = ArabicDateHelper::dayName($fromCarbon);
                $dateAr = $fromCarbon->day . ' ' . ArabicDateHelper::monthName((int) $fromCarbon->month);

                if ($fromCarbon->gt($todayCarbon)) {
                    // Future
                    $diffDays = $todayCarbon->diffInDays($fromCarbon, false);
                    if ($diffDays === 1) {
                        $dateLine = "📅 يبدأ غداً {$dayName} {$dateAr}";
                    } else {
                        $dateLine = "📅 يبدأ يوم {$dayName} {$dateAr}";
                    }
                } elseif ($fromCarbon->isSameDay($todayCarbon)) {
                    // Today
                    $dateLine = "📅 سارٍ اليوم {$dayName} {$dateAr} (حتى نفاذ الكمية)";
                } else {
                    // Past/active
                    $dateLine = "📅 {$dayName} {$dateAr}";
                }
            } else {
                // Multi-day
                $fromAr = $this->formatArabicDateShort($fromCarbon);
                $untilAr = $this->formatArabicDateShort($untilCarbon);
                $dateLine = "📅 سارٍ من {$fromAr} حتى {$untilAr}";
            }
            $parts[] = $dateLine;
        }

        // --- TOP DEALS WITH REAL PRICES ---
        if ($items->isNotEmpty()) {
            $dealsHeader = $hasRealDiscount
                ? "⭐ <b>أقوى العروض (خصومات تصل إلى {$maxDiscountInt}%):</b>"
                : '⭐ <b>أبرز السلع والأسعار:</b>';
            $parts[] = $dealsHeader;

            $dealLines = [];
            foreach ($items->take(5) as $item) {
                $name = htmlspecialchars((string) $item->product_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $salePrice = $this->formatPrice((float) $item->sale_price);
                $line = "• {$name}: <b>{$salePrice} ج.م</b>";

                $oldPrice = $item->old_price ? (float) $item->old_price : 0;
                if ($oldPrice > (float) $item->sale_price) {
                    $oldFormatted = $this->formatPrice($oldPrice);
                    $line .= " {$oldFormatted} ج.م";
                }

                $discount = $item->discount_percent ? round((float) $item->discount_percent) : 0;
                if ($discount >= 5) {
                    $line .= " (خصم {$discount}%)";
                }

                $dealLines[] = $line;
            }

            $parts[] = implode("\n", $dealLines);
        }

        // --- CTA WITH CANONICAL /offers/ LINK ---
        $cta = "🔗 <b>شاهد الـ {$pagesCountAr} والأسعار كاملة:</b>\n{$canonicalUrl}";
        $parts[] = $cta;

        // Join and enforce 950 char limit
        $caption = implode("\n\n", $parts);

        return $this->truncateHtmlSafe($caption, 950);
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
     * Parse date to Carbon in Cairo timezone, or null if invalid.
     */
    private function parseDateCarbon($date): ?\Carbon\Carbon
    {
        if ($date === null || (is_string($date) && trim($date) === '')) {
            return null;
        }

        try {
            return $date instanceof \Carbon\Carbon
                ? $date->copy()->setTimezone('Africa/Cairo')
                : \Carbon\Carbon::parse((string) $date, 'Africa/Cairo');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Format date for multi-day range: "13 سبتمبر" (no weekday, no year)
     */
    private function formatArabicDateShort(\Carbon\Carbon $carbon): string
    {
        return $carbon->day . ' ' . ArabicDateHelper::monthName((int) $carbon->month);
    }

    /**
     * Format price: trim trailing .00, .50 -> "12", "12.5"
     */
    private function formatPrice(float $price): string
    {
        $formatted = rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
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