<?php
declare(strict_types=1);

namespace CakeMcp\Test\TestCase\Server;

use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use CakeMcp\Server\DiagnosticServer;

/**
 * Protocol handling of the built-in server, for both eras.
 */
class DiagnosticServerTest extends TestCase
{
    private const MODERN_META = [
        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities' => [],
    ];

    /**
     * @param array<string, mixed>|null $message JSON-RPC message.
     * @param array<string, string> $headers Headers.
     * @return array{0: int, 1: array|null}
     */
    private function send(?array $message, array $headers = [], string $method = 'POST'): array
    {
        $request = new ServerRequest([
            'environment' => ['REQUEST_METHOD' => $method],
            'input' => $message === null ? '' : (string)json_encode($message),
        ]);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $response = (new DiagnosticServer())->handle($request);

        return [$response->getStatusCode(), json_decode((string)$response->getBody(), true)];
    }

    public function testLegacyInitializeNegotiatesVersion(): void
    {
        [$status, $body] = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']]);
        $this->assertSame(200, $status);
        $this->assertSame('2025-03-26', $body['result']['protocolVersion']);

        [, $body] = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05']]);
        $this->assertSame('2025-11-25', $body['result']['protocolVersion']);
    }

    public function testNotificationsAreAccepted(): void
    {
        [$status] = $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $this->assertSame(202, $status);
    }

    public function testGetIsNotAllowed(): void
    {
        [$status] = $this->send(null, [], 'GET');
        $this->assertSame(405, $status);
    }

    public function testBatchesAreRejected(): void
    {
        [$status, $body] = $this->send([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']]);
        $this->assertSame(400, $status);
        $this->assertSame(-32600, $body['error']['code']);
    }

    public function testModernRequestNeedsMatchingHeaders(): void
    {
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => self::MODERN_META]];

        [$status, $body] = $this->send($message, ['Mcp-Method' => 'tools/list']);
        $this->assertSame(400, $status);
        $this->assertSame(-32020, $body['error']['code']);

        [$status, $body] = $this->send($message, ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call']);
        $this->assertSame(-32020, $body['error']['code']);

        [$status, $body] = $this->send($message, ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list']);
        $this->assertSame(200, $status);
        $this->assertSame('complete', $body['result']['resultType']);
        $this->assertSame('whoami', $body['result']['tools'][0]['name']);
    }

    public function testUnsupportedModernVersionListsSupportedOnes(): void
    {
        $meta = ['io.modelcontextprotocol/protocolVersion' => '2099-01-01'] + self::MODERN_META;
        [$status, $body] = $this->send(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => $meta]],
            ['MCP-Protocol-Version' => '2099-01-01', 'Mcp-Method' => 'tools/list']
        );
        $this->assertSame(400, $status);
        $this->assertSame(-32022, $body['error']['code']);
        $this->assertSame('2099-01-01', $body['error']['data']['requested']);
        $this->assertContains('2026-07-28', $body['error']['data']['supported']);
        $this->assertContains('2025-11-25', $body['error']['data']['supported']);
    }

    public function testModernRequestNeedsClientCapabilities(): void
    {
        $meta = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28'];
        [$status, $body] = $this->send(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => $meta]],
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list']
        );
        $this->assertSame(400, $status);
        $this->assertSame(-32602, $body['error']['code']);
    }

    public function testModernUnknownMethodIs404(): void
    {
        [$status, $body] = $this->send(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'nope/nothing', 'params' => ['_meta' => self::MODERN_META]],
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'nope/nothing']
        );
        $this->assertSame(404, $status);
        $this->assertSame(-32601, $body['error']['code']);
    }

    public function testModernToolCallChecksBase64Name(): void
    {
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'whoami', '_meta' => self::MODERN_META]];
        $headers = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call'];

        [$status, $body] = $this->send($message, $headers + ['Mcp-Name' => '=?base64?' . base64_encode('whoami') . '?=']);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('complete', $body['result']['resultType']);

        [$status, $body] = $this->send($message, $headers + ['Mcp-Name' => 'other']);
        $this->assertSame(400, $status);
        $this->assertSame(-32020, $body['error']['code']);
    }
}
