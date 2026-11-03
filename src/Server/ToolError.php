<?php
declare(strict_types=1);

namespace CakeMcp\Server;

use RuntimeException;

/**
 * A tool failure the caller should see, returned as a CallToolResult with `isError: true` so
 * the model can read the message and correct its arguments.
 */
class ToolError extends RuntimeException
{
}
