<?php
declare(strict_types=1);

namespace CakeMcp\Service;

/**
 * Comparison of RFC 8707 resource indicators. Scheme and host are case-insensitive, a default
 * port and a trailing slash are ignored.
 */
final class ResourceIndicator
{
    public static function normalize(string $uri): ?string
    {
        if ($uri === '' || strpos($uri, '#') !== false) {
            return null;
        }
        $parts = parse_url($uri);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $normalized = $scheme . '://' . strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $normalized .= ':' . $port;
        }
        $normalized .= rtrim($parts['path'] ?? '', '/');
        if (isset($parts['query'])) {
            $normalized .= '?' . $parts['query'];
        }

        return $normalized;
    }

    public static function same(string $a, string $b): bool
    {
        $na = self::normalize($a);

        return $na !== null && $na === self::normalize($b);
    }
}
