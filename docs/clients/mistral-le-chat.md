# Mistral Le Chat

Add your Sulu MCP server to Le Chat (`chat.mistral.ai`) as a **custom MCP connector**. There is no client to create on the Sulu side. Le Chat registers itself through Dynamic Client Registration at `/admin/mcp/register`.

## 1. Add the connector in Le Chat

Open the connector settings and add a custom MCP connector with your server URL:

| Field | Value |
|-------|-------|
| Name | `Sulu` (or whatever you want) |
| MCP Server URL | `https://your-sulu-host.example.com/admin/mcp` |

Leave any client ID and secret fields empty and connect. Le Chat registers a client named `mistral-mcp-client` with the redirect URI `https://callback.mistral.ai/v1/integrations_auth/oauth2_callback`. It then redirects to your Sulu login. You authenticate as the user the AI should act as, and Sulu shows a consent page that names the client and the scopes. After you approve, the connector is active.

## 2. (Recommended) Set the system prompt

Paste the contents of [`CONTENT_ASSISTANT_PROMPT.md`](../CONTENT_ASSISTANT_PROMPT.md) into your Le Chat instructions. It teaches the model how to use the tools efficiently and respects your content guidelines.

## Troubleshooting

- **Registration fails**: check that `dynamic_client_registration.enabled` is not `false` in `config/packages/sulu_mcp.yaml`. Le Chat needs the registration endpoint.
- **Authentication succeeds, then the server rejects the connection**: the transport refuses the `Host` header. Set `mcp.servers.sulu.http.allowed_hosts` (see [configuration](../configuration.md#allowed-hosts)).
- **Tools missing**: `dangerous_tools.*` defaults to `false`. Enable categories in `config/packages/sulu_mcp.yaml`.
- **403 on every call**: the authenticated Sulu user lacks permission for that operation. Adjust roles in the Sulu admin.
