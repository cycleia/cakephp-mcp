<?php
declare(strict_types=1);

namespace CakeMcp\Test\TestCase\Integration;

/**
 * The connection flow as each supported client performs it, from discovery to a tool call
 * and a token refresh. Request shapes follow what the clients were seen to send (see
 * README "Client behaviour").
 */
class ClientFlowsTest extends OAuthTestCase
{
    public function testDiscoveryDocuments(): void
    {
        foreach (['/.well-known/oauth-protected-resource/mcp', '/.well-known/oauth-protected-resource'] as $path) {
            $this->get($path);
            $this->assertResponseOk();
            $this->assertHeader('Access-Control-Allow-Origin', '*');
            $prm = $this->json();
            $this->assertSame(self::RESOURCE, $prm['resource']);
            $this->assertSame([self::ISSUER], $prm['authorization_servers']);
            $this->assertSame(['mcp'], $prm['scopes_supported']);
            $this->assertSame('Test Site', $prm['resource_name']);
        }

        $this->get('/.well-known/oauth-authorization-server/oauth');
        $this->assertResponseOk();
        $as = $this->json();
        $this->assertSame(self::ISSUER, $as['issuer']);
        $this->assertSame('https://site.test/oauth/authorize', $as['authorization_endpoint']);
        $this->assertSame('https://site.test/oauth/token', $as['token_endpoint']);
        $this->assertSame('https://site.test/oauth/register', $as['registration_endpoint']);
        $this->assertSame('https://site.test/oauth/revoke', $as['revocation_endpoint']);
        $this->assertSame(['S256'], $as['code_challenge_methods_supported']);
        $this->assertSame(['mcp', 'offline_access'], $as['scopes_supported']);
        $this->assertTrue($as['authorization_response_iss_parameter_supported']);
        $this->assertContains('none', $as['token_endpoint_auth_methods_supported']);
        // Client ID Metadata Documents are not offered yet, so clients use registration.
        $this->assertArrayNotHasKey('client_id_metadata_document_supported', $as);
    }

    public function testUnauthenticatedMcpRequestPointsToMetadata(): void
    {
        $status = $this->mcp(null, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);
        $this->assertSame(401, $status);
        $this->assertSame(
            'Bearer resource_metadata="https://site.test/.well-known/oauth-protected-resource/mcp", scope="mcp"',
            $this->_response->getHeaderLine('WWW-Authenticate')
        );
    }

    /**
     * ChatGPT registers without a scope, then asks for `openid email offline_access <mcp>`.
     * Servers that treat a registration's scope as a whitelist refuse this with invalid_scope;
     * here the OpenID scopes are dropped and the connection succeeds.
     */
    public function testChatGpt(): void
    {
        $flow = $this->connect(
            ['client_name' => 'ChatGPT', 'redirect_uris' => [self::CHATGPT_REDIRECT]],
            ['scope' => 'openid email offline_access mcp']
        );
        $tokens = $flow['tokens'];
        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertSame(3600, $tokens['expires_in']);
        $this->assertSame('mcp offline_access', $tokens['scope']);
        $this->assertNotEmpty($tokens['refresh_token']);

        $this->assertStringContainsString('admin@site.test', $this->whoami($tokens['access_token']));
        $this->assertStringContainsString('through ChatGPT', $this->whoami($tokens['access_token']));

        // A refresh that repeats the original scope list must not fail either.
        [$status, $refreshed] = $this->token([
            'grant_type' => 'refresh_token',
            'client_id' => $flow['client']['client_id'],
            'refresh_token' => $tokens['refresh_token'],
            'scope' => 'openid email offline_access mcp',
            'resource' => self::RESOURCE,
        ]);
        $this->assertSame(200, $status, json_encode($refreshed));
        $this->assertNotSame($tokens['refresh_token'], $refreshed['refresh_token']);
        $this->assertStringContainsString('admin@site.test', $this->whoami($refreshed['access_token']));
    }

    /**
     * Claude (claude.ai, desktop, mobile) registers with its callback and asks for the MCP
     * scope and offline_access. Uses the legacy initialize handshake and then a tool call.
     */
    public function testClaude(): void
    {
        $flow = $this->connect(
            [
                'client_name' => 'Claude',
                'redirect_uris' => [self::CLAUDE_REDIRECT],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'token_endpoint_auth_method' => 'none',
                'scope' => 'mcp offline_access',
            ],
            ['scope' => 'mcp offline_access']
        );
        $access = $flow['tokens']['access_token'];

        $status = $this->mcp($access, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'claude-ai', 'version' => '1']],
        ]);
        $this->assertSame(200, $status);
        $this->assertSame('2025-06-18', $this->json()['result']['protocolVersion']);

        $this->assertSame(202, $this->mcp($access, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));

        $this->mcp($access, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $this->assertSame('whoami', $this->json()['result']['tools'][0]['name']);
        $this->assertStringContainsString('through Claude', $this->whoami($access));
    }

    /**
     * Claude Code registers a loopback redirect on one port and may come back on another;
     * it sends no scope. Uses the modern (2026-07-28) protocol.
     */
    public function testClaudeCodeLoopbackOnAnotherPort(): void
    {
        $flow = $this->connect(
            [
                'client_name' => 'Claude Code (example)',
                'redirect_uris' => ['http://localhost:54321/callback'],
                'application_type' => 'native',
                'token_endpoint_auth_method' => 'none',
            ],
            [],
            'http://localhost:61999/callback'
        );
        $this->assertSame('mcp', $flow['tokens']['scope']);

        $status = $this->mcp($flow['tokens']['access_token'], [
            'jsonrpc' => '2.0',
            'id' => 'd1',
            'method' => 'server/discover',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ]],
        ], ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'server/discover']);
        $this->assertSame(200, $status, (string)$this->_response->getBody());
        $result = $this->json()['result'];
        $this->assertSame('complete', $result['resultType']);
        $this->assertContains('2026-07-28', $result['supportedVersions']);
    }

    /**
     * Codex registers an explicit scope list including OpenID scopes, on 127.0.0.1.
     */
    public function testCodex(): void
    {
        $flow = $this->connect(
            [
                'client_name' => 'Codex',
                'redirect_uris' => ['http://127.0.0.1:1455/callback'],
                'scope' => 'openid offline_access mcp email profile',
                'token_endpoint_auth_method' => 'none',
                'application_type' => 'native',
            ],
            ['scope' => 'openid offline_access mcp email profile']
        );
        $this->assertSame('mcp offline_access', $flow['tokens']['scope']);
        $this->assertStringContainsString('through Codex', $this->whoami($flow['tokens']['access_token']));
    }

    public function testConfidentialClientMustAuthenticate(): void
    {
        $client = $this->register([
            'client_name' => 'Secret client',
            'redirect_uris' => [self::CLAUDE_REDIRECT],
            'token_endpoint_auth_method' => 'client_secret_basic',
        ]);
        $this->assertArrayHasKey('client_secret', $client);
        [$verifier, $challenge] = $this->pkce();
        $query = $this->authorize([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::CLAUDE_REDIRECT,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'redirect_uri' => self::CLAUDE_REDIRECT,
            'code_verifier' => $verifier,
        ];

        [$status, $body] = $this->token($form + ['client_id' => $client['client_id']]);
        $this->assertSame(401, $status);
        $this->assertSame('invalid_client', $body['error']);

        $basic = 'Basic ' . base64_encode(rawurlencode($client['client_id']) . ':' . rawurlencode($client['client_secret']));
        [$status, $body] = $this->token($form, ['Authorization' => $basic]);
        $this->assertSame(200, $status, json_encode($body));
    }
}
