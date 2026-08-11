# Configuration

All bundle configuration lives under the `sulu_mcp` key in `config/packages/sulu_mcp.yaml`. Only `server_url` is required.

## Full reference

```yaml
sulu_mcp:
    # REQUIRED. Public base URL of the Sulu installation. Used for OAuth issuer
    # metadata and generating absolute callback URLs.
    server_url: '%env(SULU_MCP_SERVER_URL)%'

    # MCP endpoint path. Default: /admin/mcp
    mcp_path: '/admin/mcp'

    oauth:
        # Access token lifetime in seconds. Default: 3600 (1 hour).
        access_token_ttl: 3600
        # Refresh token lifetime in seconds. Default: 2592000 (30 days).
        refresh_token_ttl: 2592000
        # OAuth scopes advertised by the server.
        scopes:
            - 'mcp:tools'
            - 'mcp:resources'

    # Opt-in flags for tools with hard-to-reverse side effects.
    # All categories default to false.
    dangerous_tools:
        delete: false        # sulu_content_delete, sulu_tag_delete, sulu_category_delete
        publish: false       # sulu_content_publish, sulu_content_unpublish, sulu_preview_link_revoke
        block_remove: false  # sulu_block_remove
```

## Settings

### `server_url` (required)

The publicly reachable base URL of your Sulu installation, e.g. `https://sulu.example.com`. The bundle uses it to advertise OAuth endpoints and to compose the MCP server URL printed by `sulu:mcp:create-client`.

Use an env var so it differs per environment:

```bash
# .env.local / .env.prod
SULU_MCP_SERVER_URL=https://sulu.example.com
```

### `mcp_path`

The HTTP path serving MCP requests. Default `/admin/mcp`. The `/admin/...` prefix routes the request into Sulu's admin kernel via the standard front-controller mapping, so admin-context services (article preview provider, etc.) are available to the tools. Unlike the routes below, this one is registered by `symfony/mcp-bundle` and so cannot pick up a route import prefix -- it is a full path and has to be kept in sync with the prefix you mount `routing_admin.yaml` under.

If you change `mcp_path`, also update the `pattern` of the `mcp` firewall in your `security.yaml` (see "Required security setup" below) and the URL registered with each MCP client.

## Routes

The bundle ships its routes in two files, because the two halves are mounted differently. `routing_admin.yaml` carries the OAuth endpoints and takes whichever prefix your project already uses for the rest of the Sulu admin, exactly like every other Sulu bundle. `routing_website.yaml` carries the discovery documents, which RFC 8414 and RFC 9728 pin to the host root and which must therefore never be prefixed.

```yaml
# config/routes.yaml
sulu_mcp_admin:
    resource: '@SuluMcpBundle/config/routing_admin.yaml'
    prefix: /admin

sulu_mcp_website:
    resource: '@SuluMcpBundle/config/routing_website.yaml'
```

No path inside the bundle hardcodes the prefix, and the code that needs to know a route's URL generates it from the router. The table below assumes the conventional `/admin` prefix.

Keep both imports in a file that is loaded in **every** Sulu context, as above. The discovery documents are served from the host root and therefore run in the website kernel, but they advertise the OAuth endpoints from `routing_admin.yaml` and generate those URLs from the router. Splitting the two imports into context-specific route files leaves the website kernel without the admin route definitions, and the discovery document fails with a `RouteNotFoundException`.

| Path | Purpose | Authentication |
|---|---|---|
| `/admin/mcp` | MCP transport (JSON-RPC), configurable via `mcp_path` | OAuth bearer token |
| `/admin/mcp/authorize` | OAuth authorization endpoint | Sulu admin session |
| `/admin/mcp/consent/{requestId}` | Consent screen backend (`GET` details, `POST` decision) | Sulu admin session |
| `/admin/mcp/token` | OAuth token endpoint | client credentials / PKCE |
| `/admin/mcp/register` | RFC 7591 dynamic client registration | public |
| `/.well-known/oauth-protected-resource` | RFC 9728 discovery | public |
| `/.well-known/oauth-authorization-server` | RFC 8414 discovery | public |

Clients discover `authorize`, `token` and `register` from the authorization-server document, so they need no manual configuration beyond the server URL.

## Required security setup

