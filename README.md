# CakeMcp

A CakePHP 4 plugin that adds the sign-in part of a remote [MCP](https://modelcontextprotocol.io)
server to an existing site, so the site's **administrators** can connect **Claude** (web, desktop
and mobile), **ChatGPT**, **Claude Code** and **Codex** to `https://<site>/mcp` using their normal
site login.

The plugin is the OAuth 2.1 authorization server, the token check at `/mcp`, and the approval
and "AI connections" pages. The MCP tools themselves are each site's own code. Until a site
plugs its tools in, `/mcp` serves a small built-in server with one tool, `whoami`, so the
sign-in can be tested from every client first.

- Authorization code flow with PKCE (S256 only), public clients by default
- Dynamic Client Registration (RFC 7591)
- Protected Resource Metadata (RFC 9728) and Authorization Server Metadata (RFC 8414)
- Tokens bound to the `/mcp` resource (RFC 8707), `iss` in authorization responses (RFC 9207)
- Opaque access tokens (1 hour) and rotating refresh tokens (30 days), stored only as SHA-256 hashes
- Revocation (RFC 7009), plus a page where administrators see and revoke their connections
- The user is re-checked on every request: demoting, deactivating or changing the password of
  an administrator cuts their connections off immediately
- No signing keys, no extra services: everything runs inside the CakePHP app and its database

