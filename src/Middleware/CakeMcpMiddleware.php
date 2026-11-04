<?php
declare(strict_types=1);

namespace CakeMcp\Middleware;

use Cake\Core\PluginApplicationInterface;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\Routing\RoutingApplicationInterface;
use CakeMcp\Http\OAuthException;
use CakeMcp\McpConfig;
use CakeMcp\Server\DiagnosticServer;
use CakeMcp\Server\McpContext;
use CakeMcp\Server\ToolServer;
use CakeMcp\Service\Audit;
use CakeMcp\Service\ClientService;
use CakeMcp\Service\GrantService;
use CakeMcp\Service\UserGate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

/**
 * Serves the machine-facing endpoints and guards /mcp. The plugin inserts it before the app's
 * RoutingMiddleware, so these requests never reach the site's CSRF, session, authentication
 * or permission layers, and no site needs exceptions for them:
 *
 *   GET  /.well-known/oauth-protected-resource/mcp      RFC 9728 metadata
 *   GET  /.well-known/oauth-authorization-server/oauth  RFC 8414 metadata
 *   POST /oauth/register                                RFC 7591 registration
 *   POST /oauth/token                                   code exchange and refresh
 *   POST /oauth/revoke                                  RFC 7009 revocation
 *   *    /mcp                                           bearer check, then the site's handler
 *
 * The approval page (/oauth/authorize) and /oauth/connections are ordinary controller routes,
 * because they need the site's login session and layout.
 */
class CakeMcpMiddleware implements MiddlewareInterface
{
    private const CORS_HEADERS = 'Authorization, Content-Type, MCP-Protocol-Version, Mcp-Method, Mcp-Name';

    private ?RoutingApplicationInterface $app;

    /**
     * @param \Cake\Routing\RoutingApplicationInterface|null $app The application, to load its routes
     *   before the site's tools run (this middleware runs before RoutingMiddleware).
     */
    public function __construct(?RoutingApplicationInterface $app = null)
    {
        $this->app = $app;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $config = new McpConfig();
        $path = $this->relativePath($request);
        $mcp = $config->mcpPath();
        $oauth = $config->oauthPath();

        if ($path === '/.well-known/oauth-protected-resource' . $mcp || $path === '/.well-known/oauth-protected-resource') {
            return $this->publicEndpoint($request, $config, ['GET'], fn () => $this->protectedResourceMetadata($config));
        }
        if ($path === '/.well-known/oauth-authorization-server' . $oauth) {
            return $this->publicEndpoint($request, $config, ['GET'], fn () => $this->authorizationServerMetadata($config));
        }
        if ($path === $oauth . '/register') {
            return $this->publicEndpoint($request, $config, ['POST'], fn () => $this->register($request, $config));
        }
        if ($path === $oauth . '/token') {
            return $this->publicEndpoint($request, $config, ['POST'], fn () => $this->token($request, $config));
        }
        if ($path === $oauth . '/revoke') {
            return $this->publicEndpoint($request, $config, ['POST'], fn () => $this->revoke($request, $config));
        }
        if ($path === $mcp || $path === $mcp . '/') {
            return $this->mcp($request, $config);
        }

        return $handler->handle($request);
    }

    /**
     * The request path without the app's base directory.
     */
    private function relativePath(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        $base = (string)$request->getAttribute('base');
        if ($base !== '' && strpos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }

        return '/' . ltrim($path, '/');
    }

