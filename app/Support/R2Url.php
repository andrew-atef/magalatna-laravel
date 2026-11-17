<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\FlyerPage;
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
            $url = 'https://'.ltrim($url, '/');
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
            return 'https:'.$path;
        }

        $base = self::base();

        if ($base === '') {
            return ltrim($path, '/');
        }

        return $base.'/'.ltrim($path, '/');
    }

    /**
     * Build a Cloudflare Image Resizing URL for a stored R2 path.
     *
     * Cloudflare Free-Plan zones return 403/404 for /cdn-cgi/image/... unless
     * Image Transformations is explicitly enabled, so resizing is OPT-IN via
     * config('filesystems.disks.r2.image_resizing_enabled') — disabled by
     * default, mobile browsers then receive the plain original asset and can
     * NEVER hit a broken image.
     */
    public static function resizedAsset(?string $path, int $width = 600, string $format = 'webp'): ?string
    {
        $base = self::base();
        if (blank($path) || $base === '') {
            return null;
        }

        if (config('filesystems.disks.r2.image_resizing_enabled', false) !== true) {
            return self::asset($path);
        }

        return $base.'/cdn-cgi/image/width='.$width.',format='.$format.'/'.ltrim((string) $path, '/');
    }

    /**
     * Lightweight cover-card URL for grid widgets: prefers the pre-generated
     * 400px WebP thumbnail (~15-22KB) over the 1200x1600 master (~150KB) —
     * page-inside cards never need the full-resolution scan.
     *
     * NEVER throws and NEVER returns a blank src: missing thumb → master
     * image; missing page/master → local placeholder. A missing thumbnail
     * must degrade the image, never the card.
     */
    public static function cardThumb(?FlyerPage $page): string
    {
        if ($page !== null) {
            $thumbPath = trim((string) $page->thumb_path);

            if ($thumbPath !== '') {
                return (string) self::asset($thumbPath);
            }

            $masterPath = trim((string) $page->image_path);

            if ($masterPath !== '') {
                return (string) self::asset($masterPath);
            }
        }

        return '/img/placeholder-flyer.png';
    }
}
