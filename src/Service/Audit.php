<?php
declare(strict_types=1);

namespace CakeMcp\Service;

use Cake\Log\Log;
use CakeMcp\McpConfig;

/**
 * One log line per OAuth step (registration, authorization, token, refresh, revocation, /mcp
 * refusals), so a failed connection can be traced with a single grep. Never logs a code,
 * token or secret.
 */
final class Audit
{
    /**
     * @param \CakeMcp\McpConfig $config Plugin config.
     * @param string $event Short event name, e.g. "register".
     * @param array<string, mixed> $context Values to append as key=value.
     */
    public static function log(McpConfig $config, string $event, array $context = [], string $level = 'info'): void
    {
        $parts = ['[cake_mcp] ' . $event];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $value = implode(' ', array_map('strval', $value));
            } elseif (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            $value = preg_replace('/\s+/', ' ', (string)$value);
            $parts[] = $key . '=' . (strpbrk((string)$value, ' "') !== false ? '"' . str_replace('"', "'", (string)$value) . '"' : $value);
        }
        Log::write($level, implode(' ', $parts), ['scope' => [(string)$config->get('logScope', 'cake_mcp')]]);
    }
}