The MCP endpoint lives under `/admin/mcp` so its requests reach the admin kernel. Sulu's standard `admin` firewall has the pattern `^/admin(\/|$)`, which also matches the MCP paths -- Symfony applies the *first* firewall whose pattern matches, in declaration order. The MCP firewall therefore must be declared **before** the admin firewall in your `config/packages/security.yaml`:

```yaml
security:
    firewalls:
        # ...any "dev" or static-asset firewalls...
        mcp:
            pattern: ^/admin/mcp/?$
            provider: sulu                 # or whichever provider authenticates Sulu users
            stateless: true
            entry_point: sulu_mcp.authentication_entry_point
            oauth2: true
        admin:
            pattern: ^/admin(\/|$)
            # ...existing admin firewall config...

    access_control:
        # Allow the OAuth discovery, registration and token endpoints through
        # without a session. They authenticate the client themselves.
        - { path: ^/\.well-known/oauth-, roles: PUBLIC_ACCESS }
        - { path: ^/admin/mcp/register$, roles: PUBLIC_ACCESS }
        - { path: ^/admin/mcp/token$, roles: PUBLIC_ACCESS }
        # Require a valid OAuth bearer on the MCP endpoint itself.
        - { path: ^/admin/mcp/?$, roles: IS_AUTHENTICATED_FULLY }
        # ...your existing admin rules...
```

The `mcp` pattern is anchored to the transport path alone. `/admin/mcp/authorize` and `/admin/mcp/consent/...` need the logged-in Sulu user, so they must fall through to the `admin` firewall -- an unanchored `^/admin/mcp` would put them behind the stateless OAuth firewall and break the consent flow. They are covered by your existing `^/admin` access-control rule, and the three public entries above have to precede it.

This setup keeps the MCP traffic stateless (no PHP session cookies), isolated from your form-login / two-factor / HTTP-basic flows on `/admin/...`, and works alongside any extra middleware your host project layers onto the admin firewall.

### `oauth.access_token_ttl` / `oauth.refresh_token_ttl`

Token lifetimes in seconds. The defaults (1 hour / 30 days) match common hosted-client expectations. Shorter access tokens reduce blast radius on leak; longer refresh tokens reduce re-login friction.

### `oauth.scopes`

The scopes the server advertises and accepts. The two defaults map to MCP semantics:

- `mcp:tools` — call tools (`tools/list`, `tools/call`).
- `mcp:resources` — read resources.

You don't normally change this. Add scopes only if you've extended the bundle with custom OAuth grants.

During OAuth authorization, users authenticate with the normal Sulu admin login and then approve or deny the client on a Sulu admin consent screen. The consent screen uses the scopes configured here and the authenticated user's existing Sulu permissions; there is no separate MCP user or permission layer.

### `dangerous_tools.*`

Three booleans gating high-impact tools. Each flag is independent — enable only what you need.

| Flag | Tools enabled when `true` |
|------|---------------------------|
| `delete` | `sulu_content_delete` (page/article/snippet via `type`), `sulu_tag_delete`, `sulu_category_delete` |
| `publish` | `sulu_content_publish` (page/article/snippet via `type`), `sulu_content_unpublish` (page/article/snippet via `type`), `sulu_preview_link_revoke` |
| `block_remove` | `sulu_block_remove` |

When a flag is `false`, the corresponding tool services are removed from the container at compile time — they don't appear in MCP `tools/list` and calls fail with "unknown tool" rather than running with an error. To change a flag, edit the YAML and clear the cache (`bin/console cache:clear`).

## Recommended profiles

**Read-only / staging** — leave `dangerous_tools` at defaults. The AI can read everything and create drafts, but cannot publish or delete.

```yaml
sulu_mcp:
    server_url: '%env(SULU_MCP_SERVER_URL)%'
```

**Editorial workflow** — let the AI publish but not delete:

```yaml
sulu_mcp:
    server_url: '%env(SULU_MCP_SERVER_URL)%'
    dangerous_tools:
        publish: true
```

**Full agent control** — only on accounts you trust to act autonomously:

```yaml
sulu_mcp:
    server_url: '%env(SULU_MCP_SERVER_URL)%'
    dangerous_tools:
        delete: true
        publish: true
        block_remove: true
```

## Verifying

After changing config, clear the cache and inspect the registered MCP tools:

```bash
bin/console cache:clear
bin/console debug:container --tag=mcp.tool
```

The list reflects the active `dangerous_tools` configuration.
