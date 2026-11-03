<?php
declare(strict_types=1);

namespace CakeMcp\Controller;

use App\Controller\AppController as SiteAppController;
use Cake\Http\Response;
use CakeMcp\McpConfig;

/**
 * Extends the site's own AppController, so the approval pages use the site's login,
 * permission checks and layout.
 */
class AppController extends SiteAppController
{
    /**
     * Renders an explanation page instead of throwing, so it works with any site layout.
     */
    protected function problem(string $title, string $message, int $status = 400): Response
    {
        $this->set(['title' => $title, 'message' => $message, 'siteName' => (new McpConfig())->siteName()]);
        $this->response = $this->response->withStatus($status);

        return $this->render('/Authorize/problem');
    }

    /**
     * Rejects state-changing requests that come from another site.
     */
    protected function sameOrigin(McpConfig $config): bool
    {
        $origin = $this->request->getHeaderLine('Origin');

        return $origin === '' || strtolower(rtrim($origin, '/')) === $config->origin();
    }

    protected function loginRedirect(McpConfig $config): Response
    {
        $login = (string)$config->get('loginUrl');
        $separator = strpos($login, '?') === false ? '?' : '&';

        return $this->redirect(
            $login . $separator . rawurlencode((string)$config->get('loginRedirectParam')) . '='
            . rawurlencode($this->request->getRequestTarget())
        );
    }
}
