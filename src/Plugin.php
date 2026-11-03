<?php
declare(strict_types=1);

namespace CakeMcp;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\RouteBuilder;
use CakeMcp\Command\McpClientsCommand;
use CakeMcp\Command\McpPurgeCommand;
use CakeMcp\Middleware\CakeMcpMiddleware;
use LogicException;
use RuntimeException;

/**
 * Load with `$this->addPlugin('CakeMcp');` in Application::bootstrap().
 */
class Plugin extends BasePlugin
{
    protected $name = 'CakeMcp';

    protected $bootstrapEnabled = false;

    /**
     * The approval and connections pages. They are ordinary pages: they need the site's login
     * session, layout and CSRF protection. If the app registered a middleware named `csrf`
     * on the route builder, it is applied here too.
     */
    public function routes(RouteBuilder $routes): void
    {
        $oauthPath = (new McpConfig())->oauthPath();
        $routes->scope($oauthPath, ['plugin' => 'CakeMcp'], function (RouteBuilder $builder): void {
            try {
                $builder->applyMiddleware('csrf');
            } catch (RuntimeException $e) {
                // The app has no route middleware called `csrf` (it may apply CSRF globally).
            }
            $builder->connect('/authorize', ['controller' => 'Authorize', 'action' => 'index']);
            $builder->connect('/connections', ['controller' => 'Connections', 'action' => 'index']);
            $builder->connect('/connections/revoke', ['controller' => 'Connections', 'action' => 'revoke']);
        });
    }

    /**
     * Inserts the OAuth/MCP middleware ahead of routing, so the machine-facing endpoints skip
     * the app's CSRF, session, authentication and permission layers.
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $middleware = new CakeMcpMiddleware();
        try {
            $middlewareQueue->insertBefore(RoutingMiddleware::class, $middleware);
        } catch (LogicException $e) {
            $middlewareQueue->prepend($middleware);
        }

        return $middlewareQueue;
    }

    public function console(CommandCollection $commands): CommandCollection
    {
        $commands->add('mcp clients', McpClientsCommand::class);
        $commands->add('mcp purge', McpPurgeCommand::class);

        return $commands;
    }
}
