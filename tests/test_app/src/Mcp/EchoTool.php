<?php
declare(strict_types=1);

namespace App\Mcp;

use CakeMcp\Server\McpContext;
use CakeMcp\Server\Tool;
use CakeMcp\Server\ToolError;

/**
 * Test tool: echoes its argument, refuses "bad", and crashes on "boom".
 */
class EchoTool extends Tool
{
    public function definition(): array
    {
        return [
            'name' => 'echo',
            'description' => 'Echo a word.',
            'inputSchema' => self::schema(['word' => ['type' => 'string']], ['word']),
            'annotations' => self::readOnly(),
        ];
    }

    public function call(array $arguments, McpContext $context): array
    {
        $word = $this->string($arguments, 'word', true, 20);
        if ($word === 'bad') {
            throw new ToolError('That word is not allowed.');
        }
        if ($word === 'boom') {
            throw new \LogicException('secret internal detail');
        }

        // Site code that builds URLs must work inside tools.
        $login = \Cake\Routing\Router::url(['controller' => 'Pages', 'action' => 'login', '_full' => true]);

        return $this->result(['word' => $word, 'user' => $context->userId, 'login' => $login], 'Echoed.');
    }
}
