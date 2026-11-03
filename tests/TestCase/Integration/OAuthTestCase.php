<?php
declare(strict_types=1);

namespace CakeMcp\Test\TestCase\Integration;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeMcp\Service\Secrets;

/**
 * Helpers that drive the real HTTP endpoints the way an MCP client does.
 */
abstract class OAuthTestCase extends TestCase
{
    use IntegrationTestTrait;

    protected const CHATGPT_REDIRECT = 'https://chatgpt.com/connector_platform_oauth_redirect';
    protected const CLAUDE_REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    protected const RESOURCE = 'https://site.test/mcp';
    protected const ISSUER = 'https://site.test/oauth';

    protected $fixtures = [
        'plugin.CakeMcp.Users',
        'plugin.CakeMcp.McpClients',
        'plugin.CakeMcp.McpGrants',
        'plugin.CakeMcp.McpTokens',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('CakeMcp', ['siteName' => 'Test Site']);
    }

    protected function tearDown(): void
    {
        Configure::delete('CakeMcp');
        parent::tearDown();
    }

    /**
     * Replaces (rather than merges) the headers for the next request.
     *
     * @param array<string, string> $headers Headers.
     */
    protected function headers(array $headers): void
    {
        $this->_request = ['headers' => $headers];
    }

    protected function json(): array
    {
        $decoded = json_decode((string)$this->_response->getBody(), true);
        $this->assertIsArray($decoded, 'Response is not JSON: ' . (string)$this->_response->getBody());

        return $decoded;
    }

    /**
     * @param array<string, mixed> $meta Client metadata.
     */
    protected function register(array $meta): array
    {
        $this->headers(['Content-Type' => 'application/json']);
        $this->post('/oauth/register', (string)json_encode($meta));
        $this->assertResponseCode(201, (string)$this->_response->getBody());

        return $this->json();
    }

    /**
     * @return array{0: string, 1: string} PKCE verifier and challenge.
     */
    protected function pkce(): array
    {
        $verifier = Secrets::base64url(random_bytes(32));

        return [$verifier, Secrets::pkceChallenge($verifier)];
    }

    /**
     * Opens the approval page as $userId and returns the request id from the form.
     *
     * @param array<string, string> $params Authorization request.
     */
    protected function openConsent(array $params, int $userId = 1): string
    {
        $this->headers([]);
        $this->session(['Auth' => ['User' => ['id' => $userId]]]);
        $this->get('/oauth/authorize?' . http_build_query($params));
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame(1, preg_match('/name="request_id" value="([^"]+)"/', (string)$this->_response->getBody(), $m));

        // The integration harness does not carry session writes into the next request.
        $this->session(['CakeMcp' => ['pending' => $this->getSession()->read('CakeMcp.pending')]]);

        return $m[1];
    }

    /**
     * Posts the approval decision and returns the query of the redirect back to the client.
     */
    protected function decide(string $requestId, string $decision = 'approve'): array
    {
        $this->post('/oauth/authorize', ['request_id' => $requestId, 'decision' => $decision]);
        $this->assertResponseCode(302, (string)$this->_response->getBody());

        return $this->redirectQuery();
    }

    /**
     * @param array<string, string> $params Authorization request.
     */
    protected function authorize(array $params, int $userId = 1): array
    {
        return $this->decide($this->openConsent($params, $userId));
    }

    protected function redirectQuery(): array
    {
        $location = $this->_response->getHeaderLine('Location');
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    protected function redirectBase(): string
    {
        $location = $this->_response->getHeaderLine('Location');

        return strtok($location, '?');
    }

    /**
     * @param array<string, string> $form Token request.
     * @param array<string, string> $headers Extra headers.
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function token(array $form, array $headers = []): array
    {
        $this->headers($headers);
        $this->post('/oauth/token', $form);

        return [$this->_response->getStatusCode(), $this->json()];
    }

    /**
     * Sends one JSON-RPC message to /mcp.
     *
     * @param array<string, mixed> $message JSON-RPC message.
     * @param array<string, string> $headers Extra headers.
     */
    protected function mcp(?string $accessToken, array $message, array $headers = []): int
    {
        $headers += ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];
        if ($accessToken !== null) {
            $headers['Authorization'] = 'Bearer ' . $accessToken;
        }
        $this->headers($headers);
        $this->post('/mcp', (string)json_encode($message));

        return $this->_response->getStatusCode();
    }

    /**
     * The whole flow for a public client: register, approve, exchange.
     *
     * @param array<string, mixed> $meta Client metadata.
     * @param array<string, string> $authorize Extra authorization parameters.
     * @return array{client: array, tokens: array, redirect: string, verifier: string}
     */
    protected function connect(array $meta, array $authorize = [], ?string $redirect = null, int $userId = 1): array
    {
        $client = $this->register($meta);
        $redirect = $redirect ?? $meta['redirect_uris'][0];
        [$verifier, $challenge] = $this->pkce();
        $query = $this->authorize($authorize + [
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => $redirect,
            'state' => 'state-123',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => self::RESOURCE,
        ], $userId);
        $this->assertArrayHasKey('code', $query, 'No code in redirect: ' . json_encode($query));
        $this->assertSame('state-123', $query['state']);
        $this->assertSame(self::ISSUER, $query['iss']);

        [$status, $tokens] = $this->token([
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'code' => $query['code'],
            'redirect_uri' => $redirect,
            'code_verifier' => $verifier,
            'resource' => self::RESOURCE,
        ]);
        $this->assertSame(200, $status, json_encode($tokens));

        return ['client' => $client, 'tokens' => $tokens, 'redirect' => $redirect, 'verifier' => $verifier];
    }

    protected function whoami(string $accessToken): string
    {
        $status = $this->mcp($accessToken, [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => ['name' => 'whoami', 'arguments' => []],
        ]);
        $this->assertSame(200, $status, (string)$this->_response->getBody());

        return (string)($this->json()['result']['content'][0]['text'] ?? '');
    }
}
