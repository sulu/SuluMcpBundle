<h1 align="center">SuluMcpBundle</h1>

<p align="center">
    <a href="https://sulu.io/" target="_blank">
        <img width="30%" src="https://sulu.io/uploads/media/800x/00/230-Official%20Bundle%20Seal.svg?v=2-6&inline=1" alt="Official Sulu Bundle Badge">
    </a>
</p>

<p align="center">
    <a href="LICENSE" target="_blank">
        <img src="https://img.shields.io/github/license/sulu/SuluMcpBundle.svg" alt="GitHub license">
    </a>
    <a href="https://github.com/sulu/SuluMcpBundle/releases" target="_blank">
        <img src="https://img.shields.io/github/tag/sulu/SuluMcpBundle.svg" alt="GitHub tag (latest SemVer)">
    </a>
    <a href="https://github.com/sulu/SuluMcpBundle/actions" target="_blank">
        <img src="https://img.shields.io/github/actions/workflow/status/sulu/SuluMcpBundle/test-application.yaml" alt="Test workflow status">
    </a>
    <a href="https://github.com/sulu/sulu/releases" target="_blank">
        <img src="https://img.shields.io/badge/sulu%20compatibility-%3E=3.0-52b6ca.svg" alt="Sulu compatibility">
    </a>
</p>
<br/>

