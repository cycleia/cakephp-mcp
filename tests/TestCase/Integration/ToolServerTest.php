<?php
declare(strict_types=1);

namespace CakeMcp\Test\TestCase\Integration;

use App\Mcp\EchoTool;
use Cake\Core\Configure;

/**
 * Tools registered through CakeMcp.tools, end to end through /mcp.
 */
class ToolServerTest extends OAuthTestCase
{
    private function accessToken(): string
    {
        Configure::write('CakeMcp.tools', [EchoTool::class]);
        Configure::write('CakeMcp.instructions', 'Use echo wisely.');
        $flow = $this->connect(['client_name' => 'Claude', 'redirect_uris' => [self::CLAUDE_REDIRECT]]);

        return $flow['tokens']['access_token'];
    }

    private function callEcho(string $access, array $arguments): array
    {
        $this->mcp($access, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => $arguments]]);

        return $this->json()['result'];
    }

    public function testListsConfiguredToolsAndWhoami(): void
    {
        $access = $this->accessToken();
        $this->mcp($access, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $names = array_column($this->json()['result']['tools'], 'name');
        $this->assertSame(['whoami', 'echo'], $names);

        $this->mcp($access, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']]);
        $result = $this->json()['result'];
        $this->assertSame('Use echo wisely.', $result['instructions']);
        $this->assertSame('Test Site MCP', $result['serverInfo']['name']);
    }

    public function testCallReturnsStructuredContent(): void
    {
        $result = $this->callEcho($this->accessToken(), ['word' => 'hello']);
        $this->assertSame(['word' => 'hello', 'user' => '1', 'login' => 'https://site.test/login'], $result['structuredContent']);
        $this->assertStringStartsWith('Echoed.', $result['content'][0]['text']);
        $this->assertArrayNotHasKey('isError', $result);
    }

    public function testToolErrorsAreShownToTheModel(): void
    {
        $access = $this->accessToken();
        $result = $this->callEcho($access, ['word' => 'bad']);
        $this->assertTrue($result['isError']);
        $this->assertSame('That word is not allowed.', $result['content'][0]['text']);

        $result = $this->callEcho($access, []);
        $this->assertTrue($result['isError']);
        $this->assertSame('`word` is required.', $result['content'][0]['text']);
    }

    public function testCrashesAreNotLeaked(): void
    {
        $result = $this->callEcho($this->accessToken(), ['word' => 'boom']);
        $this->assertTrue($result['isError']);
        $this->assertStringNotContainsString('secret', $result['content'][0]['text']);
    }
}
