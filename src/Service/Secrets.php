<?php
declare(strict_types=1);

namespace CakeMcp\Service;

/**
 * Random identifiers and secrets. Secrets are stored only as SHA-256 hashes.
 */
final class Secrets
{
    public static function token(string $prefix, int $bytes = 32): string
    {
        return $prefix . '_' . self::base64url(random_bytes($bytes));
    }

    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * The PKCE S256 challenge for a verifier (RFC 7636 section 4.2).
     */
    public static function pkceChallenge(string $verifier): string
    {
        return self::base64url(hash('sha256', $verifier, true));
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
