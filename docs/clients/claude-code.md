# Claude Code

Claude Code reads MCP server config from a `.mcp.json` in the project root (or `~/.claude.json` for global servers).

## 1. Add the server

The simplest option — let Claude Code write the config for you:

```bash
claude mcp add --transport http sulu https://your-sulu-host.example.com/admin/mcp
```

Or write `.mcp.json` by hand:

```json
{
  "mcpServers": {
    "sulu": {
      "type": "http",
      "url": "https://your-sulu-host.example.com/admin/mcp"
    }
  }
}
```

## 2. Authenticate

On first use, Claude Code triggers the OAuth flow in your browser via Dynamic Client Registration — no manual client setup needed. You log in to Sulu, approve the scopes, and Claude Code stores the token.

To force a fresh login:

```bash
claude mcp logout sulu
```

## 3. (Recommended) Set the system prompt

Add the contents of [`CONTENT_ASSISTANT_PROMPT.md`](../CONTENT_ASSISTANT_PROMPT.md) to your `CLAUDE.md` so every Claude Code session in the project picks it up.

## Troubleshooting

- **`Server failed to start`** — confirm the URL is reachable from your machine and ends in `/admin/mcp`. Check the Symfony log for OAuth/CORS errors.
- **Authentication succeeds, then the server rejects the connection** — the browser reports success and Claude Code reports rejected credentials. The transport is refusing the `Host` header, not the token: set `mcp.http.allowed_hosts` (see [configuration](../configuration.md#allowed-hosts)). Nothing is written to the Symfony log in this case; the response body is `Forbidden: Invalid Host header.`
- **Tools missing** — `dangerous_tools.*` defaults to `false`. Enable categories in `config/packages/sulu_mcp.yaml`.
- **Tokens expire after re-deploy** — clearing the OAuth tables on Sulu invalidates issued tokens; re-run `claude mcp logout sulu` and reconnect.