Requires PHP 8.1+ and CakePHP 4.5+. Works with sites that sign users in with
[CakeDC/Users](https://github.com/CakeDC/users), with `cakephp/authentication`, or with the
older `AuthComponent`.

## Endpoints

| URL | What it is |
|---|---|
| `/.well-known/oauth-protected-resource/mcp` | Tells clients where to sign in (RFC 9728). Also served without the `/mcp` suffix. |
| `/.well-known/oauth-authorization-server/oauth` | Authorization server metadata (RFC 8414). The issuer is `https://<site>/oauth`. |
| `/oauth/register` | Client registration (RFC 7591) |
| `/oauth/authorize` | The approval page (needs the site's login) |
| `/oauth/token` | Code exchange and refresh |
| `/oauth/revoke` | Token revocation (RFC 7009) |
| `/oauth/connections` | The signed-in administrator's AI connections, with Revoke buttons |
| `/mcp` | The MCP endpoint: checks the bearer token, then runs the site's MCP handler |

The issuer lives under `/oauth` rather than at the site root, so the plugin never collides with
an OpenID Connect provider the site may already serve at `/.well-known/openid-configuration`.

## Install

1. Require the package. It is not on Packagist yet, so add the repository first:

   ```json
   "repositories": [
       {"type": "vcs", "url": "https://github.com/cycleia/cakephp-mcp"}
   ],
   ```

   ```bash
   composer require cycleia/cakephp-mcp:dev-master
   ```

2. Load the plugin in `src/Application.php`:

   ```php
   public function bootstrap(): void
   {
       // ...
       $this->addPlugin('CakeMcp');
   }
   ```

3. Create the tables (`mcp_clients`, `mcp_grants`, `mcp_tokens`):

   ```bash
   bin/cake migrations migrate -p CakeMcp
   ```

   or apply `config/schema/cake_mcp.mysql.sql` by hand.

4. Configure it, for example in `config/app_local.php` or `config/bootstrap.php`:

   ```php
   'CakeMcp' => [
       // The public origin. Set it explicitly so URLs never come from the Host header.
       'baseUrl' => 'https://example.org',
       'siteName' => 'Example',
       'usersTable' => 'Users',          // 'MyUsers' on CakeDC/Users sites that extend the table
       'adminRoles' => ['admin'],        // values of users.role that may connect
   ],
   ```

5. Let signed-in users reach the approval pages. With CakeDC/Users (or another
   `config/permissions.php` RBAC), add:

   ```php
   [
       'role' => '*',
       'plugin' => 'CakeMcp',
       'controller' => ['Authorize', 'Connections'],
       'action' => '*',
   ],
   ```

   The plugin itself refuses anyone who is not an administrator, with a page that says so;
   allowing every signed-in role here only means non-administrators see that explanation rather
   than a generic "not authorized" redirect. Anonymous visitors are sent to the site's login and
   come back afterwards.

   Nothing else needs an exception: the discovery documents, registration, token, revocation and
   `/mcp` endpoints are handled by middleware that runs before the app's routing, CSRF,
   authentication and permission layers.

6. Make sure the web server lets `/.well-known/` through. A common hardening rule,
   `location ~ /\. { deny all; }`, blocks it; change it to `location ~ /\.(?!well-known/) { deny all; }`.
   MCP clients find the sign-in server only through these paths.

7. Connect: add `https://<site>/mcp` as a custom connector in Claude or ChatGPT, or run
   `claude mcp add --transport http <name> https://<site>/mcp` or
   `codex mcp add <name> --url https://<site>/mcp` then `codex mcp login <name>`.
   Sign in as an administrator, approve, and ask the assistant to call `whoami`.

## Configuration

All settings live under the `CakeMcp` key. Defaults are in `src/McpConfig.php`.

| Key | Default | Meaning |
|---|---|---|
| `baseUrl` | `App.fullBaseUrl` | Public origin of the site. All advertised URLs are built from it. |
| `siteName` | host of `baseUrl` | Shown on the approval page and in `whoami`. |
| `mcpPath` / `oauthPath` | `/mcp` / `/oauth` | Endpoint paths. |
| `scope` | `mcp` | The one scope granted. |
| `usersTable` | `Users` | Table to load users from. |
| `userFinder` | `all` | Finder used to load a user by primary key, e.g. one that excludes soft-deleted users. |
| `roleField` / `adminRoles` | `role` / `['admin']` | Users whose role is in `adminRoles` are administrators. |
| `activeField` | `active` | When the user entity has this field, it must be truthy. `null` disables. |
| `passwordField` | `password` | Changing it revokes the user's connections. `null` disables. |
| `isAdmin` | `null` | `callable(EntityInterface $user): bool`, replaces the three settings above. |
| `identityResolver` | `null` | `callable(ServerRequest $request): string\|int\|null`, how to find the signed-in user id. By default the `identity` request attribute (cakephp/authentication, CakeDC/Users) and then the session key below are tried. |
| `sessionUserKey` | `Auth.User.id` | Session key of the user id for sites on the old `AuthComponent`. |
| `loginUrl` / `loginRedirectParam` | `/login` / `redirect` | Where anonymous visitors are sent, and the query parameter that brings them back. |
| `allowedRedirectHosts` | `claude.ai`, `claude.com`, `chatgpt.com` | https hosts a client may register as redirect URIs. `[]` allows any https host. |
| `allowLoopbackRedirects` | `true` | Allow `http://localhost`, `127.0.0.1` and `[::1]` redirect URIs (Claude Code, Codex), on any port. |
| `allowedOrigins` | `[]` | Browser origins, besides the site itself, allowed to call `/mcp`. |
| `handler` | `null` | The site's MCP server (see below). `null` serves the built-in `whoami` server. |
| `accessTokenTtl` / `refreshTokenTtl` / `codeTtl` / `consentTtl` | 3600 / 2592000 / 600 / 600 | Lifetimes in seconds. |
| `logScope` | `cake_mcp` | Log scope of the plugin's audit lines. |

## Adding the site's tools

`/mcp` checks the bearer token, re-loads the user, and then hands the request to `handler`, any
PSR-15 `RequestHandlerInterface` (class name or instance) or a
`callable(ServerRequestInterface): ResponseInterface`. The caller is on the request:

```php
use CakeMcp\Server\McpContext;

$context = McpContext::fromRequest($request);
$context->user;        // the site's user entity, already confirmed as an administrator
$context->userId;
$context->clientName;  // "Claude", "ChatGPT", ...
$context->scopes;
```

The quickest way to add tools is to extend the built-in server, which already handles both the
current protocol (2026-07-28, stateless) and the older `initialize`-based versions:

```php
namespace App\Mcp;

use CakeMcp\Server\DiagnosticServer;
use CakeMcp\Server\McpContext;

class SiteMcpServer extends DiagnosticServer
{
    protected function tools(): array
    {
        return array_merge(parent::tools(), [[
            'name' => 'count_members',
            'description' => 'Number of active members.',
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            'annotations' => ['readOnlyHint' => true],
        ]]);
    }

    protected function callTool(string $name, array $arguments, ?McpContext $context): ?array
    {
        if ($name === 'count_members') {
            $count = \Cake\ORM\TableRegistry::getTableLocator()->get('Users')->find()->where(['active' => 1])->count();

            return ['content' => [['type' => 'text', 'text' => (string)$count]]];
        }

        return parent::callTool($name, $arguments, $context);
    }
}
```

```php
'CakeMcp' => ['handler' => \App\Mcp\SiteMcpServer::class],
```

A site that wants a full MCP implementation can use the official
[`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk) as the handler instead and read the
caller from `McpContext`.

## Client behaviour

What the plugin is built and tested against (`tests/TestCase/Integration/ClientFlowsTest.php`):

| Client | Registration | Redirect | Scopes it asks for |
|---|---|---|---|
| Claude (web, desktop, mobile) | Dynamic registration | `https://claude.ai/api/mcp/auth_callback` (may move to `claude.com`) | the MCP scope and `offline_access` |
| ChatGPT | Dynamic registration when metadata documents are not offered; registers without a scope | `https://chatgpt.com/connector_platform_oauth_redirect` | `openid email offline_access <mcp>` |
| Claude Code | Dynamic registration when metadata documents are not offered | `http://localhost:<port>/callback`, port chosen per login | none (uses what the server advertises) |
| Codex | Dynamic registration when metadata documents are not offered | loopback callback on a chosen port | `openid offline_access <mcp> email profile` |

Rules that follow from this:

- **Scopes are never refused.** The MCP scope is always granted, `offline_access` is granted
  when asked, and `openid`/`email`/`profile` are dropped. Allowed scopes are checked against the
  current configuration, not copied into each registration, so later changes apply to existing
  clients.
- **Refresh tokens are always issued** and a refresh may repeat the original scope list.
- **Loopback redirects match on any port** (RFC 8252 section 7.3).
- **Client ID Metadata Documents are not offered yet.** The 2026-07-28 MCP revision prefers them
  over registration, which it deprecates but keeps; all four clients fall back to registration
  when the server does not advertise `client_id_metadata_document_supported`.
- The plugin does not issue OpenID Connect ID tokens.

## Operations

```bash
bin/cake mcp clients                   # registered clients and their active approvals
bin/cake mcp clients --revoke <id>     # revoke every approval of one client
bin/cake mcp purge                     # delete expired tokens and unused registrations
```

Registration also purges on the fly, so the purge command is optional.

Every step logs one line under the `cake_mcp` scope, with the client, user and outcome but never a
code, token or secret, for example:

```
[cake_mcp] register client=mcpc_... name=ChatGPT redirect_uris=https://chatgpt.com/connector_platform_oauth_redirect auth_method=none
[cake_mcp] authorize consent shown client=mcpc_... user=1 scope_requested="openid email offline_access mcp" scope_dropped="openid email"
[cake_mcp] authorize approved client=mcpc_... user=1 grant=12 scope="mcp offline_access"
[cake_mcp] token issued grant_type=authorization_code client=mcpc_... user=1 grant=12
[cake_mcp] mcp refused reason="token expired"
```

To send them to their own file, add a log config with `'scopes' => ['cake_mcp']`.

## Security notes

- Only administrators can approve a connection, exchange a code, refresh, or call `/mcp`; the
  check reads the users table each time.
- Codes are single use; reusing one revokes the approval. Refresh tokens rotate; reusing one
  revokes the approval.
- Tokens issued here only work at this site's `/mcp`, and `/mcp` accepts no other tokens.
- Problems with `client_id` or `redirect_uri` are shown on the page, never redirected.
- The approval form is bound to the browser session, single use, rejects cross-site `Origin`s,
  and also carries the site's CSRF token when the site uses CSRF protection.
- A new approval replaces the same user's earlier approval for the same client.

## Tests

```bash
composer install
vendor/bin/phpunit
```

The suite runs the full flows above against a throwaway SQLite database with the plugin's real
migration.

## License

MIT
