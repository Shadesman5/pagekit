#!/usr/bin/env node
// Import cursor.com/agents runs into conductor-metrics (post-hoc / Automations).
//
// Prefer the **parent** Orchestrator `bc-…` id. Task child agents expose a separate
// `bc-…` in the UI (`?child-id=`), but GET /v1/agents/{child}/usage typically returns
// zeros — usage rolls up on the parent.
//
// Usage:
//   CURSOR_API_KEY=… node .github/conductor/import-manual-agents.mjs \
//     --step 2.7 --agent bc-… [--push]
//
// Options:
//   --step ID          ROADMAP step ID (required unless inferable from --title)
//   --agent ID         Cloud agent ID (repeatable); accepts cursor.com/agents/… URLs
//                      (path id = parent; ignore ?child-id= for usage)
//   --pr-url URL       GitHub PR URL — resolve parent agent via Cursor API (prUrl, then branch)
//   --label TEXT       Phase label (default: V1 for --v1-ui, else MANUAL)
//   --title TEXT       Session title (default: from ROADMAP row); also used to infer --step
//   --branch NAME      Feature branch recorded on the session (+ agent resolve fallback)
//   --issue N          GitHub issue number
//   --task-slug SLUG   Task prompt slug (without .md)
//   --session UUID     Append to existing session instead of creating one
//   --v1-ui            (default) Tag session source=v1-ui (dashboard badge)
//   --no-v1-ui         Legacy manualImport-only tagging (historical backfills)
//   --push             Commit + push to conductor-metrics (+ pages-deploy dispatch)
//   --dry-run          Fetch + print only, no writes
//   --copy-local       Copy metrics → docs-site/data/conductor-metrics
//   --tokens-total N   Skip API; use dashboard-copied token total (optional breakdown below)
//   --tokens-input N / --tokens-output N / --tokens-cache-read N / --tokens-cache-write N
//
// Note: /v1/agents/{id}/usage returns cumulative tokens for that agent (one cloud run).

import { readFileSync, writeFileSync, existsSync, mkdirSync, cpSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseRoadmapTable } from './sync-roadmap-snapshot.mjs';
import {
  INDEX_PATH,
  SESSIONS_DIR,
  METRICS_DIR,
  SCHEMA_VERSION,
  DEFAULT_METRICS_BRANCH,
  createCursorClient,
  fetchAgentMetricsFromCursor,
  applyAgentMetricsToPhase,
  recomputeSessionTotals,
  applySessionTimestamps,
  resolveSessionId,
  currentGitBranch,
  syncMetricsFromRemote,
  pushMetricsToRemote,
  resolveOrchestratorAgentForPr,
  parseRoadmapStepId
} from './metrics.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const ROADMAP = join(ROOT, '.cursor/ROADMAP.md');

const DRY_RUN = process.argv.includes('--dry-run');
const COPY_LOCAL = process.argv.includes('--copy-local');
const DO_PUSH = process.argv.includes('--push');
/** Default on: Automations / post-hoc V1 path. Pass --no-v1-ui for legacy historical imports. */
const V1_UI = !process.argv.includes('--no-v1-ui');

function getArg(name) {
  const i = process.argv.indexOf(name);
  return i >= 0 ? process.argv[i + 1] : null;
}

function getAllArgs(name) {
  const out = [];
  for (let i = 0; i < process.argv.length; i += 1) {
    if (process.argv[i] === name && process.argv[i + 1]) out.push(process.argv[i + 1]);
  }
  return out;
}

function normalizeAgentId(raw) {
  const s = (raw || '').trim();
  // Path segment after /agents/ is the parent id. ?child-id= is a nested Task agent —
  // usage API usually returns 0 for children; do not prefer child-id over the path id.
  const fromUrl = s.match(/agents\/([a-z0-9-]+)/i);
  if (fromUrl) return fromUrl[1].toLowerCase();
  return s.toLowerCase();
}

function readJson(path, fallback = null) {
  if (!existsSync(path)) return fallback;
  return JSON.parse(readFileSync(path, 'utf8'));
}

function writeJson(path, data) {
  writeFileSync(path, `${JSON.stringify(data, null, 2)}\n`, 'utf8');
}

function lookupRoadmapRow(stepId) {
  if (!existsSync(ROADMAP)) return null;
  const md = readFileSync(ROADMAP, 'utf8');
  const { rows } = parseRoadmapTable(md);
  return rows.find(r => r.id.toLowerCase() === stepId.toLowerCase()) || null;
}

function touchIndex(index, session) {
  const stepId = session.roadmapStepId || 'unknown';
  const entry = index.steps[stepId] || {
    title: session.title || session.taskSlug,
    latestSessionId: session.sessionId,
    sessionIds: []
  };
  if (session.title) entry.title = session.title;
  if (!entry.sessionIds.includes(session.sessionId)) {
    entry.sessionIds.unshift(session.sessionId);
  }
  entry.latestSessionId = session.sessionId;
  index.steps[stepId] = entry;
  index.updatedAt = new Date().toISOString();
  index.schemaVersion = SCHEMA_VERSION;
}

