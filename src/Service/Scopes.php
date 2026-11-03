<?php
declare(strict_types=1);

namespace CakeMcp\Service;

use CakeMcp\McpConfig;

/**
 * Scope policy. This server has one access level, so the rule is simple and never refuses:
 *
 * - The configured MCP scope is always granted, even when the client did not ask for it
 *   (ChatGPT and Cursor register without one).
 * - `offline_access` is granted when asked for. Refresh tokens are issued either way.
 * - Anything else (`openid`, `email`, `profile`, ...) is dropped, not refused. ChatGPT and
 *   Codex ask for OpenID scopes on their own, and refusing them makes ChatGPT fail with
 *   "invalid_scope - requested scope is not allowed". RFC 6749 section 3.3 lets a server
 *   grant less than was asked, and the token response's `scope` says what was granted.
 *
 * The check runs against the current configuration on every request. Nothing about allowed
 * scopes is copied into a client's registration, so changing the configuration later applies
 * to clients that registered earlier.
 */
final class Scopes
{
    /**
     * @param \CakeMcp\McpConfig $config Plugin config.
     * @param string|null $requested Space-separated scopes from the request.
     * @return array<string> Granted scopes.
     */
    public static function grant(McpConfig $config, ?string $requested): array
    {
        $granted = [$config->scope()];
        if (in_array('offline_access', self::split($requested), true)) {
            $granted[] = 'offline_access';
        }

        return $granted;
    }

    /**
     * @param string|null $requested Space-separated scopes.
     * @return array<string>
     */
    public static function split(?string $requested): array
    {
        return array_values(array_unique(preg_split('/\s+/', trim((string)$requested), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    /**
     * @param \CakeMcp\McpConfig $config Plugin config.
     * @param string|null $requested Space-separated scopes from the request.
     * @return array<string> Scopes that were asked for and will not be granted.
     */
    public static function dropped(McpConfig $config, ?string $requested): array
    {
        return array_values(array_diff(self::split($requested), self::grant($config, $requested)));
    }
}
