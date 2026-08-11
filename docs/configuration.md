# Configuration

All bundle configuration lives under the `sulu_mcp` key in `config/packages/sulu_mcp.yaml`. Only `server_url` is required.

## Full reference

```yaml
sulu_mcp:
    # REQUIRED. Public base URL of the Sulu installation. Used for OAuth issuer
    # metadata and generating absolute callback URLs.
    server_url: '%env(SULU_MCP_SERVER_URL)%'

    # MCP endpoint path. Default: /admin/_mcp
    mcp_path: '/admin/_mcp'

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

The HTTP path serving MCP requests. Default `/admin/_mcp`. The `/admin/...` prefix routes the request into Sulu's admin kernel via the standard front-controller mapping, so admin-context services (article preview provider, etc.) are available to the tools. Change it only if you need to avoid a route collision; keep the `/admin/` prefix unless you've explicitly routed a different path to the admin kernel. Clients must use the same path.

If you change `mcp_path`, also update the `pattern` of the `mcp` firewall in your `security.yaml` (see "Required security setup" below) and the URL registered with each MCP client. The OAuth endpoints below keep their fixed `/admin/_mcp/...` paths either way -- only the transport path moves.

## Routes

Every route of this bundle lives under the `_mcp` namespace. The underscore follows the Symfony/Sulu convention for framework-owned paths (`_profiler`, `_wdt`, Sulu's `/admin/p`) and keeps the bundle from colliding with a project's own `/mcp` pages.

| Path | Purpose | Authentication |
|---|---|---|
| `/admin/_mcp` | MCP transport (JSON-RPC), configurable via `mcp_path` | OAuth bearer token |
| `/admin/_mcp/authorize` | OAuth authorization endpoint | Sulu admin session |
| `/admin/_mcp/consent/{requestId}` | Consent screen backend (`GET` details, `POST` decision) | Sulu admin session |
| `/admin/_mcp/token` | OAuth token endpoint | client credentials / PKCE |
| `/admin/_mcp/register` | RFC 7591 dynamic client registration | public |
| `/.well-known/oauth-protected-resource` | RFC 9728 discovery | public |
| `/.well-known/oauth-authorization-server` | RFC 8414 discovery | public |

The two `.well-known` documents are the only routes outside the namespace -- RFC 8414 and RFC 9728 pin them to the host root. Clients discover `authorize`, `token` and `register` from the authorization-server document, so they need no manual configuration beyond the server URL.

## Required security setup

The MCP endpoint lives under `/admin/_mcp` so its requests reach the admin kernel. Sulu's standard `admin` firewall has the pattern `^/admin(\/|$)`, which also matches the MCP paths -- Symfony applies the *first* firewall whose pattern matches, in declaration order. The MCP firewalls therefore must be declared **before** the admin firewall in your `config/packages/security.yaml`:

```yaml
security:
    firewalls:
        # ...any "dev" or static-asset firewalls...
        # The token and registration endpoints authenticate the client themselves
        # (client secret / PKCE), so Symfony security stays out of the way.
        mcp_public:
            pattern: ^/admin/_mcp/(token|register)$
            security: false
        mcp:
            pattern: ^/admin/_mcp/?$
            provider: sulu                 # or whichever provider authenticates Sulu users
            stateless: true
            entry_point: sulu_mcp.authentication_entry_point
            oauth2: true
        admin:
            pattern: ^/admin(\/|$)
            # ...existing admin firewall config...

    access_control:
        # Allow the OAuth discovery documents through without a session.
        - { path: ^/\.well-known/oauth-, roles: PUBLIC_ACCESS }
        # Require a valid OAuth bearer on the MCP endpoint itself.
        - { path: ^/admin/_mcp/?$, roles: IS_AUTHENTICATED_FULLY }
        # ...your existing admin rules...
```

The `mcp` pattern is anchored to the transport path alone. `/admin/_mcp/authorize` and `/admin/_mcp/consent/...` need the logged-in Sulu user, so they must fall through to the `admin` firewall -- an unanchored `^/admin/_mcp` would put them behind the stateless OAuth firewall and break the consent flow. They are covered by your existing `^/admin` access-control rule.

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
