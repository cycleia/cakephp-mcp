<?php
declare(strict_types=1);

namespace App;

use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\RouteBuilder;

/**
 * Stands in for a site. Login is simulated by putting a user id in the session under
 * Auth.User.id, the way the old AuthComponent does.
 */
class Application extends BaseApplication
{
    public function bootstrap(): void
    {
        $this->addPlugin('CakeMcp');
    }

    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue
            ->add(new ErrorHandlerMiddleware())
            ->add(new RoutingMiddleware($this))
            ->add(new BodyParserMiddleware());
    }

    public function routes(RouteBuilder $routes): void
    {
        $routes->scope('/', function (RouteBuilder $builder): void {
            $builder->connect('/login', ['controller' => 'Pages', 'action' => 'login']);
            $builder->fallbacks();
        });
    }
}
