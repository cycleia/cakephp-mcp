<?php
declare(strict_types=1);

namespace CakeMcp\Controller;

use Cake\Http\Response;
use CakeMcp\McpConfig;
use CakeMcp\Service\GrantService;
use CakeMcp\Service\UserGate;

/**
 * /oauth/connections: the signed-in administrator's approved AI connections, with a way to
 * revoke each one. Revoking stops its access and refresh tokens immediately.
 */
class ConnectionsController extends AppController
{
    public function index(): ?Response
    {
        $config = new McpConfig();
        $userId = (new UserGate($config))->currentUserId($this->request);
        if ($userId === null) {
            return $this->loginRedirect($config);
        }

        $this->set([
            'siteName' => $config->siteName(),
            'mcpUrl' => $config->resource(),
            'grants' => (new GrantService($config))->grantsForUser($userId),
            'revokeUrl' => $config->oauthPath() . '/connections/revoke',
        ]);

        return null;
    }

    public function revoke(): ?Response
    {
        $this->request->allowMethod(['post']);
        $config = new McpConfig();
        if (!$this->sameOrigin($config)) {
            return $this->problem('Request refused', 'This request did not come from ' . $config->siteName() . '.', 403);
        }
        $userId = (new UserGate($config))->currentUserId($this->request);
        if ($userId === null) {
            return $this->loginRedirect($config);
        }
        $revoked = (new GrantService($config))->revokeForUser((int)$this->request->getData('grant_id'), $userId);
        if ($this->components()->has('Flash')) {
            $revoked
                ? $this->Flash->success('The connection was revoked.')
                : $this->Flash->error('That connection was not found.');
        }

        return $this->redirect($config->oauthPath() . '/connections');
    }
}
