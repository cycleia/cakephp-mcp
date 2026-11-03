<?php
declare(strict_types=1);

namespace CakeMcp\Service;

use Cake\Datasource\EntityInterface;
use Cake\I18n\FrozenTime;
use Cake\ORM\TableRegistry;
use CakeMcp\Http\OAuthException;
use CakeMcp\McpConfig;
use CakeMcp\Model\Table\McpTokensTable;
use CakeMcp\Server\McpContext;

/**
 * Approvals (grants) and the codes and tokens issued under them.
 *
 * - Authorization codes are single use and expire after `codeTtl`. Presenting a used code
 *   revokes the grant (RFC 6749 section 4.1.2).
 * - Access tokens are opaque, expire after `accessTokenTtl`, and only work at /mcp.
 * - Refresh tokens are always issued and rotate on every use. Presenting a used refresh token
 *   revokes the grant (OAuth 2.1 section 4.3.1).
 * - The user is re-checked at code exchange, every refresh and every /mcp request.
 */
final class GrantService
{
    private const VERIFIER_PATTERN = '/^[A-Za-z0-9\-._~]{43,128}$/';

    private McpConfig $config;

    private UserGate $users;

    /**
     * @var \Cake\ORM\Table
     */
    private $grants;

    /**
     * @var \Cake\ORM\Table
     */
    private $tokens;

    /**
     * @var \Cake\ORM\Table
     */
    private $clients;

    public function __construct(McpConfig $config, ?UserGate $users = null)
    {
        $this->config = $config;
        $this->users = $users ?? new UserGate($config);
        $locator = TableRegistry::getTableLocator();
        $this->grants = $locator->get('CakeMcp.McpGrants');
        $this->tokens = $locator->get('CakeMcp.McpTokens');
        $this->clients = $locator->get('CakeMcp.McpClients');
    }

    /**
     * Records an approval and returns the authorization code for it.
     *
     * @param array<string> $scopes Granted scopes.
     */
    public function approve(
        EntityInterface $client,
        EntityInterface $user,
        array $scopes,
        string $resource,
        string $redirectUri,
        string $codeChallenge
    ): string {
        $userId = $this->users->idOfUser($user);
        $now = FrozenTime::now();

        // A new approval replaces the user's earlier ones for the same client.
        $older = $this->grants->find()
            ->select(['id'])
            ->where(['mcp_client_id' => $client->get('id'), 'user_id' => $userId, 'revoked IS' => null])
            ->all();
        foreach ($older as $grant) {
            $this->revokeGrant((int)$grant->get('id'), 'replaced by a new approval');
        }

        $grant = $this->grants->newEntity([
            'mcp_client_id' => $client->get('id'),
            'user_id' => $userId,
            'scope' => implode(' ', $scopes),
            'resource' => $resource,
            'fingerprint' => $this->users->fingerprint($user),
            'created' => $now,
        ], ['accessibleFields' => ['*' => true]]);
        $this->grants->saveOrFail($grant);

        $code = Secrets::token('mcpac');
        $this->saveToken((int)$grant->get('id'), McpTokensTable::TYPE_CODE, $code, (int)$this->config->get('codeTtl'), [
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
        ]);

        Audit::log($this->config, 'authorize approved', [
            'client' => $client->get('client_id'),
            'name' => $client->get('client_name'),
            'user' => $userId,
            'grant' => $grant->get('id'),
            'scope' => $scopes,
        ]);

        return $code;
    }

