<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Flyer;
use App\Models\FlyerItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Stateless service that drafts the natural Egyptian radio-ad commercial
 * script (45–65 words, strictly 15–25 seconds spoken) for a flyer Reel.
 *
 * Gemini Flash is prompted with the flyer's top discounted items; a
 * deterministic Egyptian fallback script keeps the pipeline alive whenever
 * the LLM call fails or returns non-Arabic / overlong copy.
 */
final class ReelScriptService
{
    private const MIN_WORDS = 45;

    private const MAX_WORDS = 65;

    private const TIMEOUT_SECONDS = 60;

    /**
     * @return array{script: string, source: string}
     */
    public function generate(Flyer $flyer): array
    {
        $items = $this->topDiscountedItems($flyer);

        if ($items->isEmpty()) {
            throw new RuntimeException('Flyer #'.$flyer->id.' has no items with sale_price > 0 — cannot draft a reel script.');
        }

        $retailerName = $flyer->retailer?->name ?? 'المتجر';

        try {
            $script = $this->viaGemini($flyer, $items, $retailerName);

            return ['script' => $script, 'source' => 'gemini'];
        } catch (Throwable $e) {
            Log::warning('ReelScriptService: Gemini script failed, using deterministic fallback.', [
                'flyer_id' => $flyer->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ['script' => $this->fallbackScript($flyer, $items, $retailerName), 'source' => 'fallback'];
    }

    /**
     * Up to 3 top discounted items (sale_price > 0), richest discount first.
     *
     * @return \Illuminate\Support\Collection<int, FlyerItem>
     */
    private function topDiscountedItems(Flyer $flyer): \Illuminate\Support\Collection
    {
        return $flyer->relationLoaded('items')
            ? $flyer->items->filter(static fn (FlyerItem $item): bool => (float) $item->sale_price > 0 && trim((string) $item->product_name) !== '')
                ->sortByDesc(static fn (FlyerItem $item): float => (float) $item->discount_percent)
                ->values()
                ->take(3)
            : $flyer->items()
                ->where('sale_price', '>', 0)
                ->whereNotNull('product_name')
                ->orderByDesc('discount_percent')
                ->limit(3)
                ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FlyerItem>  $items
     */
    private function viaGemini(Flyer $flyer, \Illuminate\Support\Collection $items, string $retailerName): string
    {
        $apiKey = trim((string) config('services.gemini.key', ''));

        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $itemsText = $items
            ->map(static function (FlyerItem $item): string {
                $sale = self::trimDecimal((float) $item->sale_price);
                $old = (float) $item->old_price > 0 ? ' (بدل '.self::trimDecimal((float) $item->old_price).')' : '';
                $savings = self::savingsAmount($item) > 0 ? '، توفير '.self::trimDecimal(self::savingsAmount($item)).' جنيه' : '';

                return '- '.trim((string) $item->product_name).': '.$sale.' جنيه'.$old.$savings;
            })
            ->implode("\n");

        [$from, $until, $daysSpan, $singleDay] = self::offerWindow($flyer);
        $ctaPhrase = $singleDay ? 'ليوم واحد بس' : 'لمدة '.$daysSpan.' والكمية بتطير';

        $prompt = <<<PROMPT
اكتب سكريبت إعلان راديو/ريلز مصري حماسي لعروض {$retailerName}.

بيانات العرض:
- ساري من {$from} حتى {$until} ({$daysSpan}).
- أهم 3 سلع مخفضة:
{$itemsText}

النبرة المطلوبة: حماسي، سريع، بالعامية المصرية البيضاء المبهجة (أسلوب إعلانات الراديو/الريلز).

الهيكل الصارم:
1. الافتتاحية (الهوك): "عرض {$daysSpan} من {$retailerName} نزل رسمي لـ{$from}، وفيه توفير تقيل!"
2. السلع: اذكر 3 سلع استراتيجية بأسعارها والتوفير بجمل سريعة وواضحة (استخدم الأرقام كما هي).
3. الدعوة للتحرك: "العرض {$ctaPhrase}! شوف باقي الأسعار والمجلات على منصة 'مجلاتنا'.. الرابط في أول كومنت والبايو."

قواعد صارمة:
- طول النص من 45 إلى 65 كلمة بالظبط (15–25 ثانية نطق).
- بالعامية المصرية البيضاء — ممنوع الفصحى المتكلفة وممنوع أي إنجليزية.
- الأسعار بنفس صيغتها: "219 جنيه بدل 244" أو "بـ 41.95 بس".
- أخرج نص السكريبت فقط بدون أي مقدمات أو علامات تنسيق.
PROMPT;

        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'x-goog-api-key' => $apiKey,
            ])
            ->withOptions(['http_errors' => false])
            ->post('https://generativelanguage.googleapis.com/v1beta/models/'
                .trim((string) config('services.gemini.model', 'gemini-2.5-flash')).':generateContent', [
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature' => 0.8,
                    'topP' => 0.95,
                    'responseMimeType' => 'text/plain',
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gemini script call failed: HTTP '.$response->status());
        }

        $text = trim(html_entity_decode((string) $response->json('candidates.0.content.parts.0.text'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = str_replace(['"', '“', '”', '«', '»'], '', $text);
        $text = (string) preg_replace('/^[\s\*#\-]*(سكريبت|السكريبت)[:：]?\s*/u', '', $text);
        $text = trim($text);

        if ($text === '' || preg_match('/[\x{0600}-\x{06FF}]/u', $text) !== 1) {
            throw new RuntimeException('Gemini script is empty or not Arabic.');
        }

        $wordCount = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        if ($wordCount < self::MIN_WORDS || $wordCount > self::MAX_WORDS) {
            throw new RuntimeException('Gemini script word count out of range: '.$wordCount);
        }

        return $text;
    }

    /**
     * Deterministic Egyptian fallback — assembled locally, always in range.
     *
     * @param  \Illuminate\Support\Collection<int, FlyerItem>  $items
     */
    private function fallbackScript(Flyer $flyer, \Illuminate\Support\Collection $items, string $retailerName): string
    {
        [$from, $until, $daysSpan, $singleDay] = self::offerWindow($flyer);

        $lines = ["عرض {$daysSpan} من {$retailerName} نزل رسمي لـ{$from}، وفيه توفير تقيل!"];
        $index = 0;

        foreach ($items as $item) {
            $index++;
            $sale = self::trimDecimal((float) $item->sale_price);
            $savings = self::savingsAmount($item);
            $old = (float) $item->old_price > 0 ? ' بدل '.self::trimDecimal((float) $item->old_price) : '';

            $line = 'رقم '.$index.': '.trim((string) $item->product_name).' بـ '.$sale.' جنيه'.$old;

            if ($savings > 0) {
                $line .= '، بتوفير '.self::trimDecimal($savings).' جنيه في الباكت';
            }

            $lines[] = $line.'!';
        }

        $lines[] = 'العرض '.($singleDay ? 'ليوم واحد بس' : 'لمدة '.$daysSpan).' والكمية بتطير! شوف باقي الأسعار والمجلات على منصة مجلاتنا.. الرابط في أول كومنت والبايو.';

        $script = trim(implode(' ', $lines));
        $words = preg_split('/\s+/u', $script, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Fallback copy is allowed to be slightly shorter than the Gemini
        // gate (never longer): a reel voiceover must stay under ~25s.
        if (count($words) > self::MAX_WORDS) {
            $script = implode(' ', array_slice($words, 0, self::MAX_WORDS));
        }

        return $script;
    }

    /**
     * Egyptian phrasing helpers — "219" reads natural, "219.00" reads robotic.
     */
    private static function trimDecimal(float $value): string
    {
        $trimmed = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }

    /**
     * Old − sale, falling back to the stored savings_amount.
     */
    private static function savingsAmount(FlyerItem $item): float
    {
        if ((float) $item->old_price > 0 && (float) $item->old_price > (float) $item->sale_price) {
            return (float) $item->old_price - (float) $item->sale_price;
        }

        return max(0.0, (float) $item->savings_amount);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: bool} [fromArabic, untilArabic, windowPhrase, isSingleDay]
     */
    private static function offerWindow(Flyer $flyer): array
    {
        $format = static function (mixed $value): string {
            if ($value instanceof Carbon) {
                return $value->locale('ar')->isoFormat('dddd D MMMM');
            }

            if (is_string($value) && trim($value) !== '') {
                try {
                    return Carbon::parse(trim($value), 'Africa/Cairo')->locale('ar')->isoFormat('dddd D MMMM');
                } catch (Throwable) {
                    return trim($value);
                }
            }

            return '';
        };

        $from = $format($flyer->valid_from);
        $until = $format($flyer->valid_until);

        $isSingleDay = $from !== '' && $from === $until;

        $daysSpan = $isSingleDay
            ? 'عرض اليوم الواحد'
            : (function () use ($flyer, $format): string {
                if ($flyer->valid_from instanceof Carbon && $flyer->valid_until instanceof Carbon) {
                    // Carbon 3 returns a signed float — abs() + round keeps a
                    // stable day count regardless of date order.
                    $days = (int) round(abs($flyer->valid_from->diffInDays($flyer->valid_until))) + 1;

                    return 'لمدة '.max(1, $days).' أيام';
                }

                return 'عرض الأسبوع';
            })();

        return [$from, $until, $daysSpan, $isSingleDay];
    }
}