function readManualTokens() {
  const totalArg = getArg('--tokens-total');
  if (totalArg == null) return null;
  const total = Number(totalArg);
  if (!Number.isFinite(total) || total <= 0) {
    console.error('--tokens-total must be a positive number');
    process.exit(1);
  }
  const num = name => {
    const v = getArg(name);
    return v == null ? null : Number(v);
  };
  return {
    input: num('--tokens-input'),
    output: num('--tokens-output'),
    cacheRead: num('--tokens-cache-read'),
    cacheWrite: num('--tokens-cache-write'),
    total
  };
}

function fillManualTokenGaps(tokens) {
  const out = { ...tokens };
  if (out.input == null && out.output == null && out.cacheRead == null && out.cacheWrite == null) {
    out.input = 0;
    out.output = 0;
    out.cacheRead = 0;
    out.cacheWrite = 0;
  }
  return out;
}

function copyToLocalPreview() {
  const dest = join(ROOT, 'docs-site/data/conductor-metrics');
  mkdirSync(dest, { recursive: true });
  cpSync(join(ROOT, METRICS_DIR), dest, { recursive: true, force: true });
  console.log(`Copied ${METRICS_DIR}/ → docs-site/data/conductor-metrics/`);
}

function phaseTypeFromLabel(label) {
  const head = String(label || '')
    .trim()
    .split(/\s+/)[0]
    .toUpperCase();
  if (head === 'PLAN' || head === 'EXECUTE' || head === 'FINALIZE' || head === 'MANUAL') return head;
  if (V1_UI) return 'FINALIZE';
  return 'MANUAL';
}

