<?php
declare(strict_types=1);

namespace CakeMcp\Service;

use Cake\Datasource\EntityInterface;
use Cake\I18n\FrozenTime;
use Cake\ORM\TableRegistry;
use CakeMcp\Http\OAuthException;
use CakeMcp\McpConfig;
use CakeMcp\Model\Table\McpClientsTable;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dynamic Client Registration (RFC 7591) and client authentication at the token and
 * revocation endpoints.
 *
 * Public clients (`token_endpoint_auth_method: none`, protected by PKCE) are the default,
 * including when a client omits the method. A client that asks for client_secret_basic or
 * client_secret_post gets a secret, returned once.
 */
final class ClientService
{
    private const AUTH_METHODS = ['none', 'client_secret_basic', 'client_secret_post'];
    private const GRANT_TYPES = ['authorization_code', 'refresh_token'];

    private McpConfig $config;

    private RedirectPolicy $redirects;

    private McpClientsTable $clients;

    public function __construct(McpConfig $config)
    {
        $this->config = $config;
        $this->redirects = new RedirectPolicy($config);
        /** @var \CakeMcp\Model\Table\McpClientsTable $clients */
        $clients = TableRegistry::getTableLocator()->get('CakeMcp.McpClients');
        $this->clients = $clients;
    }

    /**
     * @param array<string, mixed> $meta Client metadata from the registration request.
     * @return array<string, mixed> The registration response.
     */
    public function register(array $meta): array
    {
        $uris = $meta['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === []) {
            throw new OAuthException('invalid_redirect_uri', 'redirect_uris must be a non-empty array.');
        }
        $uris = array_values(array_unique($uris, SORT_REGULAR));
        if (count($uris) > 10) {
            throw new OAuthException('invalid_redirect_uri', 'At most 10 redirect URIs may be registered.');
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || strlen($uri) > 2000 || !$this->redirects->isAllowed($uri)) {
                throw new OAuthException(
                    'invalid_redirect_uri',
                    'Redirect URI not allowed: ' . (is_string($uri) ? substr($uri, 0, 200) : gettype($uri))
                    . '. Use https on an allowed host, or http on localhost.'
                );
            }
        }

        $authMethod = $meta['token_endpoint_auth_method'] ?? 'none';
        if (!is_string($authMethod) || !in_array($authMethod, self::AUTH_METHODS, true)) {
            throw new OAuthException('invalid_client_metadata', 'Unsupported token_endpoint_auth_method.');
        }

        $grantTypes = $meta['grant_types'] ?? self::GRANT_TYPES;
        if (!is_array($grantTypes) || !in_array('authorization_code', $grantTypes, true)) {
            throw new OAuthException('invalid_client_metadata', 'grant_types must include authorization_code.');
        }
        $grantTypes = array_values(array_intersect(self::GRANT_TYPES, $grantTypes));
        if (!in_array('refresh_token', $grantTypes, true)) {
            $grantTypes[] = 'refresh_token';
        }

        $responseTypes = $meta['response_types'] ?? ['code'];
        if (!is_array($responseTypes) || !in_array('code', $responseTypes, true)) {
            throw new OAuthException('invalid_client_metadata', 'response_types must include code.');
        }

        $applicationType = $meta['application_type'] ?? null;
        if ($applicationType !== null && !in_array($applicationType, ['web', 'native'], true)) {
            throw new OAuthException('invalid_client_metadata', 'application_type must be web or native.');
        }