    /**
     * Runs an endpoint that is called server-to-server or from any browser origin: open CORS,
     * no credentials, OAuth-style JSON errors.
     *
     * @param array<string> $methods Allowed methods.
     * @param callable(): \Psr\Http\Message\ResponseInterface $action The endpoint.
     */
    private function publicEndpoint(ServerRequestInterface $request, McpConfig $config, array $methods, callable $action): ResponseInterface
    {
        $allow = implode(', ', array_merge($methods, ['OPTIONS']));
        if ($request->getMethod() === 'OPTIONS') {
            $response = (new Response())->withStatus(204);
        } elseif (!in_array($request->getMethod(), $methods, true)) {
            $response = $this->oauthError(new OAuthException('invalid_request', 'Method not allowed.', 405, ['Allow' => $allow]));
        } else {
            try {
                $response = $action();
            } catch (OAuthException $e) {
                $response = $this->oauthError($e);
            } catch (Throwable $e) {
                Audit::log($config, 'server error', ['path' => $request->getUri()->getPath(), 'error' => get_class($e) . ': ' . $e->getMessage()], 'error');
                $response = $this->oauthError(new OAuthException('server_error', 'Internal error.', 500));
            }
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', $allow)
            ->withHeader('Access-Control-Allow-Headers', self::CORS_HEADERS)
            ->withHeader('Access-Control-Max-Age', '86400');
    }

    private function protectedResourceMetadata(McpConfig $config): ResponseInterface
    {
        return $this->json([
            'resource' => $config->resource(),
            'authorization_servers' => [$config->issuer()],
            'scopes_supported' => [$config->scope()],
            'bearer_methods_supported' => ['header'],
            'resource_name' => $config->siteName(),
        ])->withHeader('Cache-Control', 'public, max-age=3600');
    }

    private function authorizationServerMetadata(McpConfig $config): ResponseInterface
    {
        $authMethods = ['none', 'client_secret_basic', 'client_secret_post'];

        return $this->json([
            'issuer' => $config->issuer(),
            'authorization_endpoint' => $config->endpoint('authorize'),
            'token_endpoint' => $config->endpoint('token'),
            'registration_endpoint' => $config->endpoint('register'),
            'revocation_endpoint' => $config->endpoint('revoke'),
            'scopes_supported' => [$config->scope(), 'offline_access'],
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => $authMethods,
            'revocation_endpoint_auth_methods_supported' => $authMethods,
            'code_challenge_methods_supported' => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
        ])->withHeader('Cache-Control', 'public, max-age=3600');
    }

    private function register(ServerRequestInterface $request, McpConfig $config): ResponseInterface
    {
        $raw = (string)$request->getBody();
        if (strlen($raw) > 16384) {
            throw new OAuthException('invalid_client_metadata', 'Registration request is too large.');
        }
        $meta = json_decode($raw, true);
        if (!is_array($meta) || array_is_list($meta)) {
            throw new OAuthException('invalid_client_metadata', 'The body must be a JSON object.');
        }
        $grants = new GrantService($config);
        $grants->purge();

        return $this->json((new ClientService($config))->register($meta), 201)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function token(ServerRequestInterface $request, McpConfig $config): ResponseInterface
    {
        $body = $this->formBody($request);
        $clients = new ClientService($config);
        $client = $clients->authenticate($request, $body);
        $grants = new GrantService($config);

        $grantType = $body['grant_type'] ?? null;
        try {
            if ($grantType === 'authorization_code') {
                $tokens = $grants->exchangeCode($client, $body);
            } elseif ($grantType === 'refresh_token') {
                $tokens = $grants->refresh($client, $body);
            } else {
                throw new OAuthException('unsupported_grant_type', 'Use authorization_code or refresh_token.');
            }
        } catch (OAuthException $e) {
            Audit::log($config, 'token refused', [
                'grant_type' => is_string($grantType) ? $grantType : '-',
                'client' => $client->get('client_id'),
                'error' => $e->error,
                'description' => $e->getMessage(),
            ], 'warning');
            throw $e;
        }

        return $this->json($tokens)->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    private function revoke(ServerRequestInterface $request, McpConfig $config): ResponseInterface
    {
        $body = $this->formBody($request);
        $client = (new ClientService($config))->authenticate($request, $body);
        $token = $body['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new OAuthException('invalid_request', 'token is required.');
        }
        (new GrantService($config))->revokeToken($client, $token);

        return (new Response())->withStatus(200)->withHeader('Cache-Control', 'no-store');
    }

    private function mcp(ServerRequestInterface $request, McpConfig $config): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $allowedOrigin = null;
        if ($origin !== '') {
            $allowed = array_map('strtolower', array_merge([$config->origin()], (array)$config->get('allowedOrigins', [])));
            if (!in_array(strtolower(rtrim($origin, '/')), $allowed, true)) {
                Audit::log($config, 'mcp refused', ['reason' => 'origin not allowed', 'origin' => $origin], 'warning');

                return $this->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Origin not allowed.']], 403);
            }
            $allowedOrigin = $origin;
        }
        if ($request->getMethod() === 'OPTIONS') {
            return $this->mcpCors((new Response())->withStatus(204), $allowedOrigin);
        }

        $header = $request->getHeaderLine('Authorization');
        $challenge = sprintf(
            'Bearer resource_metadata="%s", scope="%s"',
            $config->protectedResourceMetadataUrl(),
            $config->scope()
        );
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $this->mcpCors(
                $this->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Authentication required.']], 401)
                    ->withHeader('WWW-Authenticate', $challenge),
                $allowedOrigin
            );
        }

        $reason = null;
        $context = (new GrantService($config, new UserGate($config)))->authenticateAccess($m[1], $reason);
        if ($context === null) {
            Audit::log($config, 'mcp refused', ['reason' => $reason], 'notice');

            return $this->mcpCors(
                $this->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Invalid or expired access token.']], 401)
                    ->withHeader(
                        'WWW-Authenticate',
                        $challenge . ', error="invalid_token", error_description="' . addcslashes((string)$reason, '"\\') . '"'
                    ),
                $allowedOrigin
            );
        }

        $request = $request->withAttribute(McpContext::ATTRIBUTE, $context);
        $this->prepareRouter($request);
        $response = $this->siteHandler($config)->handle($request);

        return $this->mcpCors($response, $allowedOrigin);
    }

    /**
     * Tools often call site code that builds URLs (Router::url(), email templates). /mcp is
     * answered before RoutingMiddleware, so load the app's routes and set the request the way
     * RoutingMiddleware (and the CLI, for commands) would.
     */
    private function prepareRouter(ServerRequestInterface $request): void
    {
        if ($this->app !== null && count(Router::getRouteCollection()->routes()) === 0) {
            $builder = Router::createRouteBuilder('/');
            $this->app->routes($builder);
            if ($this->app instanceof PluginApplicationInterface) {
                $this->app->pluginRoutes($builder);
            }
        }
        if ($request instanceof ServerRequest) {
            Router::setRequest($request);
        }
    }

    private function mcpCors(ResponseInterface $response, ?string $origin): ResponseInterface
    {
        if ($origin === null) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Vary', 'Origin')
            ->withHeader('Access-Control-Allow-Methods', 'POST, GET, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', self::CORS_HEADERS . ', Mcp-Session-Id, Last-Event-ID')
            ->withHeader('Access-Control-Expose-Headers', 'WWW-Authenticate, Mcp-Session-Id');
    }

    private function siteHandler(McpConfig $config): RequestHandlerInterface
    {
        $handler = $config->get('handler');
        if ($handler === null) {
            return (array)$config->get('tools', []) === [] ? new DiagnosticServer() : new ToolServer(null, $config);
        }
        if ($handler instanceof RequestHandlerInterface) {
            return $handler;
        }
        if (is_string($handler) && class_exists($handler)) {
            $instance = new $handler();
            if ($instance instanceof RequestHandlerInterface) {
                return $instance;
            }
        }
        if (is_callable($handler)) {
            return new class ($handler) implements RequestHandlerInterface {
                /**
                 * @var callable
                 */
                private $callable;

                public function __construct(callable $callable)
                {
                    $this->callable = $callable;
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return ($this->callable)($request);
                }
            };
        }

        throw new RuntimeException('CakeMcp.handler must be a RequestHandlerInterface class name or instance, or a callable.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body) && $body !== []) {
            return $body;
        }
        $raw = (string)$request->getBody();
        if (stripos($request->getHeaderLine('Content-Type'), 'application/json') !== false) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }
        parse_str($raw, $parsed);

        return $parsed;
    }

    private function oauthError(OAuthException $e): ResponseInterface
    {
        $body = ['error' => $e->error];
        if ($e->getMessage() !== '') {
            $body['error_description'] = $e->getMessage();
        }
        $response = $this->json($body, $e->status)->withHeader('Cache-Control', 'no-store');
        foreach ($e->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $payload Response body.
     */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        return (new Response())
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
