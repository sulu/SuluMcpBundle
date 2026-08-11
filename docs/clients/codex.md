# Codex

OpenAI Codex (the coding agent / CLI) connects to remote MCP servers as a **public, native client**: it uses PKCE with no client secret and a loopback redirect URI (`http://localhost:<port>/callback`). The server supports public clients and HTTP loopback callbacks, so Codex onboards through the browser OAuth flow with **no manual client setup**.

## 1. Add the server to Codex

Register the Sulu MCP server in Codex's MCP configuration (see OpenAI's Codex MCP docs for the exact config file and syntax for your version):

```toml
[mcp_servers.sulu]
url = "https://your-sulu-host.example.com/admin/mcp"
```

## 2. Authenticate

On first use, Codex opens the OAuth flow in your browser. It registers dynamically and uses a loopback callback, so you don't pre-provision anything on the Sulu side. Log in to Sulu, approve the scopes, and Codex stores the token. Every MCP request then runs under that Sulu user's permissions.

If your Codex version pins the callback port, make sure the loopback host stays `localhost`/`127.0.0.1` — the server only accepts HTTP redirect URIs on loopback hosts.

## 3. (Recommended) Set the system prompt

Add the contents of [`CONTENT_ASSISTANT_PROMPT.md`](../CONTENT_ASSISTANT_PROMPT.md) to your project instructions so every Codex session picks up the Sulu-aware behaviour.

## Troubleshooting

- **Browser flow doesn't complete** — confirm the server URL is reachable and ends in `/admin/mcp`; check the Symfony log for OAuth errors.
- **`invalid redirect URI`** — Codex must use an `http` loopback callback (`localhost`/`127.0.0.1`) or an `https` URL. Other hosts are rejected.
- **Tools missing** — `dangerous_tools.*` defaults to `false`. Enable categories in `config/packages/sulu_mcp.yaml`.
