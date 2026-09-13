<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Brand;
use App\Models\Flyer;
use App\Models\Retailer;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class AutoInternalLinkerService
{
    private const CACHE_TTL = 86400; // 24 hours
    private const MAX_LINKS = 5;

    public function linkify(string $content, ?Flyer $currentFlyer = null): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        $keywords = $this->buildKeywordMap($currentFlyer);
        if ($keywords === []) {
            return nl2br(e($content), false);
        }

        // Sort descending by string length to link longest matching phrases first
        uksort($keywords, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $escaped = e($content);
        $htmlFragment = nl2br($escaped, false);

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="utf-8" ?><div>' . $htmlFragment . '</div>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $excluded = ['a', 'button', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style'];
        $notAncestors = implode(' and ', array_map(static fn (string $tag): string => 'not(ancestor::' . $tag . ')', $excluded));
        $nodes = $xpath->query('//text()[' . $notAncestors . ']');

        if ($nodes === false || $nodes->length === 0) {
            return $htmlFragment;
        }

        $linkedKeywords = [];
        $totalLinks = 0;

        // Snapshot to avoid mutation side-effects during live iteration
        $textNodes = [];
        foreach ($nodes as $node) {
            $textNodes[] = $node;
        }

        foreach ($textNodes as $textNode) {
            if ($totalLinks >= self::MAX_LINKS) {
                break;
            }

            $origText = $textNode->nodeValue;
            if ($origText === null || trim($origText) === '') {
                continue;
            }

            $parent = $textNode->parentNode;
            if ($parent === null) {
                continue;
            }

            $remaining = $origText;
            $inserted = false;

            while ($remaining !== '' && $totalLinks < self::MAX_LINKS) {
                $bestKeyword = null;
                $bestUrl = null;
                $bestPos = null;
                $bestMatchText = null;

                foreach ($keywords as $keyword => $url) {
                    if (isset($linkedKeywords[$keyword])) {
                        continue;
                    }
                    $keywordTrim = trim($keyword);
                    if ($keywordTrim === '') {
                        continue;
                    }

                    $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($keywordTrim, '/') . '(?![\p{L}\p{N}_])/u';
                    if (preg_match($pattern, $remaining, $matches, PREG_OFFSET_CAPTURE)) {
                        $bytePos = $matches[0][1];
                        $pos = mb_strlen(substr($remaining, 0, $bytePos));
                        $matchText = $matches[0][0];

                        if ($bestPos === null || $pos < $bestPos || ($pos === $bestPos && mb_strlen($keywordTrim) > mb_strlen($bestKeyword ?? ''))) {
                            $bestPos = $pos;
                            $bestKeyword = $keywordTrim;
                            $bestUrl = $url;
                            $bestMatchText = $matchText;
                        }
                    }
                }

                if ($bestKeyword === null || $bestPos === null || $bestUrl === null) {
                    break;
                }

                $before = mb_substr($remaining, 0, $bestPos);
                $after = mb_substr($remaining, $bestPos + mb_strlen($bestMatchText ?? $bestKeyword));

                if ($before !== '') {
                    $parent->insertBefore($dom->createTextNode($before), $textNode);
                }

                // SECURE DOM NODE GENERATION: Prevents DOMException with & and special characters
                /** @var DOMElement $a */
                $a = $dom->createElement('a');
                $a->appendChild($dom->createTextNode($bestMatchText ?? $bestKeyword));
                $a->setAttribute('href', $bestUrl);
                $a->setAttribute('class', 'text-[#039652] hover:text-[#023b55] hover:underline font-bold');

                if (str_contains($bestUrl, '?q=') || str_contains($bestUrl, '&q=')) {
                    $a->setAttribute('rel', 'nofollow');
                }

                $parent->insertBefore($a, $textNode);

                $linkedKeywords[$bestKeyword] = true;
                $totalLinks++;
                $inserted = true;
                $remaining = $after;
            }

            if ($inserted) {
                if ($remaining !== '') {
                    $parent->insertBefore($dom->createTextNode($remaining), $textNode);
                }
                $parent->removeChild($textNode);
            }
        }

        $wrapper = $dom->getElementsByTagName('div')->item(0);
        if ($wrapper === null) {
            return $htmlFragment;
        }

        $inner = '';
        foreach ($wrapper->childNodes as $child) {
            $inner .= $dom->saveHTML($child);
        }

        return $inner !== '' ? $inner : $htmlFragment;
    }

    /**
     * @return array<string, string>
     */
    private function buildKeywordMap(?Flyer $currentFlyer): array
    {
        $map = [];

        $arabicAliases = [
            'carrefouregypt' => ['كارفور', 'كارفور مصر'],
            'carrefour' => ['كارفور', 'كارفور مصر'],
            'bimmisr' => ['بيم', 'بيم مصر'],
            'bim' => ['بيم', 'بيم مصر'],
            'hyperone' => ['هايبر وان'],
            'fathalla' => ['فتح الله'],
            'elmarshdy' => ['المرشدي', 'أسواق المرشدي'],
            'raneen' => ['رنين'],
        ];

        $retailers = Cache::remember('auto_linker:retailers', self::CACHE_TTL, static function (): array {
            return Retailer::query()->where('is_active', true)->get(['id', 'name', 'slug'])->toArray();
        });

        foreach ($retailers as $ret) {
            $name = trim((string) ($ret['name'] ?? ''));
            $slug = trim((string) ($ret['slug'] ?? ''));
            $id = (int) ($ret['id'] ?? 0);

            if ($name === '' || $slug === '' || ($currentFlyer !== null && (int) $currentFlyer->retailer_id === $id)) {
                continue;
            }

            $routeUrl = route('retailers.show', ['retailer' => $slug]);
            $map[$name] ??= $routeUrl;

            $slugKey = strtolower($slug);
            if (isset($arabicAliases[$slugKey])) {
                foreach ($arabicAliases[$slugKey] as $alias) {
                    $map[$alias] ??= $routeUrl;
                }
            }
        }

        $brands = Cache::remember('auto_linker:brands', self::CACHE_TTL, static function (): array {
            return Brand::query()->whereNotNull('name')->where('name', '!=', '')->limit(100)->pluck('name')->all();
        });

        foreach ($brands as $brandName) {
            $brandName = trim((string) $brandName);
            if (mb_strlen($brandName) >= 2) {
                $map[$brandName] ??= route('home', ['q' => $brandName]);
            }
        }

        if ($currentFlyer !== null) {
            try {
                $items = $currentFlyer->relationLoaded('items')
                    ? $currentFlyer->items
                    : $currentFlyer->items()->limit(15)->get(['id', 'product_name']);

                foreach ($items as $item) {
                    $pName = trim((string) $item->product_name);
                    if (mb_strlen($pName) >= 3) {
                        $map[$pName] ??= route('flyers.show', ['slug' => $currentFlyer->slug]);
                    }
                }
            } catch (Throwable) {
                // Defensive degradation
            }
        }

        return $map;
    }
}
