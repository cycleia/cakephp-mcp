<?php
declare(strict_types=1);

namespace CakeMcp\Server;

/**
 * One MCP tool. List the classes in `CakeMcp.tools` and /mcp serves them through ToolServer.
 */
interface ToolInterface
{
    /**
     * The tool as tools/list shows it: name, title, description, inputSchema, annotations.
     *
     * @return array<string, mixed>
     */
    public function definition(): array;

    /**
     * Runs the tool for an administrator already signed in and re-checked.
     *
     * Return a CallToolResult array (`content`, optionally `structuredContent`). Throw ToolError
     * for a problem the caller should see; any other exception is logged and reported generically.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param \CakeMcp\Server\McpContext $context The caller.
     * @return array<string, mixed>
     */
    public function call(array $arguments, McpContext $context): array;
}
