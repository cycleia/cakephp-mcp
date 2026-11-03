<?php
declare(strict_types=1);

namespace CakeMcp\Server;

use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A minimal MCP server with one tool, `whoami`. It is what /mcp serves until a site configures
 * its own handler, so a new install can be connected from Claude, ChatGPT, Claude Code or
 * Codex and the sign-in checked before any real tools exist.
 *
 * It speaks both protocol eras over Streamable HTTP:
 * - modern (2026-07-28): stateless, per-request `_meta`, `server/discover`, header validation;
 * - legacy (2025-03-26 to 2025-11-25): the `initialize` handshake, without sessions.
 *
 * Sites can extend it and override tools() and callTool() to add their own tools.
 */
class DiagnosticServer implements RequestHandlerInterface
{
    public const MODERN_VERSIONS = ['2026-07-28'];
    public const LEGACY_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    private const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    private const META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if ($method !== 'POST') {
            return (new Response())->withStatus(405)->withHeader('Allow', 'POST');
        }

        $message = json_decode((string)$request->getBody(), true);
        if (!is_array($message)) {
            return $this->error(null, -32700, 'Parse error: the body must be a JSON-RPC message.', 400);
        }
        if ($message === [] || array_is_list($message)) {
            return $this->error(null, -32600, 'Invalid request: send one JSON-RPC message per POST.', 400);
        }
        // Notifications and responses: accept and ignore.
        if (!array_key_exists('id', $message) || !isset($message['method'])) {
            return (new Response())->withStatus(202);
        }

        $id = $message['id'];
        $rpcMethod = (string)$message['method'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $context = McpContext::fromRequest($request);

        if (isset($meta[self::META_VERSION])) {
            return $this->modern($request, $id, $rpcMethod, $params, $meta, $context);
        }

        return $this->legacy($id, $rpcMethod, $params, $context);
    }

    /**
     * Tool definitions, in tools/list format.
     *
     * @return array<array<string, mixed>>
     */
    protected function tools(): array
    {
        return [[
            'name' => 'whoami',
            'title' => 'Who am I',
            'description' => 'Shows which account on this site the connection is signed in as, through which app, and with which scopes.',
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
        ]];
    }

