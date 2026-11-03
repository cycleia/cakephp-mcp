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
 * bin/cake mcp purge — deletes expired tokens, approvals revoked over 30 days ago, and client
 * registrations that never led to an approval. Registration also does this on the fly.
 */
class McpPurgeCommand extends Command
{
    public static function defaultName(): string
    {
        return 'mcp purge';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser->setDescription('Delete expired MCP OAuth tokens, old revoked approvals and unused client registrations.');
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $counts = (new GrantService(new McpConfig()))->purge();
        $io->out(sprintf('Deleted %d tokens, %d approvals, %d clients.', $counts['tokens'], $counts['grants'], $counts['clients']));

        return static::CODE_SUCCESS;
    }
}
