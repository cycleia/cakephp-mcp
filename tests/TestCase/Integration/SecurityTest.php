<?php
declare(strict_types=1);

namespace CakeMcp\Test\TestCase\Integration;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;

/**
 * Refusals: who may approve, what a client may present, and what revokes access.
 */
class SecurityTest extends OAuthTestCase
{
    private function chatgpt(): array
    {
        return $this->register(['client_name' => 'ChatGPT', 'redirect_uris' => [self::CHATGPT_REDIRECT]]);
    }

    private function request(array $client, array $overrides = []): array
    {
        [, $challenge] = $this->pkce();

        return $overrides + [
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::CHATGPT_REDIRECT,
            'state' => 's',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => self::RESOURCE,
        ];
    }

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $client = $this->chatgpt();
        $this->headers([]);
        $this->get('/oauth/authorize?' . http_build_query($this->request($client)));
        $this->assertResponseCode(302);
        $location = $this->_response->getHeaderLine('Location');
        $this->assertStringContainsString('/login?redirect=', $location);
        $this->assertStringContainsString(rawurlencode('/oauth/authorize?'), $location);
    }

    public function testNonAdministratorIsRefused(): void
    {
        $client = $this->chatgpt();
        foreach ([2, 3] as $userId) {
            $this->headers([]);
            $this->session(['Auth' => ['User' => ['id' => $userId]]]);
            $this->get('/oauth/authorize?' . http_build_query($this->request($client)));
            $this->assertResponseCode(403);
            $this->assertResponseContains('Administrators only');
            $this->assertResponseNotContains('request_id');
        }
    }

    public function testAdminRolesAreConfigurable(): void
    {
        Configure::write('CakeMcp.adminRoles', ['superadmin', 'staff']);
        $flow = $this->connect(['client_name' => 'ChatGPT', 'redirect_uris' => [self::CHATGPT_REDIRECT]], [], null, 4);
        $this->assertStringContainsString('staff@site.test', $this->whoami($flow['tokens']['access_token']));
    }

    public function testUnknownClientIsShownNotRedirected(): void
    {
        $this->session(['Auth' => ['User' => ['id' => 1]]]);
        $this->get('/oauth/authorize?' . http_build_query(['client_id' => 'nope', 'redirect_uri' => self::CHATGPT_REDIRECT]));
        $this->assertResponseCode(400);
        $this->assertResponseContains('Unknown app');
    }

    public function testUnregisteredRedirectIsShownNotRedirected(): void
    {
        $client = $this->chatgpt();
        $this->session(['Auth' => ['User' => ['id' => 1]]]);
        $this->get('/oauth/authorize?' . http_build_query($this->request($client, ['redirect_uri' => 'https://chatgpt.com/elsewhere'])));
        $this->assertResponseCode(400);
        $this->assertResponseContains('Invalid return address');
    }

    public function testRegistrationRefusesOtherRedirectHosts(): void
    {
        foreach (['https://evil.example/cb', 'http://chatgpt.com/cb', 'myapp://cb', 'https://claude.ai/cb#frag'] as $uri) {
            $this->headers(['Content-Type' => 'application/json']);
            $this->post('/oauth/register', (string)json_encode(['client_name' => 'x', 'redirect_uris' => [$uri]]));
            $this->assertResponseCode(400, $uri);
            $this->assertSame('invalid_redirect_uri', $this->json()['error']);
        }
    }

    public function testPkceIsRequired(): void
    {
        $client = $this->chatgpt();
        $this->session(['Auth' => ['User' => ['id' => 1]]]);
        $this->get('/oauth/authorize?' . http_build_query($this->request($client, ['code_challenge_method' => 'plain'])));
        $this->assertResponseCode(302);
        $query = $this->redirectQuery();
        $this->assertSame('invalid_request', $query['error']);
        $this->assertSame(self::ISSUER, $query['iss']);
        $this->assertSame('s', $query['state']);
    }

    public function testOtherResourceIsRefused(): void
    {
        $client = $this->chatgpt();
        $this->session(['Auth' => ['User' => ['id' => 1]]]);
        $this->get('/oauth/authorize?' . http_build_query($this->request($client, ['resource' => 'https://other.test/mcp'])));
        $this->assertSame('invalid_target', $this->redirectQuery()['error']);
    }

    public function testDecliningReturnsAccessDenied(): void
    {
        $client = $this->chatgpt();
        $query = $this->decide($this->openConsent($this->request($client)), 'deny');
        $this->assertSame('access_denied', $query['error']);
        $this->assertSame(self::ISSUER, $query['iss']);
        $this->assertSame(self::CHATGPT_REDIRECT, $this->redirectBase());
    }

    public function testApprovalIsSingleUseAndBoundToTheSession(): void
    {
        $client = $this->chatgpt();
        $requestId = $this->openConsent($this->request($client));
        $this->decide($requestId);
        $this->assertArrayNotHasKey($requestId, (array)$this->getSession()->read('CakeMcp.pending'));

        // An id that is not in this browser's session is refused.
        $this->session(['CakeMcp' => ['pending' => []]]);
        $this->post('/oauth/authorize', ['request_id' => $requestId, 'decision' => 'approve']);
        $this->assertResponseCode(400);
        $this->assertResponseContains('Approval expired');
    }

    public function testCrossSiteApprovalIsRefused(): void
    {
        $client = $this->chatgpt();
        $requestId = $this->openConsent($this->request($client));
        $this->headers(['Origin' => 'https://evil.example']);
        $this->post('/oauth/authorize', ['request_id' => $requestId, 'decision' => 'approve']);
        $this->assertResponseCode(403);
    }

    public function testWrongVerifierBurnsTheCode(): void
    {
        $client = $this->chatgpt();
        [$verifier, $challenge] = $this->pkce();
        $query = $this->authorize($this->request($client, ['code_challenge' => $challenge]));
        $form = [
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'code' => $query['code'],
            'redirect_uri' => self::CHATGPT_REDIRECT,
        ];
        [$status, $body] = $this->token($form + ['code_verifier' => str_repeat('a', 43)]);
        $this->assertSame(400, $status);
        $this->assertSame('invalid_grant', $body['error']);

        [$status, $body] = $this->token($form + ['code_verifier' => $verifier]);
        $this->assertSame('invalid_grant', $body['error']);
    }

    public function testReusedCodeRevokesTheTokensItIssued(): void
    {
        $client = $this->chatgpt();
        [$verifier, $challenge] = $this->pkce();
        $query = $this->authorize($this->request($client, ['code_challenge' => $challenge]));
        $form = [
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'code' => $query['code'],
            'redirect_uri' => self::CHATGPT_REDIRECT,
            'code_verifier' => $verifier,
        ];
        [$status, $tokens] = $this->token($form);
        $this->assertSame(200, $status);
        $this->assertSame(200, $this->mcp($tokens['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));

        [$status, $body] = $this->token($form);
        $this->assertSame(400, $status);
        $this->assertSame('invalid_grant', $body['error']);
        $this->assertSame(401, $this->mcp($tokens['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
    }

    public function testRefreshTokenReplayRevokesTheConnection(): void
    {
        $flow = $this->connect(['client_name' => 'ChatGPT', 'redirect_uris' => [self::CHATGPT_REDIRECT]]);
        $form = ['grant_type' => 'refresh_token', 'client_id' => $flow['client']['client_id'], 'refresh_token' => $flow['tokens']['refresh_token']];

        [$status, $first] = $this->token($form);
        $this->assertSame(200, $status);
        [$status, $replay] = $this->token($form);
        $this->assertSame(400, $status);
        $this->assertSame('invalid_grant', $replay['error']);

        $this->assertSame(401, $this->mcp($first['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
        [$status, $after] = $this->token(['refresh_token' => $first['refresh_token']] + $form);
        $this->assertSame('invalid_grant', $after['error']);
    }

    public function testDemotionCutsOffAccessImmediately(): void
    {
        $flow = $this->connect(['client_name' => 'ChatGPT', 'redirect_uris' => [self::CHATGPT_REDIRECT]]);
        $users = TableRegistry::getTableLocator()->get('Users');
        $users->updateAll(['role' => 'user'], ['id' => 1]);

        $this->assertSame(401, $this->mcp($flow['tokens']['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
        $this->assertStringContainsString('error="invalid_token"', $this->_response->getHeaderLine('WWW-Authenticate'));

        [$status, $body] = $this->token([
            'grant_type' => 'refresh_token',
            'client_id' => $flow['client']['client_id'],
            'refresh_token' => $flow['tokens']['refresh_token'],
        ]);
        $this->assertSame('invalid_grant', $body['error']);
    }

    public function testPasswordChangeRevokesConnections(): void
    {
        $flow = $this->connect(['client_name' => 'ChatGPT', 'redirect_uris' => [self::CHATGPT_REDIRECT]]);
        TableRegistry::getTableLocator()->get('Users')->updateAll(['password' => '$2y$10$newhash'], ['id' => 1]);

        $this->assertSame(401, $this->mcp($flow['tokens']['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
        $grant = TableRegistry::getTableLocator()->get('CakeMcp.McpGrants')->find()->first();
        $this->assertSame('password changed', $grant->get('revoked_reason'));
    }

    public function testRevocationEndpoint(): void
    {
        $flow = $this->connect(['client_name' => 'ChatGPT', 'redirect_uris' => [self::CHATGPT_REDIRECT]]);
        $this->headers([]);
        $this->post('/oauth/revoke', ['client_id' => $flow['client']['client_id'], 'token' => $flow['tokens']['refresh_token']]);
        $this->assertResponseOk();
        $this->assertSame(401, $this->mcp($flow['tokens']['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
    }

    public function testNewApprovalReplacesTheOldOneForTheSameClient(): void
    {
        $first = $this->connect(['client_name' => 'ChatGPT', 'redirect_uris' => [self::CHATGPT_REDIRECT]]);
        [$verifier, $challenge] = $this->pkce();
        $query = $this->authorize($this->request($first['client'], ['code_challenge' => $challenge]));
        [$status] = $this->token([
            'grant_type' => 'authorization_code',
            'client_id' => $first['client']['client_id'],
            'code' => $query['code'],
            'redirect_uri' => self::CHATGPT_REDIRECT,
            'code_verifier' => $verifier,
        ]);
        $this->assertSame(200, $status);
        $this->assertSame(401, $this->mcp($first['tokens']['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
    }

    public function testForeignOriginIsRefusedAtMcp(): void
    {
        $flow = $this->connect(['client_name' => 'ChatGPT', 'redirect_uris' => [self::CHATGPT_REDIRECT]]);
        $status = $this->mcp($flow['tokens']['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Origin' => 'https://evil.example']);
        $this->assertSame(403, $status);

        $status = $this->mcp($flow['tokens']['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Origin' => 'https://site.test']);
        $this->assertSame(200, $status);
        $this->assertHeader('Access-Control-Allow-Origin', 'https://site.test');
    }

    public function testTokenEndpointErrors(): void
    {
        [$status, $body] = $this->token(['grant_type' => 'authorization_code', 'client_id' => 'unknown']);
        $this->assertSame(401, $status);
        $this->assertSame('invalid_client', $body['error']);

        $client = $this->chatgpt();
        [$status, $body] = $this->token(['grant_type' => 'password', 'client_id' => $client['client_id']]);
        $this->assertSame('unsupported_grant_type', $body['error']);

        $this->headers([]);
        $this->get('/oauth/token');
        $this->assertResponseCode(405);
    }

    public function testConnectionsPageListsAndRevokes(): void
    {
        $flow = $this->connect(['client_name' => 'ChatGPT', 'redirect_uris' => [self::CHATGPT_REDIRECT]]);
        $this->headers([]);
        $this->session(['Auth' => ['User' => ['id' => 1]]]);
        $this->get('/oauth/connections');
        $this->assertResponseOk();
        $this->assertResponseContains('ChatGPT');

        $grant = TableRegistry::getTableLocator()->get('CakeMcp.McpGrants')->find()->first();
        $this->post('/oauth/connections/revoke', ['grant_id' => $grant->get('id')]);
        $this->assertRedirect('/oauth/connections');
        $this->assertSame(401, $this->mcp($flow['tokens']['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
    }

    public function testPurgeRemovesUnusedRegistrations(): void
    {
        $this->chatgpt();
        $clients = TableRegistry::getTableLocator()->get('CakeMcp.McpClients');
        $clients->updateAll(['created' => \Cake\I18n\FrozenTime::now()->subDays(2)], []);
        $this->chatgpt();
        $this->assertSame(1, $clients->find()->count());
    }
}