        $name = $meta['client_name'] ?? null;
        $name = is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 200) : 'MCP client';
        $scope = isset($meta['scope']) && is_string($meta['scope']) ? mb_substr($meta['scope'], 0, 255) : null;

        $clientId = Secrets::token('mcpc', 18);
        $secret = $authMethod === 'none' ? null : Secrets::token('mcps', 32);
        $now = FrozenTime::now();

        $stored = array_intersect_key($meta, array_flip(['client_uri', 'logo_uri', 'software_id', 'software_version', 'contacts']));
        $entity = $this->clients->newEntity([
            'client_id' => $clientId,
            'secret_hash' => $secret === null ? null : Secrets::hash($secret),
            'client_name' => $name,
            'redirect_uris' => json_encode($uris, JSON_UNESCAPED_SLASHES),
            'grant_types' => implode(' ', $grantTypes),
            'auth_method' => $authMethod,
            'application_type' => $applicationType,
            'scope' => $scope,
            'metadata' => $stored === [] ? null : json_encode($stored, JSON_UNESCAPED_SLASHES),
            'created' => $now,
        ], ['accessibleFields' => ['*' => true]]);
        $this->clients->saveOrFail($entity);

        Audit::log($this->config, 'register', [
            'client' => $clientId,
            'name' => $name,
            'redirect_uris' => $uris,
            'auth_method' => $authMethod,
            'application_type' => $applicationType ?? '-',
            'scope' => $scope ?? '-',
        ]);

        $response = [
            'client_id' => $clientId,
            'client_id_issued_at' => $now->getTimestamp(),
            'client_name' => $name,
            'redirect_uris' => $uris,
            'grant_types' => $grantTypes,
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $authMethod,
        ];
        if ($applicationType !== null) {
            $response['application_type'] = $applicationType;
        }
        if ($scope !== null) {
            $response['scope'] = $scope;
        }
        if ($secret !== null) {
            $response['client_secret'] = $secret;
            $response['client_secret_expires_at'] = 0;
        }

        return $response;
    }

    public function find(string $clientId): ?EntityInterface
    {
        if ($clientId === '' || strlen($clientId) > 64) {
            return null;
        }
        $client = $this->clients->find()->where(['client_id' => $clientId])->first();

        return $client instanceof EntityInterface ? $client : null;
    }

    /**
     * @return array<string>
     */
    public function redirectUris(EntityInterface $client): array
    {
        $uris = json_decode((string)$client->get('redirect_uris'), true);

        return is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [];
    }

    public function redirectMatches(EntityInterface $client, string $redirectUri): bool
    {
        return $this->redirects->matches($redirectUri, $this->redirectUris($client));
    }

    /**
     * Authenticates the client at the token or revocation endpoint.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param array<string, mixed> $body Parsed form body.
     */
    public function authenticate(ServerRequestInterface $request, array $body): EntityInterface
    {
        $clientId = null;
        $secret = null;
        $usedBasic = false;
        $header = $request->getHeaderLine('Authorization');
        if (stripos($header, 'Basic ') === 0) {
            $decoded = base64_decode(substr($header, 6), true);
            if ($decoded === false || strpos($decoded, ':') === false) {
                throw new OAuthException('invalid_client', 'Malformed Basic credentials.', 401, ['WWW-Authenticate' => 'Basic']);
            }
            [$clientId, $secret] = array_map('urldecode', explode(':', $decoded, 2));
            $usedBasic = true;
        }
        if ($clientId === null) {
            $clientId = isset($body['client_id']) && is_string($body['client_id']) ? $body['client_id'] : null;
            $secret = isset($body['client_secret']) && is_string($body['client_secret']) ? $body['client_secret'] : null;
        } elseif (isset($body['client_id']) && $body['client_id'] !== $clientId) {
            throw new OAuthException('invalid_client', 'client_id does not match the Basic credentials.', 401);
        }
        if ($clientId === null || $clientId === '') {
            throw new OAuthException('invalid_client', 'client_id is required.', 401);
        }

        $client = $this->find($clientId);
        if ($client === null) {
            throw new OAuthException('invalid_client', 'Unknown client. Register again.', 401);
        }
        $secretHash = $client->get('secret_hash');
        if (is_string($secretHash) && $secretHash !== '') {
            if ($secret === null || $secret === '' || !hash_equals($secretHash, Secrets::hash($secret))) {
                throw new OAuthException(
                    'invalid_client',
                    'Client authentication failed.',
                    401,
                    $usedBasic ? ['WWW-Authenticate' => 'Basic'] : []
                );
            }
        }

        return $client;
    }

    public function touch(EntityInterface $client): void
    {
        $this->clients->updateAll(['last_used' => FrozenTime::now()], ['id' => $client->get('id')]);
    }
}
