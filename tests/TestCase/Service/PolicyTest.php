<?php
declare(strict_types=1);

namespace CakeMcp\Test\TestCase\Service;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\TestSuite\TestCase;
use CakeMcp\McpConfig;
use CakeMcp\Service\RedirectPolicy;
use CakeMcp\Service\ResourceIndicator;
use CakeMcp\Service\Scopes;
use CakeMcp\Service\UserGate;

class PolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        Configure::delete('CakeMcp');
        parent::tearDown();
    }

    public function testRedirectPolicy(): void
    {
        $policy = new RedirectPolicy(new McpConfig());
        $this->assertTrue($policy->isAllowed('https://claude.ai/api/mcp/auth_callback'));
        $this->assertTrue($policy->isAllowed('https://claude.com/api/mcp/auth_callback'));
        $this->assertTrue($policy->isAllowed('https://chatgpt.com/connector_platform_oauth_redirect'));
        $this->assertTrue($policy->isAllowed('http://localhost:3118/callback'));
        $this->assertTrue($policy->isAllowed('http://127.0.0.1/callback'));
        $this->assertTrue($policy->isAllowed('http://[::1]:9000/cb'));
        $this->assertFalse($policy->isAllowed('https://evil.example/cb'));
        $this->assertFalse($policy->isAllowed('http://claude.ai/cb'));
        $this->assertFalse($policy->isAllowed('https://user@claude.ai/cb'));
        $this->assertFalse($policy->isAllowed('cursor://anysphere.cursor-mcp/oauth/callback'));

        $open = new RedirectPolicy(new McpConfig(['allowedRedirectHosts' => []]));
        $this->assertTrue($open->isAllowed('https://anything.example/cb'));
        $this->assertFalse((new RedirectPolicy(new McpConfig(['allowLoopbackRedirects' => false])))->isAllowed('http://localhost/cb'));
    }

    public function testLoopbackMatchesAnyPort(): void
    {
        $policy = new RedirectPolicy(new McpConfig());
        // Claude Code's metadata lists a port-less URI and listens on a port.
        $this->assertTrue($policy->matches('http://localhost:3118/callback', ['http://localhost/callback']));
        $this->assertTrue($policy->matches('http://localhost:60000/callback', ['http://localhost:54321/callback']));
        $this->assertFalse($policy->matches('http://localhost:60000/other', ['http://localhost:54321/callback']));
        $this->assertFalse($policy->matches('http://127.0.0.1:60000/callback', ['http://localhost:54321/callback']));
        // https URIs match exactly.
        $this->assertFalse($policy->matches('https://claude.ai/api/mcp/auth_callback/', ['https://claude.ai/api/mcp/auth_callback']));
        $this->assertTrue($policy->matches('https://claude.ai/api/mcp/auth_callback', ['https://claude.ai/api/mcp/auth_callback']));
    }

    public function testScopesNeverRefuseAndAlwaysIncludeTheMcpScope(): void
    {
        $config = new McpConfig();
        $this->assertSame(['mcp'], Scopes::grant($config, null));
        $this->assertSame(['mcp'], Scopes::grant($config, 'openid email profile'));
        $this->assertSame(['mcp', 'offline_access'], Scopes::grant($config, 'openid email offline_access mcp'));
        $this->assertSame(['openid', 'email'], Scopes::dropped($config, 'openid email offline_access mcp'));
        $this->assertSame(['admin'], Scopes::grant(new McpConfig(['scope' => 'admin']), 'mcp'));
    }

    public function testResourceComparison(): void
    {
        $this->assertTrue(ResourceIndicator::same('https://Site.TEST/mcp/', 'https://site.test/mcp'));
        $this->assertTrue(ResourceIndicator::same('https://site.test:443/mcp', 'https://site.test/mcp'));
        $this->assertFalse(ResourceIndicator::same('https://site.test/mcp2', 'https://site.test/mcp'));
        $this->assertFalse(ResourceIndicator::same('https://www.site.test/mcp', 'https://site.test/mcp'));
        $this->assertFalse(ResourceIndicator::same('https://site.test/mcp#x', 'https://site.test/mcp'));
    }

    public function testIdentityFromAuthenticationPlugin(): void
    {
        $gate = new UserGate(new McpConfig());
        $identity = new class {
            public function getIdentifier()
            {
                return 42;
            }
        };
        $request = (new ServerRequest(['session' => new Session()]))->withAttribute('identity', $identity);
        $this->assertSame('42', $gate->currentUserId($request));
    }

    public function testIdentityFromSession(): void
    {
        $gate = new UserGate(new McpConfig());
        $session = new Session();
        $session->write('Auth.User.id', 'b7f3c0de-uuid');
        $this->assertSame('b7f3c0de-uuid', $gate->currentUserId(new ServerRequest(['session' => $session])));

        $session = new Session();
        $session->write('Auth', ['id' => 9, 'email' => 'x@site.test']);
        $this->assertSame('9', $gate->currentUserId(new ServerRequest(['session' => $session])));

        $this->assertNull($gate->currentUserId(new ServerRequest(['session' => new Session()])));
    }

    public function testCustomIdentityResolverAndAdminRule(): void
    {
        $config = new McpConfig([
            'identityResolver' => fn () => 5,
            'isAdmin' => fn ($user) => $user->get('email') === 'boss@site.test',
        ]);
        $gate = new UserGate($config);
        $this->assertSame('5', $gate->currentUserId(new ServerRequest(['session' => new Session()])));
        $this->assertTrue($gate->isAllowed(new \Cake\ORM\Entity(['email' => 'boss@site.test'])));
        $this->assertFalse($gate->isAllowed(new \Cake\ORM\Entity(['email' => 'x@site.test', 'role' => 'admin'])));
    }

    public function testAdminRuleChecksRoleAndActiveFlag(): void
    {
        $gate = new UserGate(new McpConfig());
        $this->assertTrue($gate->isAllowed(new \Cake\ORM\Entity(['role' => 'admin', 'active' => 1])));
        $this->assertTrue($gate->isAllowed(new \Cake\ORM\Entity(['role' => 'admin'])));
        $this->assertFalse($gate->isAllowed(new \Cake\ORM\Entity(['role' => 'admin', 'active' => 0])));
        $this->assertFalse($gate->isAllowed(new \Cake\ORM\Entity(['role' => 'teacheradmin', 'active' => 1])));
        $this->assertFalse($gate->isAllowed(new \Cake\ORM\Entity(['role' => null])));
    }
}
