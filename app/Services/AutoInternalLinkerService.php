<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Brand;
use App\Models\Flyer;
use App\Models\Retailer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

final class AutoInternalLinkerService
{
    private const CACHE_TTL = 86400; // 24 hours

    private const MAX_LINKS = 5;

    /**
     * @var array<string, string>|null
     */
    private static ?array $retailerMap = null;

    /**
     * @var array<string, string>|null
     */
    private static ?array $brandMap = null;

    /**
     * Automatically link retailer, brand and product mentions.
     * - Replaces FIRST occurrence only per keyword
     * - Max 3-5 links total
     * - Never inside <a>, <button>, h1-h6
     */
    public function linkify(string $content, ?Flyer $currentFlyer = null): string
    {
        $content = trim($content);

        if ($content === '') {
            return '';
        }

        // Build keyword => url map
        $keywords = $this->buildKeywordMap($currentFlyer);

        if ($keywords === []) {
            return nl2br(e($content), false);
        }

        // Sort by keyword length descending to prefer longer phrases first
        uksort($keywords, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        // Prepare HTML fragment with escaped content + <br> for newlines
        $escaped = e($content);
        $htmlFragment = nl2br($escaped, false);

        // DOM parsing with wrapper
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        // Wrap in div to keep fragment
        $wrapped = '<?xml encoding="utf-8" ?><div>' . $htmlFragment . '</div>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        // Text nodes not inside excluded ancestors
        $excluded = ['a', 'button', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style'];
        $notAncestors = implode(' and ', array_map(static fn (string $tag): string => 'not(ancestor::' . $tag . ')', $excluded));
        $query = '//text()[' . $notAncestors . ']';
        $nodes = $xpath->query($query);

        if ($nodes === false || $nodes->length === 0) {
            return $htmlFragment;
        }

        $linkedKeywords = [];
        $totalLinks = 0;

        // Snapshot nodes to array to avoid live list issues when we replace
        $textNodes = [];
        foreach ($nodes as $n) {
            $textNodes[] = $n;
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

            // Process this text node allowing multiple links within same node (up to max)
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
                    $found = false;
                    $pos = null;
                    $matchText = $keywordTrim;
                    if (preg_match($pattern, $remaining, $m, PREG_OFFSET_CAPTURE)) {
                        $bytePos = $m[0][1];
                        $pos = mb_strlen(substr($remaining, 0, $bytePos));
                        $matchText = $m[0][0];
                        $found = true;
                    } else {
                        $p = mb_strpos($remaining, $keywordTrim);
                        if ($p !== false) {
                            $beforeOk = $p === 0 || ! $this->isLetterOrNumber(mb_substr($remaining, $p - 1, 1));
                            $afterPos = $p + mb_strlen($keywordTrim);
                            $afterOk = $afterPos >= mb_strlen($remaining) || ! $this->isLetterOrNumber(mb_substr($remaining, $afterPos, 1));
                            if ($beforeOk && $afterOk) {
                                $pos = $p;
                                $found = true;
                            }
                        }
                    }
                    if (! $found || $pos === null) {
                        continue;
                    }
                    // Prefer earliest position, tie-break longer keyword
                    if ($bestPos === null || $pos < $bestPos || ($pos === $bestPos && mb_strlen($keywordTrim) > mb_strlen($bestKeyword ?? ''))) {
                        $bestPos = $pos;
                        $bestKeyword = $keywordTrim;
                        $bestUrl = $url;
                        $bestMatchText = $matchText;
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

                $a = $dom->createElement('a', $bestMatchText ?? $bestKeyword);
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

        // Extract innerHTML of wrapper div
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
     * Build keyword => url map, cached.
     *
     * @return array<string, string>
     */
    private function buildKeywordMap(?Flyer $currentFlyer): array
    {
        $map = [];

        // Retailers + Arabic aliases for English-named retailers
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

        $retailers = $this->getRetailers();
        foreach ($retailers as $ret) {
            $name = trim((string) ($ret['name'] ?? ''));
            $slug = trim((string) ($ret['slug'] ?? ''));
            $id = (int) ($ret['id'] ?? 0);
            if ($name === '' || $slug === '') {
                continue;
            }
            // Skip self-link
            if ($currentFlyer !== null && (int) $currentFlyer->retailer_id === $id) {
                continue;
            }
            // Avoid duplicate keyword (first wins)
            if (! isset($map[$name])) {
                $map[$name] = route('retailers.show', $slug);
            }

            // Add Arabic aliases for this retailer slug
            $slugKey = strtolower($slug);
            if (isset($arabicAliases[$slugKey])) {
                foreach ($arabicAliases[$slugKey] as $alias) {
                    $alias = trim($alias);
                    if ($alias !== '' && ! isset($map[$alias])) {
                        // Also skip self-alias if current flyer is this retailer (already skipped)
                        $map[$alias] = route('retailers.show', $slug);
                    }
                }
            }
            // Fallback fuzzy: if slug contains carrefour/bim also add alias
            if (str_contains($slugKey, 'carrefour') && ! isset($map['كارفور'])) {
                $map['كارفور'] = route('retailers.show', $slug);
            }
            if (str_contains($slugKey, 'bim') && ! isset($map['بيم'])) {
                $map['بيم'] = route('retailers.show', $slug);
            }
        }

        // Brands
        $brands = $this->getBrands();
        foreach ($brands as $brand) {
            $name = trim((string) ($brand['name'] ?? ''));
            if ($name === '' || mb_strlen($name) < 2) {
                continue;
            }
            if (! isset($map[$name])) {
                $map[$name] = route('home', ['q' => $name]);
            }
        }

        // Current flyer products - hero products
        if ($currentFlyer !== null) {
            try {
                // Ensure items are loaded
                $items = $currentFlyer->relationLoaded('items')
                    ? $currentFlyer->items
                    : $currentFlyer->items()->get(['id', 'product_name']);

                foreach ($items as $item) {
                    $pName = trim((string) $item->product_name);
                    if ($pName === '' || mb_strlen($pName) < 3) {
                        continue;
                    }
                    // Use full product name as keyword, but also first meaningful chunk?
                    // Keep full name for precise linking, first occurrence only
                    if (! isset($map[$pName])) {
                        $map[$pName] = route('flyers.show', $currentFlyer->slug) . '#item-' . $item->id;
                    }
                }
            } catch (\Throwable $e) {
                // Silent fail for products
            }
        }

        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRetailers(): array
    {
        return Cache::remember('auto_linker:retailers', self::CACHE_TTL, static function (): array {
            return Retailer::where('is_active', true)
                ->get(['id', 'name', 'slug'])
                ->toArray();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getBrands(): array
    {
        return Cache::remember('auto_linker:brands', self::CACHE_TTL, static function (): array {
            return Brand::query()
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->get(['name'])
                ->toArray();
        });
    }

    private function isLetterOrNumber(string $char): bool
    {
        return (bool) preg_match('/[\p{L}\p{N}]/u', $char);
    }
}
