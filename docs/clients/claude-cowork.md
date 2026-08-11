# Claude Cowork

Claude Cowork is Anthropic's collaborative workspace. Setup mirrors [Claude.ai](claude-ai.md): a hosted client connecting to your Sulu MCP endpoint over OAuth.

## 1. Create an OAuth client on the Sulu side

Cowork uses its own callback URI, so override the default:

```bash
php bin/console sulu:mcp:create-client "Claude Cowork" --client=claude-cowork \
    --redirect-uri="<the callback URI Cowork shows in its connector setup screen>"
```

Copy the Client ID and Client Secret from the command output.

## 2. Add the connector in Cowork

In your workspace settings, add a **Custom MCP Server** (or **Custom Connector** — naming may vary):

| Field | Value |
|-------|-------|
| Server URL | `https://your-sulu-host.example.com/admin/mcp` |
| OAuth Client ID | from step 1 |
| OAuth Client Secret | from step 1 |

Save and connect — Cowork will redirect to your Sulu login. The authenticated user becomes the principal for every MCP call from that workspace.

## 3. (Recommended) Set the system prompt

Add the contents of [`CONTENT_ASSISTANT_PROMPT.md`](../CONTENT_ASSISTANT_PROMPT.md) to the workspace's shared instructions so every team member benefits from the Sulu-aware behaviour.

## Notes

- Workspace members all share the same OAuth client, but the Sulu user (and therefore permissions) is whichever account each member authenticates with.
- For a per-member identity, give each user their own OAuth client and have them connect individually. For a shared service identity, one OAuth client + a dedicated Sulu user is fine.
