<?php
declare(strict_types=1);

namespace CakeMcp\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use CakeMcp\McpConfig;
use CakeMcp\Service\GrantService;

/**
 * bin/cake mcp clients              — list registered clients and their active approvals
 * bin/cake mcp clients --revoke ID  — revoke every approval of one client
 */
class McpClientsCommand extends Command
{
    public static function defaultName(): string
    {
        return 'mcp clients';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('List registered MCP OAuth clients, or revoke all approvals of one.')
            ->addOption('revoke', ['help' => 'client_id whose approvals to revoke']);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $clients = $this->fetchTable('CakeMcp.McpClients');
        $grants = $this->fetchTable('CakeMcp.McpGrants');

        $revoke = $args->getOption('revoke');
        if (is_string($revoke) && $revoke !== '') {
            $client = $clients->find()->where(['client_id' => $revoke])->first();
            if ($client === null) {
                $io->err('No client with client_id ' . $revoke);

                return static::CODE_ERROR;
            }
            $service = new GrantService(new McpConfig());
            $ids = $grants->find()->select(['id'])->where(['mcp_client_id' => $client->get('id'), 'revoked IS' => null])->all()->extract('id')->toList();
            foreach ($ids as $id) {
                $service->revokeGrant((int)$id, 'revoked from the command line');
            }
            $io->out(sprintf('Revoked %d approval(s) of %s (%s).', count($ids), $client->get('client_name'), $revoke));

            return static::CODE_SUCCESS;
        }

        $rows = [['client_id', 'name', 'registered', 'last used', 'active approvals', 'redirect URIs']];
        foreach ($clients->find()->order(['created' => 'DESC'])->all() as $client) {
            $active = $grants->find()->where(['mcp_client_id' => $client->get('id'), 'revoked IS' => null])->count();
            $rows[] = [
                (string)$client->get('client_id'),
                (string)$client->get('client_name'),
                $client->get('created') ? $client->get('created')->format('Y-m-d H:i') : '',
                $client->get('last_used') ? $client->get('last_used')->format('Y-m-d H:i') : 'never',
                (string)$active,
                implode(' ', json_decode((string)$client->get('redirect_uris'), true) ?: []),
            ];
        }
        if (count($rows) === 1) {
            $io->out('No registered clients.');

            return static::CODE_SUCCESS;
        }
        $io->helper('Table')->output($rows);

        return static::CODE_SUCCESS;
    }
}