    /**
     * grant_type=authorization_code.
     *
     * @param array<string, mixed> $body Token request.
     * @return array<string, mixed> Token response.
     */
    public function exchangeCode(EntityInterface $client, array $body): array
    {
        $code = self::param($body, 'code');
        if ($code === null) {
            throw new OAuthException('invalid_request', 'code is required.');
        }
        $row = $this->findToken($code, McpTokensTable::TYPE_CODE);
        if ($row === null) {
            throw new OAuthException('invalid_grant', 'Unknown authorization code.');
        }
        $grant = $this->grantOf($row);
        if ($grant === null || (int)$grant->get('mcp_client_id') !== (int)$client->get('id')) {
            throw new OAuthException('invalid_grant', 'The code was issued to another client.');
        }

        // Single use, also under concurrency: only the request that flips `used` proceeds.
        $claimed = $this->tokens->updateAll(['used' => FrozenTime::now()], ['id' => $row->get('id'), 'used IS' => null]);
        if ($claimed !== 1) {
            $this->revokeGrant((int)$grant->get('id'), 'authorization code reused');
            throw new OAuthException('invalid_grant', 'The authorization code has already been used.');
        }
        if (self::expired($row)) {
            throw new OAuthException('invalid_grant', 'The authorization code has expired.');
        }
        if ($grant->get('revoked') !== null) {
            throw new OAuthException('invalid_grant', 'The approval has been revoked.');
        }

        $data = json_decode((string)$row->get('data'), true) ?: [];
        $redirectUri = self::param($body, 'redirect_uri');
        if ($redirectUri === null || $redirectUri !== ($data['redirect_uri'] ?? null)) {
            throw new OAuthException('invalid_grant', 'redirect_uri does not match the authorization request.');
        }
        $verifier = self::param($body, 'code_verifier');
        if ($verifier === null) {
            throw new OAuthException('invalid_request', 'code_verifier is required (PKCE).');
        }
        if (
            !preg_match(self::VERIFIER_PATTERN, $verifier)
            || !hash_equals((string)($data['code_challenge'] ?? ''), Secrets::pkceChallenge($verifier))
        ) {
            throw new OAuthException('invalid_grant', 'PKCE verification failed.');
        }
        $this->checkResource($body, $grant);
        $this->checkUser($grant);

        Audit::log($this->config, 'token issued', [
            'grant_type' => 'authorization_code',
            'client' => $client->get('client_id'),
            'user' => $grant->get('user_id'),
            'grant' => $grant->get('id'),
        ]);

        return $this->issue($grant, $client);
    }

    /**
     * grant_type=refresh_token. The presented token is spent and a new pair is returned.
     *
     * @param array<string, mixed> $body Token request.
     * @return array<string, mixed> Token response.
     */
    public function refresh(EntityInterface $client, array $body): array
    {
        $token = self::param($body, 'refresh_token');
        if ($token === null) {
            throw new OAuthException('invalid_request', 'refresh_token is required.');
        }
        $row = $this->findToken($token, McpTokensTable::TYPE_REFRESH);
        if ($row === null) {
            throw new OAuthException('invalid_grant', 'Unknown refresh token.');
        }
        $grant = $this->grantOf($row);
        if ($grant === null || (int)$grant->get('mcp_client_id') !== (int)$client->get('id')) {
            throw new OAuthException('invalid_grant', 'The refresh token was issued to another client.');
        }
        if ($grant->get('revoked') !== null || $row->get('revoked') !== null) {
            throw new OAuthException('invalid_grant', 'The approval has been revoked. Connect again.');
        }
        if ($row->get('used') !== null) {
            $this->revokeGrant((int)$grant->get('id'), 'refresh token reused');
            throw new OAuthException('invalid_grant', 'The refresh token has already been used. Connect again.');
        }
        if (self::expired($row)) {
            throw new OAuthException('invalid_grant', 'The refresh token has expired. Connect again.');
        }
        $this->checkResource($body, $grant);
        $this->checkUser($grant);

        $claimed = $this->tokens->updateAll(['used' => FrozenTime::now()], ['id' => $row->get('id'), 'used IS' => null]);
        if ($claimed !== 1) {
            $this->revokeGrant((int)$grant->get('id'), 'refresh token reused');
            throw new OAuthException('invalid_grant', 'The refresh token has already been used. Connect again.');
        }

        $requested = self::param($body, 'scope');
        $dropped = $requested === null ? [] : array_diff(Scopes::split($requested), explode(' ', (string)$grant->get('scope')));
        Audit::log($this->config, 'token issued', [
            'grant_type' => 'refresh_token',
            'client' => $client->get('client_id'),
            'user' => $grant->get('user_id'),
            'grant' => $grant->get('id'),
            'scope_dropped' => $dropped === [] ? '-' : array_values($dropped),
        ]);

        return $this->issue($grant, $client);
    }

