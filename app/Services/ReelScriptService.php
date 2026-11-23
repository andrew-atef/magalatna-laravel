<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Flyer;
use App\Models\FlyerItem;
use Carbon\Carbon;
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

    /**
     * Hard ceiling (≈35s of speech). Gemini is asked for 45–65 words, but
     * slight overshoots used to throw and waste a perfectly good script —
     * anything above this is trimmed at a sentence boundary instead.
     */
    private const MAX_WORDS = 90;

    public function __construct(
        private readonly GeminiVisionService $geminiVisionService,
    ) {}

    /**
     * @return array{script: string, caption: string, source: string}
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
        } catch (Throwable $e) {
            $this->warn('ReelScriptService: Gemini script failed, using deterministic fallback.', [
                'flyer_id' => $flyer->id,
                'error' => $e->getMessage(),
            ]);

            $script = $this->fallbackScript($flyer, $items, $retailerName);
        }

        // The closing CTA MUST be spoken by the voiceover, not just shown on
        // screen — Gemini occasionally drops it, so it is enforced here.
        $script = $this->ensureClosingCta($script, $flyer);

        // Speech + on-screen captions must stay Arabic-first: transliterate
        // any Latin brand token left by the LLM and fix decimal commas so
        // both TTS pronunciation and libass rendering stay smooth.
        $script = $this->normalizeForSpeech($script);

        try {
            $caption = $this->viaGeminiCaption($flyer, $script, $retailerName);
        } catch (Throwable $e) {
            $this->warn('ReelScriptService: Gemini caption failed, using deterministic fallback.', [
                'flyer_id' => $flyer->id,
                'error' => $e->getMessage(),
            ]);

            $caption = $this->fallbackCaption($flyer, $script, $retailerName);
        }

        return ['script' => $script, 'caption' => $caption, 'source' => 'gemini'];
    }

    /**
     * Logging must never break reel generation: a read-only or mis-owned
     * log file should degrade to a silent skip, not abort the job.
     *
     * @param  array<string, mixed>  $context
     */
    private function warn(string $message, array $context): void
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable) {
            // Logging unavailable (permissions/disk full) — carry on.
        }
    }

    /**
     * Arabic-first normalization for BOTH the voiceover and the burned-in
     * captions:
     *  - decimal commas / Arabic decimal separator between digits become a
     *    plain dot ("41,95" and "41٫95" → "41.95") — TTS reads it right and
     *    libass shows a stable LTR number run;
     *  - Latin brand tokens are transliterated through the shared
     *    config('ocr_aliases.brand_aliases') dictionary (Elvive → إلفيف,
     *    Lipton → ليبتون, Pepsi → بيبسي, …).
     */
    private function normalizeForSpeech(string $text): string
    {
        $text = (string) preg_replace('/(?<=\d)[,٫](?=\d)/u', '.', $text);

        $map = $this->latinBrandMap();

        if ($map !== []) {
            $pattern = '/(?<![\p{L}\p{N}])('.implode('|', array_map(
                static fn (string $latin): string => preg_quote($latin, '/'),
                array_keys($map)
            )).')(?![\p{L}\p{N}])/iu';

            $text = (string) preg_replace_callback(
                $pattern,
                static fn (array $match): string => $map[mb_strtolower($match[0])] ?? $match[0],
                $text
            );
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Latin (only) keys of the brand alias dictionary, longest first so
     * multi-word brands are replaced before their individual words.
     *
     * @return array<string, string>
     */
    private function latinBrandMap(): array
    {
        $map = [];

        foreach ((array) config('ocr_aliases.brand_aliases', []) as $raw => $canonical) {
            $key = trim((string) $raw);

            if ($key === '' || preg_match('/^[A-Za-z0-9 .&\-]+$/', $key) !== 1) {
                continue; // Arabic-keyed aliases are handled by ProductDataNormalizer.
            }

            if (! is_string($canonical) || trim($canonical) === '') {
                continue;
            }

            $map[mb_strtolower($key)] = trim($canonical);
        }

        uksort($map, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $map;
    }

    /**
     * Guarantees the voiceover ends with the urgency hook + the magalatna
     * CTA ("الرابط في أول كومنت والبايو") — the single most important line
     * for driving traffic, so it can never depend on LLM compliance.
     */
    private function ensureClosingCta(string $script, Flyer $flyer): string
    {
        [, , $daysSpan, $singleDay] = self::offerWindow($flyer);

        $urgency = 'العرض '.($singleDay ? 'ليوم واحد بس' : $daysSpan).' والكمية بتطير!';
        $linkLine = 'شوف باقي الأسعار والمجلات على منصة مجلاتنا.. الرابط في أول كومنت والبايو.';

        $script = trim($script);

        $hasUrgency = str_contains($script, 'والكمية بتطير');
        $hasLink = str_contains($script, 'الرابط في أول كومنت');

        if ($hasUrgency && $hasLink) {
            return $script;
        }

        // Drop a PARTIAL closing left behind by Gemini, so the enforced
        // closing never stutters ("شوف باقي الأسعار… شوف باقي الأسعار…").
        if (! $hasLink) {
            $cut = mb_strpos($script, 'شوف باقي الأسعار');

            if ($cut !== false) {
                $script = trim(mb_substr($script, 0, $cut));
            }
        }

        $closing = trim(($hasUrgency ? '' : $urgency.' ').($hasLink ? '' : $linkLine));

        // Keep the voiceover under ~25s: trim the BODY at a sentence
        // boundary — the closing CTA itself always survives intact.
        $budget = self::MAX_WORDS - self::wordCount($closing);

        if (self::wordCount($script) > $budget) {
            $script = $this->trimToSentenceBudget($script, max(0, $budget));
        }

        return trim($script.' '.$closing);
    }

    /**
     * Cuts text at the last whole sentence that fits the word budget (word
     * boundary as a last resort), so trimming never leaves a half sentence.
     */
    private function trimToSentenceBudget(string $text, int $budget): string
    {
        if ($budget <= 0) {
            return '';
        }

        $sentences = preg_split('/(?<=[.!؟])\s+/u', trim($text)) ?: [$text];
        $kept = [];

        foreach ($sentences as $sentence) {
            if (self::wordCount(implode(' ', array_merge($kept, [$sentence]))) > $budget) {
                break;
            }

            $kept[] = $sentence;
        }

        if ($kept === []) {
            $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $kept = [implode(' ', array_slice($words, 0, $budget))];
        }

        return rtrim(trim(implode(' ', $kept)), '، ');
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

        // Same ordered model waterfall + per-model RPM/RPD circuit breaker as
        // vision/OCR — never a raw single-model Http::post here.
        $raw = $this->geminiVisionService->generatePlainText($prompt, 0.8);

        $text = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = str_replace(['"', '“', '”', '«', '»'], '', $text);
        $text = (string) preg_replace('/^[\s\*#\-]*(سكريبت|السكريبت)[:：]?\s*/u', '', $text);
        $text = trim($text);

        if ($text === '' || preg_match('/[\x{0600}-\x{06FF}]/u', $text) !== 1) {
            throw new RuntimeException('Gemini script is empty or not Arabic.');
        }

        $wordCount = self::wordCount($text);

        if ($wordCount < self::MIN_WORDS) {
            throw new RuntimeException('Gemini script too short: '.$wordCount.' words.');
        }

        // Slight overshoot is normal from an LLM — trim it at a sentence
        // boundary instead of throwing away an otherwise usable script.
        if ($wordCount > self::MAX_WORDS) {
            $text = $this->trimToSentenceBudget($text, self::MAX_WORDS);
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

        $opener = "عرض {$daysSpan} من {$retailerName} نزل رسمي لـ{$from}، وفيه توفير تقيل!";
        $closing = 'العرض '.($singleDay ? 'ليوم واحد بس' : $daysSpan)
            .' والكمية بتطير! شوف باقي الأسعار والمجلات على منصة مجلاتنا.. الرابط في أول كومنت والبايو.';

        $itemLines = [];
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

            $itemLines[] = $line.'!';
        }

        // NEVER chop the closing CTA: the word budget trims ITEM lines only,
        // so the voiceover always ends with the urgency hook + magalatna CTA.
        $budget = self::MAX_WORDS - self::wordCount($opener.' '.$closing);
        $keptItems = [];

        foreach ($itemLines as $line) {
            $lineWords = self::wordCount($line);

            if ($keptItems !== [] && $lineWords > $budget) {
                break;
            }

            $keptItems[] = $line;
            $budget -= $lineWords;
        }

        return trim(implode(' ', array_merge([$opener], $keptItems, [$closing])));
    }

    /**
     * Word count using the same tokenization as the gate (whitespace split).
     */
    private static function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * TikTok/Reels post description via Gemini — short hook + CTA + hashtags.
     * A bad/overlong/empty response falls back to the deterministic builder.
     */
    private function viaGeminiCaption(Flyer $flyer, string $script, string $retailerName): string
    {
        $prompt = <<<PROMPT
اكتب وصف (caption) لفيديو تيك توك/ريلز عن العروض دي.

السكريبت:
{$script}

القواعد الصارمة:
- 3 أسطر بالظبط، بالعامية المصرية البيضاء.
- السطر 1: هوك قصير جذاب عن العرض (أقل من 15 كلمة).
- السطر 2: "كل الأسعار كاملة على منصة مجلاتنا — الرابط في أول كومنت والبايو 👇"
- السطر 3: هاشتاجات مصفوفة: #عروض_مصر #عروض_اليوم #مجلاتنا #{$retailerName} (بدون مسافات جوا الهاشتاج).
- ممنوع أي إنجليزية أو إيموجي في أول سطرين، وهاشتاجات بس في التالت.
- اخرج نص الوصف فقط بدون تنسيق.
PROMPT;

        // Same waterfall engine as the script call above.
        $text = trim($this->geminiVisionService->generatePlainText($prompt, 0.7));

        if ($text === '' || preg_match('/[\x{0600}-\x{06FF}]/u', $text) !== 1 || mb_strlen($text) > 300) {
            throw new RuntimeException('Gemini caption is empty, not Arabic, or too long.');
        }

        return $text;
    }

    /**
     * Deterministic caption fallback — hook from the script + fixed CTA +
     * hashtags. Always valid even when Gemini is down.
     */
    private function fallbackCaption(Flyer $flyer, string $script, string $retailerName): string
    {
        $words = preg_split('/\s+/u', $script, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $hook = implode(' ', array_slice($words, 0, 12));
        $hashtagRetailer = trim((string) preg_replace('/\s+/u', '_', $retailerName));

        return $hook."\n"
            ."🔥 كل الأسعار كاملة على منصة مجلاتنا — الرابط في أول كومنت والبايو 👇\n"
            .'#عروض_مصر #عروض_اليوم #مجلاتنا #'.$hashtagRetailer;
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
