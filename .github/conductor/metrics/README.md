# Conductor metrics

Token usage, run duration, and phase breakdown for **V2 Conductor** (GHA) and **V1 UI**
(`cursor.com/agents` orchestrator) cloud-agent sessions.

## Layout

| Path | Purpose |
|------|---------|
| `index.json` | Lightweight index keyed by ROADMAP step ID → session UUIDs |
| `sessions/{sessionId}.json` | Full session: phases, tokens, agent/GHA links |

## Session ID

- **Conductor:** auto-generated (`crypto.randomUUID()`) on the first GHA job; passed through chained jobs via `session_id`.
- **V1 UI / Automations:** created by `import-manual-agents.mjs` (printed as `SESSION_ID=…`) when you import after the ticket. Pass `--session <uuid>` to append.

## When data is written

| Event | Action |
|-------|--------|
| Conductor start | Create session + index entry (`status: in_progress`) |
| After each Conductor cloud-agent phase | Append `phases[]`, recompute `totals`, commit |
| V1 UI **after** the Orchestrator ticket finishes | Maintainer or Automation runs `import-manual-agents.mjs --push` — Cursor usage settles only after the cloud agent ends |
| FINALIZE / plan-only done | `status: completed` |
| `fail()` / fatal error | `status: failed` |
| `conductor:stop` | `status: cancelled` |

Commits land on the unprotected **`conductor-metrics`** branch only — never on the feature-branch tip (PR CI / bot approval) and never direct to protected `develop` (Ruleset requires PRs, no Actions bypass). The dashboard refreshes via an explicit `pages-deploy.yml` dispatch on `develop` after each metrics push; the build overlays metrics from `conductor-metrics` (`GITHUB_TOKEN` does not re-trigger workflows by itself).

## V1 UI (`cursor.com/agents`) / Automations / GHA

The Orchestrator rule does **not** record metrics mid-run. After a V1 modernization PR merges into
`develop` with label **`v1-metrics`**, `.github/workflows/import-v1-metrics.yml` imports the parent
Orchestrator agent automatically (`import-manual-agents.mjs --push`).

Manual / local:

```bash
CURSOR_API_KEY=… node .github/conductor/import-manual-agents.mjs \
  --step 2.7 \
  --pr-url https://github.com/Shadesman5/pagekit/pull/123 \
  --branch feature/extension-safety-fault-isolation \
  --push
```

- Accepts a full `https://cursor.com/agents/bc-…` URL via `--agent` (path id = parent).
- **`--pr-url`** resolves the parent agent (Cursor `prUrl` filter, then branch match); skips zero-usage child agents.
- **Task child agents** (`?child-id=bc-…`) usually report **zero** usage via `/v1/agents/{id}/usage` — tokens roll up on the parent.
- Default tagging: `source: "v1-ui"`, phase `tokensSource: "cursor-api-v1"` (dashboard V1 badge). Use `--no-v1-ui` only for legacy historical imports.
- `--push` syncs/commits to `conductor-metrics` and dispatches `pages-deploy.yml`.
- Optional: `--issue`, `--task-slug`, `--title`, `--label`, `--session`, `--dry-run`, `--copy-local`.

When the Cursor API returns zero but the [Dashboard](https://cursor.com/dashboard) still shows usage, copy totals manually:

```bash
node .github/conductor/import-manual-agents.mjs \
  --step 2.1.1 \
  --agent bc-a96ebcf7-2c32-4061-8d40-61ba33f30778 \
  --tokens-total 1234567 \
  --tokens-input 100 --tokens-output 200 \
  --tokens-cache-read 0 --tokens-cache-write 0 \
  --copy-local
```

## GitHub Pages

MkDocs hook copies this folder to `conductor-metrics/` on the built site.
Dashboard: [Project Roadmap](https://shadesman5.github.io/pagekit/project/roadmap/) (accordion per step ID).

Local preview uses `docs-site/data/conductor-metrics/` only when the live index has no steps.

## Backfill (historical runs)

Before live collection was enabled:

```bash
node .github/conductor/backfill-metrics.mjs [--dry-run] [--limit N] [--run-id ID] [--skip-cursor]
```

Parses GitHub Actions logs for Conductor phases, token lines, and agent URLs. Missing data is stored as `null` / noted on the phase.

When `CURSOR_API_KEY` is set, backfill automatically calls `GET /v1/agents/{id}/usage` for phases that have an agent ID but no token line in the GHA log (e.g. job cancelled during `poll()`).

## Cursor API enrichment (gaps / cancelled phases)

Same endpoint the Conductor uses after each phase — useful when GHA cancelled before `logUsage` / `recordPhase`:

```bash
CURSOR_API_KEY=… node .github/conductor/enrich-metrics-cursor.mjs [--dry-run] [--session UUID]
```

Phases enriched from the API get `tokensSource: "cursor-api"` and a ↻ marker in the dashboard.

**GHA:** `conductor.yml` runs enrich in an `always()` step after every job and pushes to **`conductor-metrics`** (`METRICS_BRANCH` / `BRANCH`).

## Completed-steps report

```bash
node .github/conductor/backfill-completed-steps.mjs [--status all] [--copy-local]
```

- **With metrics** (Conductor V2 steps like 2.1.7–2.1.10): finds cloud agents in session JSON / GHA logs, fills token gaps via `/usage`.
- **Without metrics** (older ✅ steps): listed in the report — no cloud-agent trail, tokens not recoverable.

Options: `--dry-run`, `--skip-gha-backfill`, `--skip-cursor`, `--status all`.

## Schema (`schemaVersion: 1`)

See demo sessions in `docs-site/data/conductor-metrics/sessions/`.
V1 sessions set `source: "v1-ui"` (or `v1Continued: true` when appending to a Conductor session) and phase `tokensSource: "cursor-api-v1"`.
