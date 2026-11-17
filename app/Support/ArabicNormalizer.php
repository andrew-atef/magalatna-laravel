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

        // 5. Separators → spaces: punctuation, brackets, slashes, dashes and
        //    Arabic commas become word boundaries so compound OCR tokens are
        //    addressable by the SQLite space-padded whole-word search:
        //    "(عافيه)" → "عافيه", "زيت-محرك" → "زيت محرك".
        //    The dot is handled separately — a dot flanked by digits on BOTH
        //    sides is a decimal ("1.5") and must survive; any other dot is a
        //    separator ("a." / ".5" / "1.k" → space). OR-semantics via two
        //    alternatives: replace unless (digit before AND digit after).
        $text = (string) preg_replace('/[^\p{L}\p{N}\s.]/u', ' ', $text);
        $text = (string) preg_replace('/(?<!\d)\.|\.(?!\d)/u', ' ', $text);

        // Optional cleanup: normalize multiple spaces, keep search-friendly lowercasing for latin chars
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Convert ALL Eastern Arabic-Indic (٠-٩) and Persian (۰-۹) digits to
     * Western ASCII digits (0-9). Gemini frequently mixes numeral systems
     * inside ONE page ("١ كجم" beside "5 لتر"), which silently breaks every
     * downstream price/quantity parser — everything downstream of OCR calls
     * this FIRST.
     */
    public static function toWesternDigits(string $text): string
    {
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        // Arabic decimal comma: "١,٥" → "1.5". The {1,2} cap + lookahead
        // protects thousands separators ("1,200" / "12,500" stay untouched —
        // a decimal fragment is never 3 digits).
        return (string) preg_replace('/(\d),(\d{1,2})(?!\d)/', '$1.$2', $text);
    }
}
