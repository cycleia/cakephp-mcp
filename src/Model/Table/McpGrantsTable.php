<?php
declare(strict_types=1);

namespace CakeMcp\Model\Table;

use Cake\ORM\Table;

/**
 * Connections an administrator approved: one row per approval, holding the user, client,
 * scopes and resource every token issued under it inherits.
 */
class McpGrantsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('mcp_grants');
        $this->setPrimaryKey('id');
        $this->belongsTo('McpClients', [
            'className' => 'CakeMcp.McpClients',
            'foreignKey' => 'mcp_client_id',
        ]);
        $this->hasMany('McpTokens', [
            'className' => 'CakeMcp.McpTokens',
            'foreignKey' => 'mcp_grant_id',
        ]);
    }
}