    /**
     * RFC 7009. Revoking a refresh token revokes the whole approval; revoking an access token
     * revokes that token. Unknown tokens are ignored.
     */
    public function revokeToken(EntityInterface $client, string $token): void
    {
        $row = $this->tokens->find()
            ->where(['token_hash' => Secrets::hash($token), 'type IN' => [McpTokensTable::TYPE_ACCESS, McpTokensTable::TYPE_REFRESH]])
            ->first();
        if ($row === null) {
            return;
        }
        $grant = $this->grantOf($row);
        if ($grant === null || (int)$grant->get('mcp_client_id') !== (int)$client->get('id')) {
            return;
        }
        if ($row->get('type') === McpTokensTable::TYPE_REFRESH) {
            $this->revokeGrant((int)$grant->get('id'), 'revoked by the client');
        } else {
            $this->tokens->updateAll(['revoked' => FrozenTime::now()], ['id' => $row->get('id')]);
            Audit::log($this->config, 'access token revoked', ['client' => $client->get('client_id'), 'grant' => $grant->get('id')]);
        }
    }

    /**
     * Checks a bearer token presented at /mcp.
     *
     * @param string $token The access token.
     * @param string|null $reason Set to why the token was refused.
     */
    public function authenticateAccess(string $token, ?string &$reason = null): ?McpContext
    {
        $row = $this->findToken($token, McpTokensTable::TYPE_ACCESS);
        if ($row === null) {
            $reason = 'unknown token';

            return null;
        }
        if ($row->get('revoked') !== null) {
            $reason = 'token revoked';

            return null;
        }
        if (self::expired($row)) {
            $reason = 'token expired';

            return null;
        }
        $grant = $this->grants->find()->contain(['McpClients'])->where(['McpGrants.id' => $row->get('mcp_grant_id')])->first();
        if ($grant === null || $grant->get('revoked') !== null) {
            $reason = 'approval revoked';

            return null;
        }
        if (!ResourceIndicator::same((string)$grant->get('resource'), $this->config->resource())) {
            $reason = 'token was issued for another resource';

            return null;
        }
        $why = null;
        $user = $this->users->check((string)$grant->get('user_id'), $grant->get('fingerprint'), $why);
        if ($user === null) {
            $this->revokeGrant((int)$grant->get('id'), (string)$why);
            $reason = (string)$why;

            return null;
        }

        $lastUsed = $grant->get('last_used');
        if (!$lastUsed instanceof FrozenTime || $lastUsed->lessThan(FrozenTime::now()->subMinutes(5))) {
            $this->grants->updateAll(['last_used' => FrozenTime::now()], ['id' => $grant->get('id')]);
        }

        /** @var \Cake\Datasource\EntityInterface $client */
        $client = $grant->get('mcp_client');

        return new McpContext(
            $user,
            (string)$grant->get('user_id'),
            $this->users->displayName($user),
            (string)$client->get('client_id'),
            (string)$client->get('client_name'),
            Scopes::split((string)$grant->get('scope')),
            (int)$grant->get('id'),
            $this->config->siteName()
        );
    }

    public function revokeGrant(int $grantId, string $reason): void
    {
        $now = FrozenTime::now();
        $changed = $this->grants->updateAll(
            ['revoked' => $now, 'revoked_reason' => mb_substr($reason, 0, 255)],
            ['id' => $grantId, 'revoked IS' => null]
        );
        $this->tokens->updateAll(['revoked' => $now], ['mcp_grant_id' => $grantId, 'revoked IS' => null]);
        if ($changed > 0) {
            Audit::log($this->config, 'grant revoked', ['grant' => $grantId, 'reason' => $reason]);
        }
    }

    /**
     * The user's active approvals, newest first, with their client.
     *
     * @return array<\Cake\Datasource\EntityInterface>
     */
    public function grantsForUser(string $userId): array
    {
        return $this->grants->find()
            ->contain(['McpClients'])
            ->where(['McpGrants.user_id' => $userId, 'McpGrants.revoked IS' => null])
            ->order(['McpGrants.created' => 'DESC'])
            ->all()
            ->toList();
    }

    public function revokeForUser(int $grantId, string $userId): bool
    {
        $grant = $this->grants->find()->where(['id' => $grantId, 'user_id' => $userId, 'revoked IS' => null])->first();
        if ($grant === null) {
            return false;
        }
        $this->revokeGrant($grantId, 'revoked by the user');

        return true;
    }

