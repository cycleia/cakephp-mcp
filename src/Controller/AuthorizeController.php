<?php
declare(strict_types=1);

namespace CakeMcp\Controller;

use Cake\Http\Response;
use CakeMcp\McpConfig;
use CakeMcp\Service\Audit;
use CakeMcp\Service\ClientService;
use CakeMcp\Service\GrantService;
use CakeMcp\Service\RedirectPolicy;
use CakeMcp\Service\ResourceIndicator;
use CakeMcp\Service\Scopes;
use CakeMcp\Service\Secrets;
use CakeMcp\Service\UserGate;

/**
 * GET /oauth/authorize shows the approval page; POST records the decision.
 *
 * Problems with client_id or redirect_uri are shown here and never redirected, so this page
 * cannot be used as an open redirector. Every other error goes back to the registered
 * redirect_uri (RFC 6749 section 4.1.2.1), always with `iss` (RFC 9207).
 */
class AuthorizeController extends AppController
{
    private const SESSION_KEY = 'CakeMcp.pending';
    private const CHALLENGE_PATTERN = '/^[A-Za-z0-9\-_]{43,128}$/';

    public function index(): ?Response
    {
        $config = new McpConfig();
        if ($this->request->is('post')) {
            return $this->decide($config);
        }
        $this->request->allowMethod(['get']);

        $query = $this->request->getQueryParams();
        $clients = new ClientService($config);
        $client = $clients->find(self::param($query, 'client_id') ?? '');
        if ($client === null) {
            return $this->problem(
                'Unknown app',
                'The app that sent you here is not registered with ' . $config->siteName()
                . '. Remove the connection in the app and add it again.'
            );
        }

        $redirectUri = self::param($query, 'redirect_uri');
        if ($redirectUri === null) {
            $uris = $clients->redirectUris($client);
            if (count($uris) === 1 && !RedirectPolicy::isLoopback($uris[0])) {
                $redirectUri = $uris[0];
            }
        }
        if ($redirectUri === null || !$clients->redirectMatches($client, $redirectUri)) {
            Audit::log($config, 'authorize refused', [
                'client' => $client->get('client_id'),
                'reason' => 'redirect_uri not registered',
                'redirect_uri' => (string)$redirectUri,
            ], 'warning');

            return $this->problem('Invalid return address', 'The app asked to come back to an address it did not register.');
        }

        $state = self::param($query, 'state');
        $back = fn (string $error, string $description): Response => $this->back(
            $config,
            $redirectUri,
            ['error' => $error, 'error_description' => $description],
            $state
        );

        if (($query['response_type'] ?? null) !== 'code') {
            return $back('unsupported_response_type', 'Only response_type=code is supported.');
        }
        $challenge = self::param($query, 'code_challenge');
        if (
            $challenge === null
            || ($query['code_challenge_method'] ?? null) !== 'S256'
            || !preg_match(self::CHALLENGE_PATTERN, $challenge)
        ) {
            return $back('invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }
        $resource = self::param($query, 'resource') ?? $config->resource();
        if (!ResourceIndicator::same($resource, $config->resource())) {
            return $back('invalid_target', 'This server only issues tokens for ' . $config->resource() . '.');
        }

        $prompt = Scopes::split(self::param($query, 'prompt'));
        $gate = new UserGate($config);
        $userId = $gate->currentUserId($this->request);
        if ($userId === null) {
            if (in_array('none', $prompt, true)) {
                return $back('login_required', 'Sign-in is required.');
            }

            return $this->loginRedirect($config);
        }
        if (in_array('none', $prompt, true)) {
            return $back('consent_required', 'Approval is required.');
        }

        $user = $gate->load($userId);
        if ($user === null || !$gate->isAllowed($user)) {
            Audit::log($config, 'authorize refused', [
                'client' => $client->get('client_id'),
                'user' => $userId,
                'reason' => 'not an administrator',
            ], 'notice');
            $this->set([
                'siteName' => $config->siteName(),
                'clientName' => (string)$client->get('client_name'),
                'userName' => $user !== null ? $gate->displayName($user) : null,
                'returnUrl' => $this->backUrl($config, $redirectUri, [
                    'error' => 'access_denied',
                    'error_description' => 'Only administrators can connect.',
                ], $state),
            ]);
            $this->response = $this->response->withStatus(403);

            return $this->render('not_allowed');
        }

        $scopes = Scopes::grant($config, self::param($query, 'scope'));
        $requestId = Secrets::token('req', 16);
        $this->storePending($requestId, [
            'client_id' => (string)$client->get('client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $challenge,
            'scopes' => $scopes,
            'resource' => $config->resource(),
            'user_id' => $userId,
            'expires' => time() + (int)$config->get('consentTtl'),
        ]);
        Audit::log($config, 'authorize consent shown', [
            'client' => $client->get('client_id'),
            'name' => $client->get('client_name'),
            'user' => $userId,
            'scope_requested' => self::param($query, 'scope') ?? '-',
            'scope_dropped' => Scopes::dropped($config, self::param($query, 'scope')) ?: '-',
        ]);

        $this->set([
            'siteName' => $config->siteName(),
            'clientName' => (string)$client->get('client_name'),
            'redirectHost' => (string)parse_url($redirectUri, PHP_URL_HOST),
            'isLoopback' => RedirectPolicy::isLoopback($redirectUri),
            'userName' => $gate->displayName($user),
            'requestId' => $requestId,
            'formUrl' => $config->oauthPath() . '/authorize',
            'connectionsUrl' => $config->oauthPath() . '/connections',
        ]);

        return $this->render('consent');
    }

    private function decide(McpConfig $config): Response
    {
        if (!$this->sameOrigin($config)) {
            return $this->problem('Request refused', 'This approval did not come from ' . $config->siteName() . '.', 403);
        }
        $requestId = (string)$this->request->getData('request_id');
        $pending = $this->takePending($requestId);
        if ($pending === null) {
            return $this->problem(
                'Approval expired',
                'This approval request has expired or was already used. Start the connection again from the app.'
            );
        }
        $back = fn (array $params): Response => $this->back($config, $pending['redirect_uri'], $params, $pending['state']);

        $gate = new UserGate($config);
        if ($gate->currentUserId($this->request) !== $pending['user_id']) {
            return $this->problem('Signed in as someone else', 'Start the connection again from the app.');
        }
        $client = (new ClientService($config))->find($pending['client_id']);
        if ($client === null) {
            return $this->problem('Unknown app', 'The app is no longer registered. Start the connection again from the app.');
        }

        if ($this->request->getData('decision') !== 'approve') {
            Audit::log($config, 'authorize denied by user', ['client' => $pending['client_id'], 'user' => $pending['user_id']]);

            return $back(['error' => 'access_denied', 'error_description' => 'The administrator declined the connection.']);
        }

        $user = $gate->load($pending['user_id']);
        if ($user === null || !$gate->isAllowed($user)) {
            return $back(['error' => 'access_denied', 'error_description' => 'Only administrators can connect.']);
        }

        $code = (new GrantService($config, $gate))->approve(
            $client,
            $user,
            $pending['scopes'],
            $pending['resource'],
            $pending['redirect_uri'],
            $pending['code_challenge']
        );

        return $back(['code' => $code]);
    }

    /**
     * @param array<string, mixed> $data Pending authorization request.
     */
    private function storePending(string $requestId, array $data): void
    {
        $session = $this->request->getSession();
        $pending = (array)$session->read(self::SESSION_KEY);
        $pending = array_filter($pending, fn ($p) => is_array($p) && ($p['expires'] ?? 0) > time());
        $pending = array_slice($pending, -4, null, true);
        $pending[$requestId] = $data;
        $session->write(self::SESSION_KEY, $pending);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function takePending(string $requestId): ?array
    {
        $session = $this->request->getSession();
        $pending = (array)$session->read(self::SESSION_KEY);
        $data = $pending[$requestId] ?? null;
        unset($pending[$requestId]);
        $session->write(self::SESSION_KEY, $pending);
        if (!is_array($data) || ($data['expires'] ?? 0) <= time()) {
            return null;
        }

        return $data;
    }

    /**
     * @param array<string, string> $params Response parameters.
     */
    private function back(McpConfig $config, string $redirectUri, array $params, ?string $state): Response
    {
        return $this->redirect($this->backUrl($config, $redirectUri, $params, $state));
    }

    /**
     * @param array<string, string> $params Response parameters.
     */
    private function backUrl(McpConfig $config, string $redirectUri, array $params, ?string $state): string
    {
        if ($state !== null) {
            $params['state'] = $state;
        }
        $params['iss'] = $config->issuer();
        $separator = strpos($redirectUri, '?') === false ? '?' : '&';

        return $redirectUri . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $query Query parameters.
     */
    private static function param(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
