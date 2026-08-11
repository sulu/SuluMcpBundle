# ChatGPT

Add your Sulu MCP server to ChatGPT as a **custom connector** (Developer mode / connectors, available on Business, Enterprise, and Pro plans). ChatGPT is a confidential client: it stores a Client Secret and adds PKCE on top.

ChatGPT only reveals its OAuth callback URL **after** you save the connector with credentials, so the setup command stays open while you create the connector and then asks for the callback URL.

## 1. Start the OAuth client setup

```bash
php bin/console sulu:mcp:create-client "ChatGPT" --client=chatgpt
```

The command creates the client, prints the Client ID and Client Secret, and waits for the callback URL. Keep this terminal open; the secret cannot be retrieved later.

## 2. Add the connector in ChatGPT

Settings → **Connectors** → **Create** (custom connector):

| Field | Value |
|-------|-------|
| Name | `Sulu` (or whatever you want) |
| MCP Server URL | `https://your-sulu-host.example.com/admin/mcp` |
| OAuth Client ID | from step 1 |
| OAuth Client Secret | from step 1 |

Save the connector.

## 3. Paste the callback URL

After saving, ChatGPT displays a callback URL containing a per-connector ID, e.g. `https://chatgpt.com/aip/<connector-id>/oauth/callback`. Paste that URL into the still-running `sulu:mcp:create-client` command. The command saves it on the same OAuth client.

Now click **Connect** in ChatGPT — it redirects to your Sulu login, you authenticate as the user the AI should act as, and the connector activates.

If you already know the callback URL, pass it directly:

```bash
php bin/console sulu:mcp:create-client "ChatGPT" --client=chatgpt \
    --redirect-uri="https://chatgpt.com/aip/<connector-id>/oauth/callback"
```

## 4. (Recommended) Set the system prompt

Paste the contents of [`CONTENT_ASSISTANT_PROMPT.md`](../CONTENT_ASSISTANT_PROMPT.md) into the connector's instructions (or a Custom GPT's configuration). It teaches the model how to use the tools efficiently and respects your content guidelines.

## Dynamic registration & CIMD

The steps above are the **manual** connector flow. The server also advertises OAuth Dynamic Client Registration (DCR) at its `.well-known` endpoints; if your ChatGPT account onboards connectors automatically via DCR, it registers itself (including its redirect URI) and steps 1 and 3 are unnecessary.

ChatGPT increasingly prefers **Client ID Metadata Documents (CIMD)** — the client identifies itself with a hosted metadata URL instead of a pre-registered client. **CIMD is not supported by this bundle yet** (tracked as a future enhancement). Until then, use the manual flow above, or DCR if your account offers it.

## Troubleshooting

- **OAuth redirect mismatch** — confirm the redirect URI you pasted exactly matches the callback ChatGPT shows (the `<connector-id>` must match). Re-run `sulu:mcp:create-client` with the correct callback and update the connector credentials.
- **Tools missing after connecting** — check `dangerous_tools.*` in `config/packages/sulu_mcp.yaml`; delete/publish/block-remove tools are off by default.
- **403 on every call** — the authenticated Sulu user lacks permission for that operation. Adjust roles in the Sulu admin.
