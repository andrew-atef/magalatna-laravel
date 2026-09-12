<?php

declare(strict_types=1);

namespace App\Support;

final class FacebookMediaHelper
{
    /**
     * Extract the unique immutable Facebook photo signature/filename from any CDN URL.
     * Ignores rotating subdomains (scontent-cdg, scontent-mrs, etc.) and query tokens.
     *
     * Example: "https://scontent-cdg4-1.xx.fbcdn.net/v/t39.../801939761_1415987247345388_n.png?stp=..."
     * Returns: "801939761_1415987247345388_n.png" (or unique numeric sequence).
     */
    public static function extractPhotoSignature(string $url): string
    {
        $path = (string) parse_url(trim($url), PHP_URL_PATH);
        $basename = basename($path);

        // Match standard Facebook image pattern (e.g., 801939761_1415987247345388_51847365996235752_n.png)
        if (preg_match('/(\d+_\d+_\d+_n\.[a-z0-9]+)/i', $basename, $matches)) {
            return strtolower($matches[1]);
        }

        if (preg_match('/(\d+_\d+_n\.[a-z0-9]+)/i', $basename, $matches)) {
            return strtolower($matches[1]);
        }

        return strtolower($basename !== '' ? $basename : md5($url));
    }
}
