<?php
declare(strict_types=1);

namespace CakeMcp\Model\Table;

use Cake\ORM\Table;

/**
 * Authorization codes, access tokens and refresh tokens (`type` = code|access|refresh),
 * stored by SHA-256 hash.
 */
class McpTokensTable extends Table
{
    public const TYPE_CODE = 'code';
    public const TYPE_ACCESS = 'access';
    public const TYPE_REFRESH = 'refresh';

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('mcp_tokens');
        $this->setPrimaryKey('id');
        $this->belongsTo('McpGrants', [
            'className' => 'CakeMcp.McpGrants',
            'foreignKey' => 'mcp_grant_id',
        ]);
    }
}
