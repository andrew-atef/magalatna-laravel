<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * SSRF guard shared by every service that fetches remote URLs originating
 * from untrusted input (webhook image URLs, AI-generated sources).
 *
 * Only public http(s) origins may be fetched: literal loopback/private hosts,
 * private/reserved IP literals, and hosts that resolve to private/reserved
 * addresses are all rejected BEFORE any byte is transferred.
 */
final class PublicUrlGuard
{
    /**
     * @throws RuntimeException when the URL must not be fetched
     */
    public static function assertPublicHttpUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Blocked URL: scheme/host not allowed.');
        }

        // Literal host check first: "localhost" and IP literals never need DNS.
        $lowerHost = strtolower($host);

        if ($lowerHost === 'localhost' || str_ends_with($lowerHost, '.localhost')) {
            throw new RuntimeException('Blocked URL: host is not a public address.');
        }

        // parse_url keeps IPv6 brackets in the host ("[::1]"); strip them so
        // FILTER_VALIDATE_IP can actually recognise the literal.
        $cleanHost = trim($host, '[]');

        if (filter_var($cleanHost, FILTER_VALIDATE_IP) !== false) {
            // IP literal — validate directly; it never needs DNS.
            if (! self::isPublicIp($cleanHost)) {
                throw new RuntimeException('Blocked URL: host is not a public address.');
            }

            return;
        }

        // Resolve DNS and validate every returned address.
        $resolved = @dns_get_record($cleanHost, DNS_A + DNS_AAAA);

        if ($resolved === false) {
            // Unresolvable at check time — the HTTP client would fail on its
            // own; treat as blocked rather than guessing.
            throw new RuntimeException('Blocked URL: host does not resolve.');
        }

        foreach ($resolved as $record) {
            // A records carry "ip"; AAAA records carry "ipv6".
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');

            if ($ip !== '' && ! self::isPublicIp($ip)) {
                throw new RuntimeException('Blocked URL: host resolves to a private/reserved address.');
            }
        }
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Extra belt-and-braces for ranges PHP flags miss (0.0.0.0/8).
        if (str_starts_with($ip, '0.')) {
            return false;
        }

        // CGNAT is exactly 100.64.0.0/10 — NOT all of 100.0.0.0/8 (e.g.
        // 100.20.x.x is ordinary public space). ip2long() handles IPv4 only,
        // so IPv6 input correctly falls through as public.
        $long = ip2long($ip);

        if ($long !== false && ($long & 0xFFC00000) === 0x64400000) {
            return false;
        }

        return true;
    }
}
