<?php
declare(strict_types=1);

namespace CakeMcp\Server;

use CakeMcp\McpConfig;
use CakeMcp\Service\Audit;
use RuntimeException;
use Throwable;

/**
 * Serves the tools listed in `CakeMcp.tools`, plus the built-in `whoami`, over both protocol
 * eras (see DiagnosticServer). /mcp uses it automatically when `CakeMcp.tools` is set.
 *
 * Every call is logged (tool, user, client, outcome). A ToolError comes back to the model as a
 * result with `isError: true`; any other exception is logged and reported without details.
 */
class ToolServer extends DiagnosticServer
{
    private McpConfig $config;

    /**
     * @var array<string, \CakeMcp\Server\ToolInterface>|null
     */
    private ?array $registry = null;

    /**
     * @param array<\CakeMcp\Server\ToolInterface|class-string<\CakeMcp\Server\ToolInterface>>|null $tools Defaults to CakeMcp.tools.
     */
    public function __construct(?array $tools = null, ?McpConfig $config = null)
    {
        $this->config = $config ?? new McpConfig();
        if ($tools !== null) {
            $this->registry = $this->build($tools);
        }
    }

    protected function tools(): array
    {
        $definitions = parent::tools();
        foreach ($this->registry() as $tool) {
            $definitions[] = $tool->definition();
        }

        return $definitions;
    }

    protected function callTool(string $name, array $arguments, ?McpContext $context): ?array
    {
        $tool = $this->registry()[$name] ?? null;
        if ($tool === null) {
            return parent::callTool($name, $arguments, $context);
        }
        if ($context === null) {
            return ['content' => [['type' => 'text', 'text' => 'Not signed in.']], 'isError' => true];
        }

        $log = ['tool' => $name, 'user' => $context->userId, 'client' => $context->clientName];
        try {
            $result = $tool->call($arguments, $context);
            Audit::log($this->config, 'tool call', $log + ['outcome' => empty($result['isError']) ? 'ok' : 'error']);

            return $result;
        } catch (ToolError $e) {
            Audit::log($this->config, 'tool call', $log + ['outcome' => 'refused', 'error' => $e->getMessage()], 'notice');

            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        } catch (Throwable $e) {
            Audit::log($this->config, 'tool call', $log + [
                'outcome' => 'failed',
                'error' => get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine(),
            ], 'error');

            return ['content' => [['type' => 'text', 'text' => "The $name tool failed with an internal error. It has been logged."]], 'isError' => true];
        }
    }

    protected function serverInfo(?McpContext $context): array
    {
        $name = $this->config->get('serverName');

        return [
            'name' => is_string($name) && $name !== '' ? $name : $this->config->siteName() . ' MCP',
            'version' => '0.2.0',
        ];
    }

    protected function instructions(?McpContext $context): string
    {
        $instructions = $this->config->get('instructions');

        return is_string($instructions) && $instructions !== '' ? $instructions : parent::instructions($context);
    }

    /**
     * @return array<string, \CakeMcp\Server\ToolInterface>
     */
    private function registry(): array
    {
        if ($this->registry === null) {
            $this->registry = $this->build((array)$this->config->get('tools', []));
        }

        return $this->registry;
    }

    /**
     * @param array<mixed> $tools Tool instances or class names.
     * @return array<string, \CakeMcp\Server\ToolInterface>
     */
    private function build(array $tools): array
    {
        $registry = [];
        foreach ($tools as $tool) {
            if (is_string($tool) && class_exists($tool)) {
                $tool = new $tool();
            }
            if (!$tool instanceof ToolInterface) {
                throw new RuntimeException('CakeMcp.tools entries must be ToolInterface instances or class names.');
            }
            $name = (string)($tool->definition()['name'] ?? '');
            if ($name === '' || isset($registry[$name]) || $name === 'whoami') {
                throw new RuntimeException("Tool name '$name' is empty or used twice.");
            }
            $registry[$name] = $tool;
        }

        return $registry;
    }
}
