# Codex

OpenAI Codex (the coding agent / CLI) connects to remote MCP servers as a **public, native client**: it uses PKCE with no client secret and a loopback redirect URI (`http://127.0.0.1:<port>/callback/<id>`). Codex identifies itself with a Client ID Metadata Document, so it onboards through the browser OAuth flow with **no manual client setup**.

## 1. Add the server to Codex

Let Codex write the config and start the login:

```bash
codex mcp add sulu --url https://your-sulu-host.example.com/admin/mcp
```

`codex mcp add` detects OAuth support and opens the authorization URL in your browser right away. Log in to Sulu, approve the scopes, and Codex stores the token. Every MCP request then runs under that Sulu user's permissions.

Or register the server by hand in Codex's MCP configuration (see OpenAI's Codex MCP docs for the exact config file and syntax for your version):

```toml
[mcp_servers.sulu]
url = "https://your-sulu-host.example.com/admin/mcp"
```

Start the login for a server added this way with:

```bash
codex mcp login sulu
```

## 2. (Recommended) Set the system prompt

Add the contents of [`CONTENT_ASSISTANT_PROMPT.md`](../CONTENT_ASSISTANT_PROMPT.md) to your project instructions so every Codex session picks up the Sulu-aware behaviour.

## Troubleshooting

- **Browser flow doesn't complete** — confirm the server URL is reachable and ends in `/admin/mcp`. Check the Symfony log for OAuth errors.
- **`invalid_client`**: Sulu could not fetch or accept Codex's metadata document, or the redirect URI does not match it. The server must reach `chatgpt.com` over https. Codex has to use an `http` loopback callback (`localhost` or `127.0.0.1`) or an `https` URL. Other hosts are rejected.
- **Tools missing** — `dangerous_tools.*` defaults to `false`. Enable categories in `config/packages/sulu_mcp.yaml`.
