# Claude.ai

Use the **Custom Connector** flow to add your Sulu MCP server to Claude.ai (Pro, Team, or Enterprise).

## 1. Create an OAuth client on the Sulu side

```bash
php bin/console sulu:mcp:create-client "Claude.ai"
```

The command's default redirect URI is already Claude.ai's callback (`https://claude.ai/api/mcp/auth_callback`). It prints a Client ID and Client Secret — copy them now, the secret cannot be retrieved later.

## 2. Add the connector in Claude.ai

Settings → **Connectors** → **Add Custom Connector**

| Field | Value |
|-------|-------|
| Name | `Sulu` (or whatever you want) |
| Remote MCP Server URL | `https://your-sulu-host.example.com/admin/mcp` |
| OAuth Client ID | from the previous step |
| OAuth Client Secret | from the previous step |

Save, then click **Connect** — Claude.ai will redirect to your Sulu login, you authenticate as the user the AI should act as, and the connector activates.

## 3. (Recommended) Set the system prompt

Paste the contents of [`CONTENT_ASSISTANT_PROMPT.md`](../CONTENT_ASSISTANT_PROMPT.md) into the Project's custom instructions or your conversation system prompt. It teaches the model how to use the tools efficiently and respects your content guidelines.

## Troubleshooting

- **OAuth redirect mismatch** — confirm the connector's redirect URI matches what `sulu:mcp:create-client` registered. Re-run the command with `--redirect-uri=...` if Claude.ai shows a different one.
- **Tools missing after connecting** — check `dangerous_tools.*` in `config/packages/sulu_mcp.yaml`; delete/publish/block-remove tools are off by default.
- **403 on every call** — the authenticated Sulu user lacks permission for that operation. Adjust roles in the Sulu admin.
