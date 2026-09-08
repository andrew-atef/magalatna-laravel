<?php

declare(strict_types=1);

namespace App\Support;

final class ArabicNormalizer
{
    /**
     * Normalize Arabic text for consistent search indexing.
     *
     * - Removes diacritics / tashkeel (حركات) and tatweel
     * - Unifies Alef variants (أ, إ, آ -> ا)
     * - Unifies Yaa / Alef Maksura (ى -> ي)
     * - Unifies Taa Marbuta / Haa (ة -> ه)
     */
    public static function normalize(string $text): string
    {
        // Trim and reduce whitespace early
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        // 1. Remove tatweel (ـ) U+0640 and diacritics / tashkeel
        //    Tashkeel ranges: 064B-065F, 0670, and 0640 tatweel
        $text = (string) preg_replace('/[\x{0640}\x{064B}-\x{065F}\x{0670}]/u', '', $text);

        // 2. Unify Alef variants: أ إ آ -> ا
        //    Includes Alef with Hamza Above, Below, and Madda Above
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);

        // 3. Unify Yaa / Alef Maksura: ى -> ي
        //    Requirement states (ى, ي -> ي) - we map ى to ي, ي stays.
        $text = str_replace('ى', 'ي', $text);

        // 4. Unify Taa Marbuta / Haa: ة -> ه
        //    Requirement states (ة, ه -> ه) - we map ة to ه, ه stays.
        $text = str_replace('ة', 'ه', $text);

        // Optional cleanup: normalize multiple spaces, keep search-friendly lowercasing for latin chars
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        return $text;
    }
}
