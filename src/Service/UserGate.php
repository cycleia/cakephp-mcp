<?php
declare(strict_types=1);

namespace CakeMcp\Service;

use Cake\Datasource\EntityInterface;
use Cake\Http\ServerRequest;
use Cake\ORM\TableRegistry;
use Cake\Utility\Security;
use CakeMcp\McpConfig;

/**
 * Who is signed in, and are they allowed to use MCP.
 *
 * The user is re-loaded from the site's users table on every check (approval, code exchange,
 * refresh, and every /mcp request), so demoting, deactivating or deleting someone cuts their
 * connections off immediately, and changing their password revokes them.
 */
final class UserGate
{
    private McpConfig $config;

    public function __construct(McpConfig $config)
    {
        $this->config = $config;
    }

    /**
     * The signed-in user's id, from (in order) the configured resolver, the
     * cakephp/authentication `identity` request attribute, or the session (old AuthComponent).
     */
    public function currentUserId(ServerRequest $request): ?string
    {
        $resolver = $this->config->get('identityResolver');
        if (is_callable($resolver)) {
            return self::stringId($resolver($request));
        }

        $identity = $request->getAttribute('identity');
        if (is_object($identity) && method_exists($identity, 'getIdentifier')) {
            $id = self::stringId($identity->getIdentifier());
            if ($id !== null) {
                return $id;
            }
        }
        $id = self::idOf($identity);
        if ($id !== null) {
            return $id;
        }

        $session = $request->getSession();
        $key = (string)$this->config->get('sessionUserKey');
        if ($key !== '') {
            $id = self::stringId($session->read($key));
            if ($id !== null) {
                return $id;
            }
        }

        return self::idOf($session->read('Auth'));
    }

    public function load(string $userId): ?EntityInterface
    {
        $table = TableRegistry::getTableLocator()->get((string)$this->config->get('usersTable'));
        $primaryKey = (array)$table->getPrimaryKey();

        $user = $table->find((string)$this->config->get('userFinder', 'all'))
            ->where([$table->aliasField((string)$primaryKey[0]) => $userId])
            ->first();

        return $user instanceof EntityInterface ? $user : null;
    }

    public function isAllowed(EntityInterface $user): bool
    {
        $isAdmin = $this->config->get('isAdmin');
        if (is_callable($isAdmin)) {
            return (bool)$isAdmin($user);
        }

        $role = $user->get((string)$this->config->get('roleField'));
        if (!is_string($role) || !in_array($role, (array)$this->config->get('adminRoles'), true)) {
            return false;
        }
        $activeField = $this->config->get('activeField');
        if (is_string($activeField) && $activeField !== '' && $user->has($activeField)) {
            return (bool)$user->get($activeField);
        }

        return true;
    }

    /**
     * A keyed hash of the user's password hash. Stored on the grant; a mismatch later means
     * the password changed and the grant is revoked.
     */
    public function fingerprint(EntityInterface $user): ?string
    {
        $field = $this->config->get('passwordField');
        if (!is_string($field) || $field === '') {
            return null;
        }
        $value = $user->get($field);
        if (!is_string($value) || $value === '') {
            return null;
        }

        return hash_hmac('sha256', 'cake_mcp|' . $value, Security::getSalt());
    }

    /**
     * Loads the user and returns them only if they are still allowed and their password
     * has not changed since the grant was made.
     *
     * @param string $userId User id.
     * @param string|null $fingerprint The fingerprint stored on the grant.
     * @param string|null $reason Set to why the user was refused.
     */
    public function check(string $userId, ?string $fingerprint, ?string &$reason = null): ?EntityInterface
    {
        $user = $this->load($userId);
        if ($user === null) {
            $reason = 'user not found or no longer active';

            return null;
        }
        if (!$this->isAllowed($user)) {
            $reason = 'user is no longer an administrator';

            return null;
        }
        $current = $this->fingerprint($user);
        if ($fingerprint !== null && ($current === null || !hash_equals($fingerprint, $current))) {
            $reason = 'password changed';

            return null;
        }

        return $user;
    }

    public function displayName(EntityInterface $user): string
    {
        foreach (array_filter([$this->config->get('displayField'), 'email', 'username', 'name']) as $field) {
            $value = $user->get((string)$field);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'user #' . self::idOf($user);
    }

    public function idOfUser(EntityInterface $user): string
    {
        return (string)self::idOf($user);
    }

    /**
     * @param mixed $value An entity, array or object carrying an id.
     */
    private static function idOf($value): ?string
    {
        if ($value instanceof EntityInterface) {
            $id = self::stringId($value->get('id'));
            if ($id === null && $value->getSource() !== '') {
                try {
                    $primaryKey = (array)TableRegistry::getTableLocator()->get($value->getSource())->getPrimaryKey();
                    $id = self::stringId($value->get((string)$primaryKey[0]));
                } catch (\Throwable $e) {
                    $id = null;
                }
            }

            return $id;
        }
        if (is_array($value) || $value instanceof \ArrayAccess) {
            return self::stringId($value['id'] ?? null);
        }
        if (is_object($value) && isset($value->id)) {
            return self::stringId($value->id);
        }

        return null;
    }

    /**
     * @param mixed $value Candidate id.
     */
    private static function stringId($value): ?string
    {
        if (is_int($value) || (is_string($value) && $value !== '')) {
            return (string)$value;
        }

        return null;
    }
}
