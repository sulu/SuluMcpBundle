# ChatGPT

Add your Sulu MCP server to ChatGPT as a **custom connector** (Developer mode / connectors, available on Business, Enterprise, and Pro plans). ChatGPT identifies itself with a Client ID Metadata Document, so there is no client to create on the Sulu side. If your workspace does not offer that flow, use the [manual client](#manual-client-fallback) below.

## 1. Add the connector in ChatGPT

Open the connector settings and add a custom MCP server. Depending on your plan and ChatGPT version, the menu is called **Connectors** or **Plugins**.

| Field | Value |
|-------|-------|
| Name | `Sulu` (or whatever you want) |
| MCP Server URL | `https://your-sulu-host.example.com/admin/mcp` |

Leave the OAuth client fields empty and connect. ChatGPT redirects to your Sulu login, you authenticate as the user the AI should act as, and Sulu shows a consent page that names the client and the scopes. After you approve, the connector is active.

ChatGPT's metadata document asks for `private_key_jwt` and also allows `none`. Sulu cannot verify a signature, so it stores ChatGPT as a public client with PKCE and ignores the document's keys.

## 2. (Recommended) Set the system prompt

Paste the contents of [`CONTENT_ASSISTANT_PROMPT.md`](../CONTENT_ASSISTANT_PROMPT.md) into the connector's instructions (or a Custom GPT's configuration). It teaches the model how to use the tools efficiently and respects your content guidelines.

## Manual client (fallback)

Use this when your workspace only offers the connector form with a client ID and secret. ChatGPT is then a confidential client: it stores a Client Secret and adds PKCE on top.

ChatGPT only reveals its OAuth callback URL **after** you save the connector with credentials, so the setup command stays open while you create the connector and then asks for the callback URL.

1. Start the setup. The command creates the client, prints the Client ID and Client Secret, and waits for the callback URL. Keep this terminal open, because the secret cannot be retrieved later.

    ```bash
    php bin/console sulu:mcp:create-client "ChatGPT" --client=chatgpt
    ```

2. In the connector form, enter the MCP server URL, the Client ID and the Client Secret, then save the connector.

3. ChatGPT now displays a callback URL containing a per-connector ID, for example `https://chatgpt.com/aip/<connector-id>/oauth/callback`. Paste it into the still-running command. The command saves it on the same OAuth client. Then click **Connect** in ChatGPT.

If you already know the callback URL, pass it directly:

```bash
php bin/console sulu:mcp:create-client "ChatGPT" --client=chatgpt \
    --redirect-uri="https://chatgpt.com/aip/<connector-id>/oauth/callback"
```

## Troubleshooting

- **`invalid_client` after Connect**: Sulu could not fetch or accept ChatGPT's metadata document. Check that the server can reach `chatgpt.com` over https and that `client_id_metadata_documents.enabled` is not `false`.
- **OAuth redirect mismatch with a manual client**: confirm the redirect URI you pasted exactly matches the callback ChatGPT shows (the `<connector-id>` must match). Re-run `sulu:mcp:create-client` with the correct callback and update the connector credentials.
- **Tools missing after connecting**: check `dangerous_tools.*` in `config/packages/sulu_mcp.yaml`. The delete, publish, block-remove and media-upload tools are off by default.
- **403 on every call** — the authenticated Sulu user lacks permission for that operation. Adjust roles in the Sulu admin.
