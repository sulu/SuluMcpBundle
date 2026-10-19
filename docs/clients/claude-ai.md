# Claude.ai

Use the **Custom Connector** flow to add your Sulu MCP server to Claude.ai (Pro, Team, or Enterprise). There is no client to create on the Sulu side. Claude.ai identifies itself with a Client ID Metadata Document, and Sulu fetches it when you connect.

## 1. Add the connector in Claude.ai

Settings → **Connectors** → **Add Custom Connector**

| Field | Value |
|-------|-------|
| Name | `Sulu` (or whatever you want) |
| Remote MCP Server URL | `https://your-sulu-host.example.com/admin/mcp` |

Leave the OAuth fields empty. Save, then click **Connect**. Claude.ai redirects to your Sulu login, you authenticate as the user the AI should act as, and Sulu shows a consent page that names the client and the scopes. After you approve, the connector is active.

## 2. (Recommended) Set the system prompt

Paste the contents of [`CONTENT_ASSISTANT_PROMPT.md`](../CONTENT_ASSISTANT_PROMPT.md) into the Project's custom instructions or your conversation system prompt. It teaches the model how to use the tools efficiently and respects your content guidelines.

## Fixed client (optional)

If you turned off `client_id_metadata_documents`, or you want a client with a secret, create one on the Sulu side:

```bash
php bin/console sulu:mcp:create-client "Claude.ai" --client=claude-ai
```

The command's default redirect URI is Claude.ai's callback (`https://claude.ai/api/mcp/auth_callback`). It prints a Client ID and Client Secret. Copy them now, the secret cannot be retrieved later. Enter both in the connector's OAuth fields.

## Troubleshooting

- **`invalid_client` after Connect**: Sulu could not fetch or accept Claude.ai's metadata document. Check that the server can reach `claude.ai` over https and that `client_id_metadata_documents.enabled` is not `false`.
- **OAuth redirect mismatch with a fixed client**: confirm the connector's redirect URI matches what `sulu:mcp:create-client` registered. Re-run the command with `--redirect-uri=...` if Claude.ai shows a different one.
- **Tools missing after connecting**: check `dangerous_tools.*` in `config/packages/sulu_mcp.yaml`. The delete, publish, block-remove and media-upload tools are off by default.
- **403 on every call** — the authenticated Sulu user lacks permission for that operation. Adjust roles in the Sulu admin.
