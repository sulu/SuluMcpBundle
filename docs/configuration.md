# Configuration

All bundle configuration lives under the `sulu_mcp` key in `config/packages/sulu_mcp.yaml`. Only `server_url` is required.

## Full reference

```yaml
sulu_mcp:
    # REQUIRED. Public base URL of the Sulu installation. With mcp_path it forms
    # the OAuth issuer and resource identifier and prefixes the discovery URLs.
    server_url: '%env(SULU_MCP_SERVER_URL)%'

    # MCP endpoint path. Default: /admin/mcp
    mcp_path: '/admin/mcp'

    # Opt-in flags for tools with hard-to-reverse side effects.
    # All categories default to false.
    dangerous_tools:
        delete: false        # sulu_content_delete, sulu_tag_delete, sulu_category_delete
        publish: false       # sulu_content_publish, sulu_content_unpublish, sulu_preview_link_revoke, sulu_page_move, sulu_page_reorder
        block_remove: false  # sulu_block_remove
```

## Settings

### `server_url` (required)

The publicly reachable base URL of your Sulu installation, e.g. `https://sulu.example.com`. The bundle appends `mcp_path` to it to form the OAuth issuer and the resource identifier the discovery documents advertise, prefixes the advertised OAuth endpoints with it, and composes the MCP server URL printed by `sulu:mcp:create-client` from it. A trailing slash is trimmed.

Use an env var so it differs per environment:

```bash
# .env.local / .env.prod
SULU_MCP_SERVER_URL=https://sulu.example.com
```

### `mcp_path`

The HTTP path serving MCP requests. Default `/admin/mcp`. The `/admin/...` prefix routes the request into Sulu's admin kernel via the standard front-controller mapping, so admin-context services (article preview provider, etc.) are available to the tools. Unlike the routes below, this one is registered by `symfony/mcp-bundle` and so cannot pick up a route import prefix -- it is a full path and has to be kept in sync with the prefix you mount `routing_admin.yaml` under.

The value has to be a literal path: it starts with a `/`, does not end with one, and contains none of `{`, `}`, `%`, `?` or `#`. The container refuses anything else, because the endpoint's listeners match the request path against it for equality -- a route placeholder or a URI delimiter would let the MCP route match one path while the listeners compare another.

If you change `mcp_path`, also update the `pattern` of the `mcp` firewall in your `security.yaml` (see "Required security setup" below) and the URL registered with each MCP client.

## The `symfony/mcp-bundle` server

`symfony/mcp-bundle` serves named MCP servers, each with its own identity, endpoint and set of exposed capabilities. This bundle prepends one called `sulu`, exposing every registered tool and resource over HTTP at `mcp_path`, so everything that bundle leaves to the project is configured under `mcp.servers.sulu` -- `allowed_hosts` below, and the options with a working default (`pagination_limit`, `instructions`, `session`, the protocol revisions the server answers for) that `symfony/mcp-bundle` documents itself.

## Routes

The bundle ships its routes in two files, because the two halves are mounted differently. `routing_admin.yaml` carries the OAuth endpoints and takes whichever prefix your project already uses for the rest of the Sulu admin, exactly like every other Sulu bundle. `routing_website.yaml` carries the discovery documents, whose paths RFC 8414 and RFC 9728 anchor in the host's `/.well-known/` namespace and which must therefore never take a route prefix.

```yaml
# config/routes.yaml
sulu_mcp_admin:
    resource: '@SuluMcpBundle/config/routing_admin.yaml'
    prefix: /admin

sulu_mcp_website:
    resource: '@SuluMcpBundle/config/routing_website.yaml'
```

No path inside the bundle hardcodes the prefix, and the code that needs to know a route's URL generates it from the router. The table below assumes the conventional `/admin` prefix.

Keep both imports in a file that is loaded in **every** Sulu context, as above. The discovery documents are served unprefixed and therefore run in the website kernel, but they advertise the OAuth endpoints from `routing_admin.yaml` and generate those URLs from the router. Splitting the two imports into context-specific route files leaves the website kernel without the admin route definitions, and the discovery document fails with a `RouteNotFoundException`.

| Path | Purpose | Authentication |
|---|---|---|
| `/admin/mcp` | MCP transport (JSON-RPC), configurable via `mcp_path` | OAuth bearer token |
| `/admin/mcp/authorize` | OAuth authorization endpoint | Sulu admin session |
| `/admin/mcp/consent/{requestId}` | Consent screen backend (`GET` details, `POST` decision) | Sulu admin session |
| `/admin/mcp/token` | OAuth token endpoint | client credentials / PKCE |
| `/admin/mcp/register` | RFC 7591 dynamic client registration | public |
| `/.well-known/oauth-protected-resource/admin/mcp` | RFC 9728 discovery | public |
| `/.well-known/oauth-authorization-server/admin/mcp` | RFC 8414 discovery | public |

Clients discover `authorize`, `token` and `register` from the authorization-server document, so they need no manual configuration beyond the server URL.