    /**
     * Runs a tool. Return a CallToolResult array, or null for an unknown tool.
     *
     * @param string $name Tool name.
     * @param array<string, mixed> $arguments Tool arguments.
     * @param \CakeMcp\Server\McpContext|null $context The caller.
     * @return array<string, mixed>|null
     */
    protected function callTool(string $name, array $arguments, ?McpContext $context): ?array
    {
        if ($name !== 'whoami') {
            return null;
        }
        if ($context === null) {
            return ['content' => [['type' => 'text', 'text' => 'Not signed in.']], 'isError' => true];
        }
        $text = sprintf(
            'Signed in to %s as %s (user id %s) through %s. Scopes: %s.',
            $context->siteName,
            $context->userName,
            $context->userId,
            $context->clientName,
            implode(' ', $context->scopes)
        );

        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'structuredContent' => [
                'site' => $context->siteName,
                'user' => $context->userName,
                'userId' => $context->userId,
                'client' => $context->clientName,
                'scopes' => $context->scopes,
            ],
        ];
    }

    protected function serverInfo(?McpContext $context): array
    {
        return [
            'name' => $context !== null ? $context->siteName . ' MCP' : 'CakeMcp',
            'version' => '0.1.0',
        ];
    }

    protected function instructions(?McpContext $context): string
    {
        return 'Diagnostic MCP server for ' . ($context->siteName ?? 'this site')
            . '. Call whoami to check which administrator account this connection uses.';
    }

    /**
     * @param mixed $id Request id.
     * @param array<string, mixed> $params Request params.
     * @param array<string, mixed> $meta Request _meta.
     */
    private function modern(
        ServerRequestInterface $request,
        $id,
        string $method,
        array $params,
        array $meta,
        ?McpContext $context
    ): ResponseInterface {
        $version = $meta[self::META_VERSION];
        $header = $request->getHeaderLine('MCP-Protocol-Version');
        if ($header === '' || $header !== $version) {
            return $this->error($id, -32020, 'Header mismatch: MCP-Protocol-Version must equal _meta protocolVersion.', 400);
        }
        if (!in_array($version, self::MODERN_VERSIONS, true)) {
            return $this->error($id, -32022, 'Unsupported protocol version', 400, [
                'supported' => array_merge(self::MODERN_VERSIONS, self::LEGACY_VERSIONS),
                'requested' => $version,
            ]);
        }
        if (!isset($meta[self::META_CAPABILITIES]) || !is_array($meta[self::META_CAPABILITIES])) {
            return $this->error($id, -32602, 'Invalid params: _meta clientCapabilities is required.', 400);
        }
        if ($request->getHeaderLine('Mcp-Method') !== $method) {
            return $this->error($id, -32020, 'Header mismatch: Mcp-Method must equal the JSON-RPC method.', 400);
        }
        if (in_array($method, ['tools/call', 'prompts/get'], true)) {
            $name = self::decodeHeader($request->getHeaderLine('Mcp-Name'));
            if ($name !== ($params['name'] ?? null)) {
                return $this->error($id, -32020, 'Header mismatch: Mcp-Name must equal params.name.', 400);
            }
        }

        switch ($method) {
            case 'server/discover':
                return $this->result($id, [
                    'resultType' => 'complete',
                    'supportedVersions' => array_merge(self::MODERN_VERSIONS, self::LEGACY_VERSIONS),
                    'capabilities' => ['tools' => new \stdClass()],
                    '_meta' => ['io.modelcontextprotocol/serverInfo' => $this->serverInfo($context)],
                    'instructions' => $this->instructions($context),
                ]);
            case 'ping':
                return $this->result($id, ['resultType' => 'complete']);
            case 'tools/list':
                return $this->result($id, ['resultType' => 'complete', 'tools' => $this->tools()]);
            case 'tools/call':
                $result = $this->callTool((string)($params['name'] ?? ''), (array)($params['arguments'] ?? []), $context);
                if ($result === null) {
                    return $this->error($id, -32602, 'Unknown tool: ' . ($params['name'] ?? ''), 200);
                }

                return $this->result($id, ['resultType' => 'complete'] + $result);
        }

        return $this->error($id, -32601, 'Method not found: ' . $method, 404);
    }

    /**
     * @param mixed $id Request id.
     * @param array<string, mixed> $params Request params.
     */
    private function legacy($id, string $method, array $params, ?McpContext $context): ResponseInterface
    {
        switch ($method) {
            case 'initialize':
                $requested = $params['protocolVersion'] ?? null;
                $version = in_array($requested, self::LEGACY_VERSIONS, true) ? $requested : self::LEGACY_VERSIONS[0];

                return $this->result($id, [
                    'protocolVersion' => $version,
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => $this->serverInfo($context),
                    'instructions' => $this->instructions($context),
                ]);
            case 'ping':
                return $this->result($id, new \stdClass());
            case 'tools/list':
                return $this->result($id, ['tools' => $this->tools()]);
            case 'tools/call':
                $result = $this->callTool((string)($params['name'] ?? ''), (array)($params['arguments'] ?? []), $context);
                if ($result === null) {
                    return $this->error($id, -32602, 'Unknown tool: ' . ($params['name'] ?? ''), 200);
                }

                return $this->result($id, $result);
        }

        return $this->error($id, -32601, 'Method not found: ' . $method, 200);
    }

    /**
     * @param mixed $id Request id.
     * @param mixed $result Result payload.
     */
    private function result($id, $result): ResponseInterface
    {
        return $this->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], 200);
    }

    /**
     * @param mixed $id Request id.
     * @param array<string, mixed>|null $data Error data.
     */
    private function error($id, int $code, string $message, int $status, ?array $data = null): ResponseInterface
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return $this->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => $error], $status);
    }

    /**
     * @param array<string, mixed> $payload Response body.
     */
    private function json(array $payload, int $status): ResponseInterface
    {
        return (new Response())
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function decodeHeader(string $value): string
    {
        if (preg_match('/^=\?base64\?(.*)\?=$/', $value, $m)) {
            $decoded = base64_decode($m[1], true);

            return $decoded === false ? '' : $decoded;
        }

        return $value;
    }
}
