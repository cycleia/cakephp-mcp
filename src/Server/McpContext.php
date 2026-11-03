<?php
declare(strict_types=1);

namespace CakeMcp\Server;

use Cake\Datasource\EntityInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who is calling /mcp. Set as the request attribute `cakeMcp` before the site's MCP handler
 * runs; the token has already been checked and the user re-loaded and confirmed as an admin.
 */
final class McpContext
{
    public const ATTRIBUTE = 'cakeMcp';

    public EntityInterface $user;

    public string $userId;

    public string $userName;

    public string $clientId;

    public string $clientName;

    /**
     * @var array<string>
     */
    public array $scopes;

    public int $grantId;

    public string $siteName;

    /**
     * @param array<string> $scopes Granted scopes.
     */
    public function __construct(
        EntityInterface $user,
        string $userId,
        string $userName,
        string $clientId,
        string $clientName,
        array $scopes,
        int $grantId,
        string $siteName
    ) {
        $this->user = $user;
        $this->userId = $userId;
        $this->userName = $userName;
        $this->clientId = $clientId;
        $this->clientName = $clientName;
        $this->scopes = $scopes;
        $this->grantId = $grantId;
        $this->siteName = $siteName;
    }

    public static function fromRequest(ServerRequestInterface $request): ?self
    {
        $context = $request->getAttribute(self::ATTRIBUTE);

        return $context instanceof self ? $context : null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
