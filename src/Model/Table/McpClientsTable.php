<?php
declare(strict_types=1);

namespace CakeMcp\Model\Table;

use Cake\ORM\Table;

/**
 * OAuth clients registered through Dynamic Client Registration (RFC 7591).
 */
class McpClientsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('mcp_clients');
        $this->setPrimaryKey('id');
        $this->hasMany('McpGrants', [
            'className' => 'CakeMcp.McpGrants',
            'foreignKey' => 'mcp_client_id',
        ]);
    }
}
