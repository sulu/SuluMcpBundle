# Documentation

Sulu MCP Bundle is a Symfony bundle that turns a Sulu 3.x installation into an [MCP](https://modelcontextprotocol.io) server. AI assistants connect over HTTP and can read, create, edit, and publish content using the authenticated user's existing Sulu permissions.

## Contents

- [Configuration](configuration.md) — all bundle config options with examples.
- [Content Assistant Prompt](CONTENT_ASSISTANT_PROMPT.md) — recommended system prompt for AI clients.
- Client setup:
  - [Claude.ai](clients/claude-ai.md) — hosted web/desktop app, OAuth connector.
  - [Claude Code](clients/claude-code.md) — CLI, configured via `.mcp.json`.
  - [Claude Cowork](clients/claude-cowork.md) — collaborative workspace, OAuth connector.
  - [ChatGPT](clients/chatgpt.md) — custom connector, one-command manual OAuth setup.
  - [Codex](clients/codex.md) — CLI, public client over loopback OAuth.

## What it exposes

The bundle ships **40 tools** spanning the core Sulu domains:

- Pages, articles, snippets — full lifecycle (CRUD, publish/unpublish, blocks, SEO, excerpt).
- Media, taxonomy (tags, categories), contacts.
- Other bundles add content types through `ContentTypeExtensionInterface`.
- Preview links, navigation, content search, and a context tool that briefs the AI on the current Sulu instance.

A handful of high-impact tools (delete, publish, block-remove, media-upload) are gated behind opt-in config — see the bundle [README](../README.md#configuration).

## Transport

[Streamable HTTP](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports) — a single endpoint at `/admin/mcp` that accepts JSON-RPC over HTTP POST and optionally streams responses with SSE. The `/admin/...` prefix routes the request into Sulu's admin kernel, where admin-context services (article preview provider, etc.) are registered. The legacy HTTP+SSE transport is not used.

## Authentication

OAuth 2.1 with Client ID Metadata Documents (CIMD) and Dynamic Client Registration, backed by `league/oauth2-server-bundle`. Once a client completes the OAuth flow, every MCP request runs under that Sulu user — operations the user cannot perform in the admin UI also fail via MCP.

After login, Sulu shows an explicit authorization screen naming the client and requested scopes. The client only receives tokens when the Sulu user approves that screen.

For hosted clients (Claude.ai, Claude Cowork), create an OAuth client up front:

```bash
php bin/console sulu:mcp:create-client "Claude.ai Production"
```

The command prints the Client ID, Client Secret, and redirect URI to paste into the client's connector setup. Claude Code and Codex use Dynamic Client Registration and skip this step. ChatGPT's manual connector reveals its callback URL only after the connector is saved, so `sulu:mcp:create-client --client=chatgpt` keeps running and asks you to paste the callback URL before it finishes.

### Client ID Metadata Documents (CIMD)

A client may send an `https://` URL as its `client_id`. The server fetches the JSON document at that URL, which names the client and lists its redirect URIs, and treats the client as a public client (PKCE required, no secret). Nothing needs to be set up on the Sulu side. The authorization-server metadata advertises `client_id_metadata_document_supported`.

The document is only trusted to describe the client, not to prove who runs it, so the consent screen shows the name together with the host of the URL and marks it as *not verified*. Check the host before you approve.

The document must be served as `application/json` from a public host, at most 16 KB, without redirects. Its `client_id` must equal the URL it was fetched from, and `client_name` and `redirect_uris` are required. It may not declare a client secret or another authentication method than `none`. Documents are cached according to their `Cache-Control` header, between 5 minutes and 24 hours, and a failed fetch is remembered for a minute.

CIMD is on by default; set `sulu_mcp.client_id_metadata_documents.enabled: false` to switch it off. Dynamic Client Registration and clients created with `sulu:mcp:create-client` are unaffected.