Both discovery documents carry `mcp_path` in their own URL. RFC 9728 section 3 and RFC 8414 section 3.1 locate them by inserting the well-known URI between the host and the path component of the resource identifier and of the issuer, respectively -- here both are the MCP endpoint URL, `server_url` plus `mcp_path` -- and the MCP authorization specification requires clients to try exactly that location first for an issuer that has a path. Changing `mcp_path` moves both routes with it. The insertion rule exists so that one host can serve several protected resources and authorization servers; it keeps the bundle off the bare host-level well-known paths, which belong to the host project.

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

### OAuth scopes

The server advertises and accepts two fixed scopes:

- `mcp:tools` — call tools (`tools/list`, `tools/call`).
- `mcp:resources` — read resources.

They are contributed to `league_oauth2_server.scopes.available` so they can be granted. The bundle deliberately contributes nothing else to `league_oauth2_server`:

- **`scopes.default` is yours.** league applies it in exactly one place: `AddClientDefaultScopesListener` puts it on a client that is *saved without scopes* (`PRE_SAVE_CLIENT`). At authorize time an empty `scope` parameter falls back to the client's own scopes, not to `default`. Every client this bundle creates -- through the registration endpoint or `sulu:mcp:create-client` -- carries the MCP scopes, so `default` never affects an MCP client and belongs to the project. league still requires you to set it.
- **Token lifetimes and PKCE enforcement are yours.** Configure `access_token_ttl`, `refresh_token_ttl` and `require_code_challenge_for_public_clients` under `league_oauth2_server.authorization_server`. league's own defaults (1 hour, 1 month, PKCE required) apply if you don't.

During OAuth authorization, users authenticate with the normal Sulu admin login and then approve or deny the client on a Sulu admin consent screen. The consent screen shows these scopes and applies the authenticated user's existing Sulu permissions; there is no separate MCP user or permission layer.

#### Scope enforcement

On the MCP endpoint the bundle inspects every access token league issued: `tools/*` methods require `mcp:tools`, `resources/*` methods require `mcp:resources`, and anything else -- the handshake methods, the `GET` stream, the session `DELETE`, an unreadable body -- requires any one of the two scopes. A JSON-RPC batch requires every scope its members require. A token the firewall authenticated by other means carries no OAuth scopes and is not inspected.

A token that falls short is answered with `403`, the JSON-RPC error `-32003 Insufficient scope`, and a `WWW-Authenticate: Bearer error="insufficient_scope"` header whose `scope=` names the scopes the request needed -- both scopes in the any-scope case.

#### Coexisting with your own OAuth clients

league runs a single authorization server per application, so token lifetimes, grants and the client table are shared with everything else the project does with OAuth -- which is why the bundle configures none of them. The consent listener only handles the bundle's `sulu_mcp_oauth_authorize` route; if your project has its own `AUTHORIZATION_REQUEST_RESOLVE` listener, guard it by route the same way so neither resolves the other's authorization requests. The registration endpoint writes into that shared client table, only ever with the MCP scopes.

### `dangerous_tools.*`

Three booleans gating high-impact tools. Each flag is independent — enable only what you need.

| Flag | Tools enabled when `true` |
|------|---------------------------|
| `delete` | `sulu_content_delete` (page/article/snippet/product via `type`), `sulu_tag_delete`, `sulu_category_delete` |
| `publish` | `sulu_content_publish` (page/article/snippet/product via `type`), `sulu_content_unpublish` (page/article/snippet/product via `type`), `sulu_preview_link_revoke`, `sulu_page_move`, `sulu_page_reorder` |
| `block_remove` | `sulu_block_remove` |

When a flag is `false`, the corresponding tool services are removed from the container at compile time — they don't appear in MCP `tools/list` and calls fail with "unknown tool" rather than running with an error. To change a flag, edit the YAML and clear the cache (`bin/console cache:clear`).

### Allowed hosts

`symfony/mcp-bundle` wraps the transport in the MCP SDK's DNS rebinding protection. Left unconfigured it keeps the SDK default, which accepts only `localhost`, `127.0.0.1` and `[::1]`. A server reachable under its own domain therefore answers every client request with `403 Forbidden: Invalid Host header.` -- after the OAuth handshake has already succeeded, and without writing anything to the Symfony log. Clients report this as rejected credentials. Name the public host on the `sulu` server:

```yaml
# config/packages/sulu_mcp.yaml
mcp:
    servers:
        sulu:
            http:
                allowed_hosts:
                    - '%env(key:host:url:SULU_MCP_SERVER_URL)%'
                    - localhost
                    - 127.0.0.1
                    - '[::1]'
```

Deriving the first entry from `SULU_MCP_SERVER_URL` keeps it correct per environment, and the loopback entries keep local development working. A request that carries an `Origin` header is checked against the same list and rejected with `Forbidden: Invalid Origin header.` instead.

`allowed_hosts: false` switches the protection off entirely. Prefer naming the host: the protection is what stops a page in a developer's browser from steering that browser into a local MCP server.

## Recommended profiles

**Read-only / staging** — leave `dangerous_tools` at defaults. The AI can read everything and create drafts, but cannot publish, delete or restructure the page tree.

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

The list reflects the active `dangerous_tools` configuration. `bin/console debug:mcp --server=sulu` prints the same registry with handlers and descriptions, but filtered by what the current user may call -- on the CLI there is none, so it shows the two tools that need no permission.
