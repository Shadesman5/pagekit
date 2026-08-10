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
- **V1 UI:** created by `record-v1-phase.mjs` (printed as `SESSION_ID=…`); store on the ticket under `## METRICS` and reuse for later phases. Pass `--session <uuid>` to resume or to append onto a Conductor session (hybrid).

## When data is written

| Event | Action |
|-------|--------|
| Conductor start | Create session + index entry (`status: in_progress`) |
| After each Conductor cloud-agent phase | Append `phases[]`, recompute `totals`, commit |
| V1 UI after Plan / each Checklist Step / Finalize | `record-v1-phase.mjs` appends PLAN / EXECUTE / FINALIZE (`tokensSource: cursor-api-v1`) |
| FINALIZE / plan-only done | `status: completed` |
| `fail()` / fatal error | `status: failed` |
| `conductor:stop` | `status: cancelled` |

Commits land on the unprotected **`conductor-metrics`** branch only — never on the feature-branch tip (PR CI / bot approval) and never direct to protected `develop` (Ruleset requires PRs, no Actions bypass). The dashboard refreshes via an explicit `pages-deploy.yml` dispatch on `develop` after each metrics push; the build overlays metrics from `conductor-metrics` (`GITHUB_TOKEN` does not re-trigger workflows by itself).

## V1 UI (`cursor.com/agents`)

Live path for the manual Orchestrator rule (`.cursor/rules/orchestrator-subagent-workflow.mdc`).
Launch Input stays Task prompt / Branch / Base / Issue only — **no** agent URL or session UUID from the user.

On a Cursor-managed Cloud VM the Orchestrator resolves its own `bc-…` id by minting an OIDC token on
`CURSOR_AGENT_SOCKET` (default `/run/cursor/api.sock`) and reading JWT claim `cloud_agent_id`
([identity docs](https://cursor.com/docs/cloud-agent/identity)). `record-v1-phase.mjs` does this when
`--agent` is omitted. Session UUIDs are created by the first PLAN record and stored on the ticket under
`## METRICS` for later `--session` reuse (Orchestrator-owned, not a user follow-up).

```bash
# After Plan (creates session; agent id auto-resolved on the Cloud VM)
CURSOR_API_KEY=… node .github/conductor/record-v1-phase.mjs \
  --step 2.7 --type PLAN --push

# After Checklist Step N
CURSOR_API_KEY=… node .github/conductor/record-v1-phase.mjs \
  --step 2.7 --type EXECUTE --batch-steps 6 --session <uuid> --push

# Hybrid Conductor→V1: seed usage cursor on an existing session
CURSOR_API_KEY=… node .github/conductor/record-v1-phase.mjs \
  --step 2.6 --session <conductor-session-uuid> --seed-cursor --push

# Finalize
CURSOR_API_KEY=… node .github/conductor/record-v1-phase.mjs \
  --step 2.7 --type FINALIZE --session <uuid> --status completed --push
```

Token **deltas** use `session.v1UsageCursor[agentId]` (last cumulative `/usage` snapshot). Dashboard shows a **V1 UI** badge and a ◇ marker on `cursor-api-v1` phases. Local IDE (no OIDC socket) cannot auto-resolve agent id → metrics skipped unless `--agent` is passed explicitly.

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

**GHA:** `conductor.yml` runs enrich in an `always()` step after every job and pushes to **`conductor-metrics`** (`METRICS_BRANCH` / `BRANCH`).

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

Steps completed via Cursor Cloud Agents **without** Conductor have no GHA log trail. Import by agent ID from [cursor.com/agents](https://cursor.com/agents) (URL or `bc-…` UUID). Prefer **`record-v1-phase.mjs`** for live V1 UI runs; keep this importer for one-shot historical backfills:

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
V1 sessions set `source: "v1-ui"` (or `v1Continued: true` when appending to a Conductor session) and phase `tokensSource: "cursor-api-v1"`.
