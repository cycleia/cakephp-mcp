<?php
declare(strict_types=1);

namespace CakeMcp;

use Cake\Core\Configure;
use RuntimeException;

/**
 * The plugin's settings, read from `Configure::read('CakeMcp')` and merged over DEFAULTS.
 *
 * Every URL the plugin advertises is built from `baseUrl`, never from the request's Host
 * header, so a forged Host cannot change what the discovery documents say.
 */
final class McpConfig
{
    public const DEFAULTS = [
        // Public origin of the site, e.g. 'https://example.org'. Falls back to App.fullBaseUrl.
        'baseUrl' => null,
        // Path of the MCP endpoint. The OAuth resource is baseUrl . mcpPath.
        'mcpPath' => '/mcp',
        // Path of the OAuth endpoints. The issuer is baseUrl . oauthPath.
        'oauthPath' => '/oauth',
        // Shown on the approval page. Defaults to the host name of baseUrl.
        'siteName' => null,
        // The one scope this server grants. Always granted, even when a client does not ask.
        'scope' => 'mcp',

        // How to find the signed-in user and decide whether they are an administrator.
        'usersTable' => 'Users',
        'userFinder' => 'all',
        'roleField' => 'role',
        'adminRoles' => ['admin'],
        // A boolean column that must be truthy (skipped when the entity has no such field). null disables.
        'activeField' => 'active',
        // Changing this column revokes the user's connections. null disables.
        'passwordField' => 'password',
        // Column used to name the user on the approval page; falls back to email, username, name.
        'displayField' => null,
        // callable(\Cake\Datasource\EntityInterface $user): bool. Replaces roleField/adminRoles/activeField.
        'isAdmin' => null,
        // callable(\Cake\Http\ServerRequest $request): string|int|null. Replaces the lookup below.
        'identityResolver' => null,
        // Session key holding the user id for apps on the old AuthComponent.
        'sessionUserKey' => 'Auth.User.id',
        // Where to send someone who is not signed in, and the query parameter that brings them back.
        'loginUrl' => '/login',
        'loginRedirectParam' => 'redirect',

        // Redirect URIs a client may register: https on these hosts, or http on localhost.
        // An empty list allows any https host.
        'allowedRedirectHosts' => ['claude.ai', 'claude.com', 'chatgpt.com'],
        'allowLoopbackRedirects' => true,
        // Browser origins, besides the site itself, allowed to call /mcp.
        'allowedOrigins' => [],

        // The site's tools: ToolInterface class names or instances, served by ToolServer
        // together with the built-in `whoami`.
        'tools' => [],
        // Guidance for the model, sent in initialize/server/discover. Explain the site's
        // conventions here (content format, time zone, what the tools cannot do).
        'instructions' => null,
        // A completely custom MCP server instead of ToolServer: a RequestHandlerInterface class
        // name or instance, or callable(ServerRequestInterface): ResponseInterface. With neither
        // this nor `tools`, /mcp serves only `whoami`.
        'handler' => null,
        // Server name reported to clients. Defaults to "<siteName> MCP".
        'serverName' => null,

        'accessTokenTtl' => 3600,
        'refreshTokenTtl' => 2592000,
        'codeTtl' => 600,
        'consentTtl' => 600,

        'logScope' => 'cake_mcp',
    ];

    private array $config;

    public function __construct(array $overrides = [])
    {
        $this->config = array_merge(self::DEFAULTS, (array)Configure::read('CakeMcp'), $overrides);
    }

    /**
     * @param string $key Setting name.
     * @param mixed $default Returned when the setting is null.
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function baseUrl(): string
    {
        $base = $this->config['baseUrl'] ?: Configure::read('App.fullBaseUrl');
        if (!is_string($base) || $base === '') {
            throw new RuntimeException(
                'CakeMcp needs the site\'s public origin: set CakeMcp.baseUrl (or App.fullBaseUrl), e.g. https://example.org'
            );
        }

        return rtrim($base, '/');
    }

    /**
     * scheme://host[:port] of baseUrl.
     */
    public function origin(): string
    {
        $parts = parse_url($this->baseUrl());
        $origin = strtolower(($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? ''));
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    public function mcpPath(): string
    {
        return '/' . trim((string)$this->config['mcpPath'], '/');
    }

    public function oauthPath(): string
    {
        return '/' . trim((string)$this->config['oauthPath'], '/');
    }

    /**
     * The OAuth resource identifier (RFC 8707) of the MCP endpoint: tokens are bound to it.
     */
    public function resource(): string
    {
        return $this->baseUrl() . $this->mcpPath();
    }

    public function issuer(): string
    {
        return $this->baseUrl() . $this->oauthPath();
    }

    public function endpoint(string $name): string
    {
        return $this->issuer() . '/' . $name;
    }

    public function protectedResourceMetadataUrl(): string
    {
        return $this->baseUrl() . '/.well-known/oauth-protected-resource' . $this->mcpPath();
    }

    public function authorizationServerMetadataUrl(): string
    {
        return $this->baseUrl() . '/.well-known/oauth-authorization-server' . $this->oauthPath();
    }

    public function siteName(): string
    {
        $name = $this->config['siteName'];
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return (string)(parse_url($this->baseUrl(), PHP_URL_HOST) ?: 'this site');
    }

    public function scope(): string
    {
        return (string)$this->config['scope'];
    }
}