    /**
     * Deletes expired tokens, long-revoked approvals, and registrations that were never used.
     *
     * @return array<string, int> Rows deleted per table.
     */
    public function purge(): array
    {
        $now = FrozenTime::now();
        $tokens = $this->tokens->deleteAll(['expires <' => $now->subDays(1)]);

        $oldGrants = $this->grants->find()->select(['id'])->where(['revoked <' => $now->subDays(30)])->all()->extract('id')->toList();
        $grants = 0;
        if ($oldGrants !== []) {
            $tokens += $this->tokens->deleteAll(['mcp_grant_id IN' => $oldGrants]);
            $grants = $this->grants->deleteAll(['id IN' => $oldGrants]);
        }

        $withGrants = $this->grants->find()->select(['mcp_client_id']);
        $clients = $this->clients->deleteAll([
            'created <' => $now->subDays(1),
            'id NOT IN' => $withGrants,
        ]);

        return ['tokens' => $tokens, 'grants' => $grants, 'clients' => $clients];
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(EntityInterface $grant, EntityInterface $client): array
    {
        $access = Secrets::token('mcpat');
        $refresh = Secrets::token('mcprt');
        $accessTtl = (int)$this->config->get('accessTokenTtl');
        $this->saveToken((int)$grant->get('id'), McpTokensTable::TYPE_ACCESS, $access, $accessTtl);
        $this->saveToken((int)$grant->get('id'), McpTokensTable::TYPE_REFRESH, $refresh, (int)$this->config->get('refreshTokenTtl'));
        $this->grants->updateAll(['last_used' => FrozenTime::now()], ['id' => $grant->get('id')]);
        $this->clients->updateAll(['last_used' => FrozenTime::now()], ['id' => $client->get('id')]);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'refresh_token' => $refresh,
            'scope' => (string)$grant->get('scope'),
        ];
    }

    /**
     * @param array<string, mixed>|null $data Extra data (codes only).
     */
    private function saveToken(int $grantId, string $type, string $token, int $ttl, ?array $data = null): void
    {
        $now = FrozenTime::now();
        $entity = $this->tokens->newEntity([
            'mcp_grant_id' => $grantId,
            'type' => $type,
            'token_hash' => Secrets::hash($token),
            'expires' => $now->addSeconds($ttl),
            'data' => $data === null ? null : json_encode($data, JSON_UNESCAPED_SLASHES),
            'created' => $now,
        ], ['accessibleFields' => ['*' => true]]);
        $this->tokens->saveOrFail($entity);
    }

    private function grantOf(EntityInterface $token): ?EntityInterface
    {
        $grant = $this->grants->find()->where(['id' => $token->get('mcp_grant_id')])->first();

        return $grant instanceof EntityInterface ? $grant : null;
    }

    private function findToken(string $token, string $type): ?EntityInterface
    {
        if ($token === '' || strlen($token) > 512) {
            return null;
        }
        $row = $this->tokens->find()->where(['token_hash' => Secrets::hash($token), 'type' => $type])->first();

        return $row instanceof EntityInterface ? $row : null;
    }

    /**
     * @param array<string, mixed> $body Token request.
     */
    private function checkResource(array $body, EntityInterface $grant): void
    {
        $resource = self::param($body, 'resource');
        if ($resource !== null && !ResourceIndicator::same($resource, (string)$grant->get('resource'))) {
            throw new OAuthException('invalid_target', 'This server only issues tokens for ' . $grant->get('resource') . '.');
        }
    }

    private function checkUser(EntityInterface $grant): void
    {
        $why = null;
        if ($this->users->check((string)$grant->get('user_id'), $grant->get('fingerprint'), $why) === null) {
            $this->revokeGrant((int)$grant->get('id'), (string)$why);
            throw new OAuthException('invalid_grant', 'The account is no longer allowed to use MCP: ' . $why . '.');
        }
    }

    private static function expired(EntityInterface $row): bool
    {
        $expires = $row->get('expires');

        return !$expires instanceof \DateTimeInterface || $expires < FrozenTime::now();
    }

    /**
     * @param array<string, mixed> $body Request body.
     */
    private static function param(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