async function main() {
  let stepId = getArg('--step');
  let agentIds = getAllArgs('--agent').map(normalizeAgentId).filter(Boolean);
  const prUrl = getArg('--pr-url');
  const defaultLabel = V1_UI ? 'V1' : 'MANUAL';
  const label = getArg('--label') || defaultLabel;
  const existingSessionId = getArg('--session');
  const branchArg = getArg('--branch');
  const taskSlug = getArg('--task-slug') || null;
  const issueArg = getArg('--issue');
  const titleArg = getArg('--title');

  if (!stepId && titleArg) {
    stepId = parseRoadmapStepId(titleArg, taskSlug ? `${taskSlug}.md` : null);
  }
  if (!stepId) {
    console.error('Missing --step (ROADMAP step ID, e.g. 2.7) — or pass --title containing Step X.Y');
    process.exit(1);
  }

  const apiKey = process.env.CURSOR_API_KEY;
  const manualTokensTemplate = readManualTokens();
  if (!manualTokensTemplate && !apiKey) {
    console.error('CURSOR_API_KEY is required (unless using --tokens-total from Cursor Dashboard)');
    process.exit(1);
  }

  const client = manualTokensTemplate ? null : createCursorClient(apiKey);

  if (!agentIds.length && (prUrl || branchArg) && client) {
    const resolved = await resolveOrchestratorAgentForPr(client, {
      prUrl,
      branch: branchArg,
      log: msg => console.log(msg)
    });
    if (resolved) agentIds = [resolved];
  }

  if (!agentIds.length) {
    console.error(
      'Missing --agent (or could not resolve via --pr-url / --branch). ' +
        'Pass parent bc-… or a PR URL linked to the Orchestrator agent.'
    );
    process.exit(1);
  }

  const row = lookupRoadmapRow(stepId);
  const title = titleArg || (row ? row.name : `Step ${stepId}`);
  const issue = issueArg ? Number(issueArg) : (row?.issue ?? null);
  const returnBranch = currentGitBranch(ROOT);

  console.log(
    `Import ${agentIds.length} agent(s) → step ${stepId}` +
      `${V1_UI ? ' [v1-ui]' : ''}${DO_PUSH ? ' [push]' : ''}${DRY_RUN ? ' [dry-run]' : ''}`
  );

  if (!DRY_RUN && DO_PUSH) {
    mkdirSync(join(ROOT, SESSIONS_DIR), { recursive: true });
    syncMetricsFromRemote({ root: ROOT, log: msg => console.log(msg) });
  }

  const phases = [];
  for (const agentId of agentIds) {
    let metrics = {
      startedAt: null,
      completedAt: null,
      durationMs: null,
      runId: null,
      runCount: 0,
      tokens: { input: null, output: null, cacheRead: null, cacheWrite: null, total: null },
      runs: []
    };
    if (client) {
      metrics = await fetchAgentMetricsFromCursor(client, agentId);
    }

    let tokens;
    let tokensSource;
    if (manualTokensTemplate) {
      tokens = fillManualTokenGaps({ ...manualTokensTemplate });
      tokensSource = 'cursor-dashboard-manual';
      console.log(`  ${agentId.slice(0, 12)}… → manual total=${tokens.total}`);
    } else {
      tokens = metrics.tokens;
      tokensSource = V1_UI ? 'cursor-api-v1' : 'cursor-api-manual';
      console.log(
        `  ${agentId.slice(0, 12)}… → in=${tokens.input ?? '?'} out=${tokens.output ?? '?'} total=${tokens.total ?? '?'}`
      );
      if (tokens.total == null) {
        console.error(`  ⚠ No usage data for ${agentId} — check ID and API key`);
        continue;
      }
      if (tokens.total === 0) {
        console.error(
          `  ⚠ Agent ${agentId.slice(0, 12)}… has zero usage via API — ` +
            `if this is a Task child-id, use the parent /agents/bc-… id instead ` +
            `(or --tokens-total from Cursor Dashboard)`
        );
        continue;
      }
    }

    const type = phaseTypeFromLabel(label);
    const phase = {
      phaseKey: `manual-${agentId}-${label}`,
      type,
      attempt: 0,
      batchSteps: null,
      agent: {
        id: agentId,
        url: `https://cursor.com/agents/${agentId}`,
        model: null
      },
      github: null,
      tokens,
      tokensSource,
      result: manualTokensTemplate
        ? 'Imported from Cursor Dashboard (API usage unavailable)'
        : V1_UI
          ? 'Imported post-hoc (cursor.com/agents UI / Automations)'
          : 'Imported manually (pre-Conductor cloud agent)',
      outcome: 'success',
      manualImport: true,
      notes: 'Import via import-manual-agents.mjs'
    };
    if (V1_UI) phase.v1 = true;
    applyAgentMetricsToPhase(phase, metrics);
    phases.push(phase);
  }

  if (!phases.length) {
    console.error('No phases imported — fix agent IDs or API key.');
    process.exit(1);
  }

  if (DRY_RUN) {
    console.log('\nDry-run OK. Re-run without --dry-run to write session files.');
    return;
  }

  mkdirSync(join(ROOT, SESSIONS_DIR), { recursive: true });
  const index = readJson(join(ROOT, INDEX_PATH), {
    schemaVersion: SCHEMA_VERSION,
    updatedAt: null,
    steps: {}
  });

  let session;
  if (existingSessionId) {
    const sessionId = resolveSessionId(existingSessionId);
    session = readJson(join(ROOT, SESSIONS_DIR, `${sessionId}.json`));
    if (!session) {
      console.error(`Session not found: ${sessionId}`);
      process.exit(1);
    }
    for (const p of phases) {
      if (!session.phases.some(x => x.phaseKey === p.phaseKey)) session.phases.push(p);
    }
    if (V1_UI && session.source !== 'v1-ui') session.v1Continued = true;
    if (title && !session.title) session.title = title;
    if (issue != null && session.issue == null) session.issue = issue;
    if (branchArg && !session.branch) session.branch = branchArg;
    if (taskSlug && !session.taskSlug) session.taskSlug = taskSlug;
  } else {
    session = {
      schemaVersion: SCHEMA_VERSION,
      sessionId: randomUUID(),
      roadmapStepId: stepId,
      title,
      taskSlug,
      taskPrompt: null,
      issue,
      branch: branchArg || null,
      model: null,
      status: 'completed',
      startedAt: null,
      completedAt: null,
      phases,
      totals: {},
      manualImport: {
        at: new Date().toISOString(),
        source: 'import-manual-agents.mjs',
        note: V1_UI
          ? 'Post-hoc V1 UI / Automations import (parent agent usage)'
          : 'Pre-Conductor / historical cloud agent run(s)'
      }
    };
    if (V1_UI) session.source = 'v1-ui';
  }

  recomputeSessionTotals(session);
  applySessionTimestamps(session);
  if (!session.startedAt) {
    session.startedAt = new Date().toISOString();
    session.completedAt = session.completedAt || session.startedAt;
  }
  writeJson(join(ROOT, SESSIONS_DIR, `${session.sessionId}.json`), session);
  touchIndex(index, session);
  writeJson(join(ROOT, INDEX_PATH), index);

  console.log(
    `\nWrote session ${session.sessionId} (${phases.length} phase(s), total=${session.totals.tokens.total})`
  );
  console.log(`SESSION_ID=${session.sessionId}`);

  if (COPY_LOCAL) copyToLocalPreview();

  if (DO_PUSH) {
    pushMetricsToRemote({
      root: ROOT,
      message: `chore(metrics): import ${stepId} · ${session.sessionId.slice(0, 8)}`,
      metricsBranch: DEFAULT_METRICS_BRANCH,
      returnBranch:
        returnBranch && returnBranch !== DEFAULT_METRICS_BRANCH ? returnBranch : null,
      log: msg => console.log(msg)
    });
  }
}

main().catch(e => {
  console.error(e.stack || e.message);
  process.exit(1);
});
