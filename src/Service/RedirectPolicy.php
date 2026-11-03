<?php
declare(strict_types=1);

namespace CakeMcp\Service;

use CakeMcp\McpConfig;

/**
 * Which redirect URIs a client may register and use.
 *
 * - https URIs on `allowedRedirectHosts` (Claude: claude.ai/claude.com, ChatGPT: chatgpt.com);
 * - http URIs on localhost, 127.0.0.1 or [::1] (Claude Code and Codex listen on a local port).
 *
 * Loopback URIs match a registered loopback URI on any port (RFC 8252 section 7.3): Claude Code
 * and Codex pick a fresh port per login, and Claude Code's metadata lists a port-less
 * `http://localhost/callback`. The policy is re-checked when a client authorizes, so tightening
 * the configuration applies to clients that registered earlier.
 */
final class RedirectPolicy
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];

    private McpConfig $config;

    public function __construct(McpConfig $config)
    {
        $this->config = $config;
    }

    public function isAllowed(string $uri): bool
    {
        if ($uri === '' || strpos($uri, '#') !== false || preg_match('/\s/', $uri)) {
            return false;
        }
        $parts = parse_url($uri);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($scheme === 'https') {
            $hosts = array_map('strtolower', (array)$this->config->get('allowedRedirectHosts', []));

            return $hosts === [] || in_array($host, $hosts, true);
        }
        if ($scheme === 'http') {
            return (bool)$this->config->get('allowLoopbackRedirects') && self::isLoopbackHost($host);
        }

        return false;
    }

    /**
     * @param string $presented The redirect_uri in the request.
     * @param array<string> $registered The client's registered redirect URIs.
     */
    public function matches(string $presented, array $registered): bool
    {
        if (!$this->isAllowed($presented)) {
            return false;
        }
        if (in_array($presented, $registered, true)) {
            return true;
        }
        $p = parse_url($presented);
        if (strtolower($p['scheme'] ?? '') !== 'http' || !self::isLoopbackHost(strtolower($p['host'] ?? ''))) {
            return false;
        }
        foreach ($registered as $uri) {
            $r = parse_url((string)$uri);
            if (
                $r !== false
                && strtolower($r['scheme'] ?? '') === 'http'
                && strtolower($r['host'] ?? '') === strtolower($p['host'])
                && ($r['path'] ?? '') === ($p['path'] ?? '')
                && ($r['query'] ?? null) === ($p['query'] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }

    public static function isLoopback(string $uri): bool
    {
        $parts = parse_url($uri);

        return $parts !== false
            && strtolower($parts['scheme'] ?? '') === 'http'
            && self::isLoopbackHost(strtolower($parts['host'] ?? ''));
    }

    private static function isLoopbackHost(string $host): bool
    {
        return in_array($host, self::LOOPBACK_HOSTS, true);
    }
}
