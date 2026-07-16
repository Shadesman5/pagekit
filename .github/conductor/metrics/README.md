# Conductor metrics

Token usage, run duration, and phase breakdown for V2 Conductor cloud-agent sessions.

## Layout

| Path | Purpose |
|------|---------|
| `index.json` | Lightweight index keyed by ROADMAP step ID → session UUIDs |
| `sessions/{sessionId}.json` | Full session: phases, tokens, agent/GHA links |

## Session ID

- **Auto-generated** (`crypto.randomUUID()`) on the first GHA job of a Conductor run.
- **Passed through** chained jobs via the `session_id` workflow input.
- Optional dispatch override (UUID v4 only) for debugging — not recommended for normal use.

## When data is written

| Event | Action |
|-------|--------|
| Conductor start | Create session + index entry (`status: in_progress`) |
| After each cloud-agent phase | Append `phases[]`, recompute `totals`, commit |
| FINALIZE / plan-only done | `status: completed` |
| `fail()` / fatal error | `status: failed` |
| `conductor:stop` | `status: cancelled` |

Commits land on the **feature branch** during the run and merge to `develop` with the PR.

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

**Local preview** (after enrich — not automatic on merge):

```bash
CURSOR_API_KEY=… node .github/conductor/enrich-metrics-cursor.mjs --copy-local
mkdocs serve -f docs-site/mkdocs.yml
```

**GHA:** `conductor.yml` runs enrich in an `always()` step after every job and pushes to the feature branch.

Historical backfill data on `develop` must be enriched locally once (or re-run backfill with `CURSOR_API_KEY` set) — merge alone does not call the API.

## One-shot: all completed ROADMAP steps

Orchestrates GHA log backfill + Cursor token enrich + audit report:

```bash
CURSOR_API_KEY=… node .github/conductor/backfill-completed-steps.mjs --copy-local
```

- **With metrics** (Conductor V2 steps like 2.1.7–2.1.10): finds cloud agents in session JSON / GHA logs, fills token gaps via `/usage`.
- **Without metrics** (older ✅ steps): listed in the report — no cloud-agent trail, tokens not recoverable.

Options: `--dry-run`, `--skip-gha-backfill`, `--skip-cursor`, `--status all`.

## Manual import (pre-Conductor cloud agents)

Steps completed via Cursor Cloud Agents **without** Conductor have no GHA log trail. Import by agent ID from [cursor.com/agents](https://cursor.com/agents) (URL or `bc-…` UUID):

```bash
CURSOR_API_KEY=… node .github/conductor/import-manual-agents.mjs \
  --step 2.1.5 \
  --agent bc-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx \
  --agent https://cursor.com/agents/bc-… \
  --copy-local
```

`/usage` returns **cumulative tokens per agent** (`totalUsage`) plus a **`runs[]` breakdown** (per follow-up chat). Enrich/import store `agent.runs[]` on each phase; the dashboard shows an expandable sub-table when a phase has more than one run.

Test fetch only: add `--dry-run`.

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

Only `--tokens-total` is required; breakdown fields are optional.

## Schema (`schemaVersion: 1`)

See demo sessions in `docs-site/data/conductor-metrics/sessions/`.
