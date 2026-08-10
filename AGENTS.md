# AGENTS.md — Read first

## Identity

Sulu MCP Bundle — a Symfony bundle for Sulu 3.x that exposes content management as MCP tools over Streamable HTTP. AI assistants (Claude.ai, Claude Code, Claude Cowork, …) read, create, edit, and publish Sulu content using the authenticated user's existing roles and permissions.

This is a library, not an application — no Docker local dev setup.

User-facing docs: `README.md`, `docs/`.

## Constraints

- **Tech stack** — PHP 8.2+, Symfony 7.3+, Sulu 3.x. No runtime dependencies beyond `composer.json`.
- **Auth** — Sulu's native user system only. No separate auth layer.
- **Transport** — Streamable HTTP. The legacy HTTP+SSE transport is not used.
- **Permissions** — every operation runs under the authenticated Sulu user. Operations the user cannot perform in the admin UI must also fail via MCP.

## Console

Use `php bin/console` in projects that install this bundle.
Use `vendor/bin/phpunit` directly for tests in this repo.

## Mandatory workflow (order required)

1. `composer fix`
2. `composer lint`
3. `composer test`

Never skip `fix`.

## Core workflow

Start every feature with:
"Let me research the codebase and create a plan before implementing."

1. **Research** — understand existing patterns and architecture.
2. **Plan** — propose approach and confirm.
3. **Implement** — build with tests and error handling.
4. **Validate** — ALWAYS run formatters, linters, and tests.

## Behavioural guidelines

These reduce common LLM coding mistakes. They bias toward caution; for trivial tasks, use judgement.

### Think before coding

- State assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them — don't pick silently.
- If a simpler approach exists, say so.
- If something is unclear, stop. Name what's confusing. Ask.

### Simplicity first

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

### Surgical changes

- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it — don't delete it.
- Remove imports/variables/functions that *your* changes orphaned. Don't remove pre-existing dead code unless asked.

### Goal-driven execution

Transform tasks into verifiable goals:

- "Fix the bug" → "Write a test that reproduces it, then make it pass."
- "Refactor X" → "Ensure tests pass before and after."

For multi-step tasks, state a brief plan with verify steps before starting.

## Code organization

- Keep functions small and focused.
- If comments are needed to explain structure, split into functions.
- Group related functionality clearly.
- Prefer many small files over few large ones.

### Prefer explicit over implicit

- Clear names over clever abstractions.
- Obvious data flow over hidden magic.
- Direct dependencies over service locators.

## Output discipline

When proposing changes:

- Show minimal diffs only.
- List modified files explicitly.
- Do not refactor beyond the requested scope.
- Do not rewrite entire files unless necessary.

## Architecture discipline

- Tool classes must not access Doctrine or persistence directly — delegate to Sulu services (`ContentManagerInterface`, `MessageBusInterface`, `MediaManagerInterface`, `TagManagerInterface`, `CategoryManagerInterface`, …).
- Follow `rules/` without exception.

## Commit messages

- No conventional commit prefixes (`chore:`, `docs:`, `feat:`, `fix:`, …).
- Plain, descriptive messages.
- No AI attribution / co-author lines.

## Rules

- `rules/architecture.md`
- `rules/coding.md`
- `rules/commits.md`
- `rules/strict-mode.md`
- `rules/testing.md`
- `rules/tooling.md`

## Hard stop
If a request would violate architecture, strict-mode, or any rule in `rules/`: ask one precise clarification question before implementing. Do not guess.

<!-- BEGIN AI_MATE_INSTRUCTIONS -->
AI Mate Summary:
- Role: MCP-powered, project-aware coding guidance and tools.
- Required action: Read and follow `mate/AGENT_INSTRUCTIONS.md` before taking any action in this project, and prefer MCP tools over raw CLI commands whenever possible.
- Installed extensions: matesofmate/composer-extension, matesofmate/phpstan-extension, matesofmate/phpunit-extension, symfony/ai-mate, symfony/ai-monolog-mate-extension, symfony/ai-symfony-mate-extension.
<!-- END AI_MATE_INSTRUCTIONS -->
