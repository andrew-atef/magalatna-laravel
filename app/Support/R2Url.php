<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final class R2Url
{
    /**
     * Normalize R2 public base URL - fixes missing // after scheme (e.g. https:cdn -> https://cdn)
     */
    public static function base(): string
    {
        $url = trim((string) config('filesystems.disks.r2.url'));

        if ($url === '') {
            return '';
        }

        // Fix malformed scheme without // : https:cdn.magalatna.com -> https://cdn.magalatna.com
        $url = (string) preg_replace('#^https:(?!//)#i', 'https://', $url);
        $url = (string) preg_replace('#^http:(?!//)#i', 'http://', $url);

        // If no scheme at all (cdn.magalatna.com), prepend https://
        if (! Str::startsWith(strtolower($url), ['http://', 'https://'])) {
            $url = 'https://' . ltrim($url, '/');
        }

        return rtrim($url, '/');
    }

    /**
     * Build absolute R2 URL for a given relative path.
     * If path already absolute (https://...), return normalized absolute without double-prefix.
     * Fixes double-host bug: https://www.magalatna.com/https:cdn...
     */
    public static function asset(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $path = trim((string) $path);

        // Already absolute URL (including malformed https:cdn)
        if (preg_match('#^https?:#i', $path)) {
            $path = (string) preg_replace('#^https:(?!//)#i', 'https://', $path);
            $path = (string) preg_replace('#^http:(?!//)#i', 'http://', $path);

            if (Str::startsWith(strtolower($path), ['http://', 'https://'])) {
                return $path;
            }
        }

        // Protocol-relative //cdn.magalatna.com/... -> https://...
        if (str_starts_with($path, '//')) {
            return 'https:' . $path;
        }

        $base = self::base();

        if ($base === '') {
            return ltrim($path, '/');
        }

        return $base . '/' . ltrim($path, '/');
    }
}