The **SuluMcpBundle** turns a [Sulu](https://sulu.io/) installation into a
[Model Context Protocol](https://modelcontextprotocol.io) server. AI assistants connect over Streamable HTTP and
**create pages, edit articles, manage media and publish content** through the same operations the administration
interface uses. Every request runs as the **authenticated Sulu user**, so an operation that is denied in the
administration interface is denied over MCP as well. There is no separate authentication layer and no privilege
escalation.

## 🚀&nbsp; Installation and Documentation

```bash
composer require sulu/mcp-bundle
```

Register the bundle in `config/bundles.php`, along with its two required dependencies. `league/oauth2-server-bundle`
registers itself automatically if you use Symfony Flex, while `symfony/mcp-bundle` has no Flex recipe yet and always
needs the manual entry:

```php
return [
    // ...
    Symfony\AI\McpBundle\McpBundle::class => ['all' => true],
    League\Bundle\OAuth2ServerBundle\LeagueOAuth2ServerBundle::class => ['all' => true],
    Sulu\Mcp\Infrastructure\Symfony\HttpKernel\SuluMcpBundle::class => ['all' => true],
];
```

Import the routes in `config/routes.yaml`. The `mcp` entry registers the MCP transport endpoint provided by
`symfony/mcp-bundle`. This bundle ships its OAuth endpoints in two files: the admin ones take the same prefix your
project already uses for the rest of the Sulu admin, and the RFC 8414/9728 discovery documents stay unprefixed in the
host's `/.well-known/` namespace:

```yaml
mcp:
    resource: .
    type: mcp

sulu_mcp_admin:
    resource: '@SuluMcpBundle/config/routing_admin.yaml'
    prefix: /admin

sulu_mcp_website:
    resource: '@SuluMcpBundle/config/routing_website.yaml'
```

Generate the RSA key pair that `league/oauth2-server-bundle` signs its tokens with. Skipping this step leaves every
MCP request failing with `Invalid key supplied`:

```bash
mkdir -p config/jwt
openssl genrsa -aes128 -out config/jwt/private.pem 4096
openssl rsa -in config/jwt/private.pem -pubout -out config/jwt/public.pem
```

Both commands prompt for the passphrase, so it stays out of your shell history.

Keep both keys out of version control, for example by adding `/config/jwt/*.pem` to your `.gitignore`.

Set the public server URL and the OAuth secrets in your environment. The passphrase has to match the one used above,
and the encryption key is any random string:

```bash
SULU_MCP_SERVER_URL=https://your-sulu-host.example.com
OAUTH_PRIVATE_KEY=%kernel.project_dir%/config/jwt/private.pem
OAUTH_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
OAUTH_PASSPHRASE=<passphrase>
OAUTH_ENCRYPTION_KEY=<random-string>
```

Configure `league/oauth2-server-bundle` in `config/packages/league_oauth2_server.yaml`. Its Flex recipe generates most
of the file; make sure the authorization-code and refresh-token grants MCP uses are enabled, the password and
implicit grants are explicitly off, and `scopes.default` is set, because league requires it and this bundle
deliberately leaves it to your project:

```yaml
league_oauth2_server:
    authorization_server:
        private_key: '%env(resolve:OAUTH_PRIVATE_KEY)%'
        private_key_passphrase: '%env(OAUTH_PASSPHRASE)%'
        encryption_key: '%env(OAUTH_ENCRYPTION_KEY)%'
        enable_auth_code_grant: true
        enable_refresh_token_grant: true
        enable_password_grant: false
        enable_implicit_grant: false

    resource_server:
        public_key: '%env(resolve:OAUTH_PUBLIC_KEY)%'

    scopes:
        # `available` is contributed by SuluMcpBundle
        default: ['mcp:tools', 'mcp:resources']

    persistence:
        doctrine: ~
```

Create the database tables. `league/oauth2-server-bundle` persists clients, authorization codes, access tokens and
refresh tokens through Doctrine:

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

The MCP endpoint then answers at `/admin/mcp`. The [`docs/`](docs/) directory documents the
[configuration reference](docs/configuration.md), the **required security setup**, and per-client connection guides for
[Claude.ai](docs/clients/claude-ai.md), [Claude Code](docs/clients/claude-code.md),
[Claude Cowork](docs/clients/claude-cowork.md), [ChatGPT](docs/clients/chatgpt.md) and [Codex](docs/clients/codex.md).

## 💡&nbsp; Key Concepts

### Permissions

The bundle adds no permission model of its own. Every tool declares the Sulu security context and permission type it
requires, a compile-time map is built from those declarations, and a central gate checks it before any tool runs.
Tools the current role cannot use are hidden from the tool listing, and calling one anyway returns a permission denial
rather than a missing-tool error.

### Dangerous tools

Tools with hard-to-reverse effects are **disabled by default** and enabled per category through the `dangerous_tools`
configuration. When a category is disabled its tools are removed from the container at compile time, so they never
appear to a client at all.

```yaml
# config/packages/sulu_mcp.yaml
sulu_mcp:
    server_url: '%env(SULU_MCP_SERVER_URL)%'
    dangerous_tools:
        delete: false        # sulu_content_delete, sulu_tag_delete, sulu_category_delete
        publish: false       # sulu_content_publish, sulu_content_unpublish, sulu_preview_link_revoke, sulu_page_move, sulu_page_reorder
        block_remove: false  # sulu_block_remove
```

### Available tools

37 tools spanning the core Sulu domains:

| Domain | Count | Examples |
|--------|-------|----------|
| Pages | 7 | `sulu_page_create`, `sulu_page_get`, `sulu_page_list`, `sulu_page_move`, `sulu_page_reorder`, `sulu_page_tree`, `sulu_page_update` |
| Blocks | 5 | `sulu_block_add`, `sulu_block_update`, `sulu_block_reorder`, `sulu_block_list`, `sulu_block_remove` |
| Articles | 4 | `sulu_article_create`, `sulu_article_update`, `sulu_article_get`, `sulu_article_list` |
| Snippets | 4 | `sulu_snippet_create`, `sulu_snippet_update`, `sulu_snippet_get`, `sulu_snippet_list` |
| Unified content | 3 | `sulu_content_delete`, `sulu_content_publish`, `sulu_content_unpublish` |
| Media | 3 | `sulu_media_list`, `sulu_media_get`, `sulu_media_update` |
| Taxonomy | 6 | `sulu_tag_*`, `sulu_category_*` |
| Preview | 2 | `sulu_preview_link_generate`, `sulu_preview_link_revoke` |
| Navigation | 1 | `sulu_navigation_get` |
| Contact | 1 | `sulu_contact_list` |
| Misc | 3 | `sulu_content_search`, `sulu_get_context`, `sulu_ping` |

The block and unified content tools operate on pages, articles and snippets alike through a `type` parameter.

### Authentication

Clients authenticate through OAuth 2.1 with Dynamic Client Registration, backed by `league/oauth2-server-bundle`.
Sulu opens the administration login when needed and then shows an explicit consent screen naming the client and the
requested scopes. Tokens are only issued once the user approves that screen.

For hosted clients, create an OAuth client up front:

```bash
bin/console sulu:mcp:create-client "Claude.ai Production"
```

## ❤️&nbsp; Support and Contributions

The Sulu content management system is a **community-driven open source project** backed by various partner companies.
We are committed to a fully transparent development process and **highly appreciate any contributions**.

Have a look at our [contribution guidelines](CONTRIBUTING.md) and the
[Sulu contribution documentation](https://docs.sulu.io/en/3.x/developer/contributing/index.html) before opening a pull
request. Security issues should be reported privately as described in [SECURITY.md](SECURITY.md).

## ✅&nbsp; Requirements

* PHP 8.2 or higher
* Symfony 7.3 or higher
* Sulu 3.0 or higher

Have a look at the `require` section in the [composer.json](composer.json) for an up-to-date list.

## 📘&nbsp; License

The Sulu content management system is released under the terms of the [MIT License](LICENSE).
