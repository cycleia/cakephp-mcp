<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Tables for registered OAuth clients, approved connections (grants), and the codes and tokens
 * issued under each grant. Every secret is stored as a SHA-256 hash only.
 *
 * user_id is a string so that integer and UUID user keys both fit; it has no foreign key
 * because the users table name differs per site.
 */
class CreateCakeMcpTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('mcp_clients')
            ->addColumn('client_id', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('secret_hash', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('client_name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('redirect_uris', 'text', ['null' => false])
            ->addColumn('grant_types', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('auth_method', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('application_type', 'string', ['limit' => 16, 'null' => true, 'default' => null])
            ->addColumn('scope', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('metadata', 'text', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('last_used', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['client_id'], ['unique' => true])
            ->create();

        $this->table('mcp_grants')
            ->addColumn('mcp_client_id', 'integer', ['null' => false])
            ->addColumn('user_id', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('scope', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('resource', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('fingerprint', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('last_used', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('revoked', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('revoked_reason', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addIndex(['mcp_client_id'])
            ->addIndex(['user_id'])
            ->create();

        $this->table('mcp_tokens')
            ->addColumn('mcp_grant_id', 'integer', ['null' => false])
            ->addColumn('type', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('expires', 'datetime', ['null' => false])
            ->addColumn('used', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('revoked', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('data', 'text', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['mcp_grant_id'])
            ->addIndex(['expires'])
            ->create();
    }
}
