#!/usr/bin/env node
// Import pre-Conductor cloud agents into metrics (manual agent IDs → Cursor /usage API).
//
// Usage:
//   CURSOR_API_KEY=… node .github/conductor/import-manual-agents.mjs \
//     --step 2.1.5 --agent bc-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx [--agent bc-…]
//
// Options:
//   --step ID          ROADMAP step ID (required)
//   --agent ID         Cloud agent ID (repeatable); also accepts cursor.com/agents/… URLs
//   --label TEXT       Phase label (default: MANUAL)
//   --title TEXT       Session title (default: from ROADMAP row)
//   --session UUID     Append to existing session instead of creating one
//   --dry-run          Fetch + print only, no writes
//   --copy-local       Copy metrics → docs-site/data/conductor-metrics
//   --tokens-total N   Skip API; use dashboard-copied token total (optional breakdown below)
//   --tokens-input N   Optional manual input tokens (with --tokens-total)
//   --tokens-output N  Optional manual output tokens
//   --tokens-cache-read N
//   --tokens-cache-write N
//
// Note: /v1/agents/{id}/usage returns cumulative tokens for that agent (one cloud run).
// Use one agent ID per distinct cloud-agent dispatch — do not reuse the same ID for multiple phases.

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
  createCursorClient,
  fetchAgentMetricsFromCursor,
  applyAgentMetricsToPhase,
  recomputeSessionTotals,
  applySessionTimestamps
} from './metrics.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const ROADMAP = join(ROOT, '.cursor/ROADMAP.md');

const DRY_RUN = process.argv.includes('--dry-run');
const COPY_LOCAL = process.argv.includes('--copy-local');

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
  // Agent IDs are "bc-<uuid>" (39 chars), not a bare 36-char UUID. Capture the whole path segment
  // after /agents/ (stops at the next /, ? or #) so the "bc-" prefix is never truncated.
  const fromUrl = s.match(/agents\/([a-z0-9-]+)/i);
  if (fromUrl) return fromUrl[1].toLowerCase();
  return s;
}

function readJson(path, fallback = null) {
  if (!existsSync(path)) return fallback;
  return JSON.parse(readFileSync(path, 'utf8'));
}

function writeJson(path, data) {
  writeFileSync(path, `${JSON.stringify(data, null, 2)}\n`, 'utf8');
}

function lookupRoadmapRow(stepId) {
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

async function main() {
  const stepId = getArg('--step');
  const agentIds = getAllArgs('--agent').map(normalizeAgentId).filter(Boolean);
  const label = getArg('--label') || 'MANUAL';
  const existingSessionId = getArg('--session');

  if (!stepId) {
    console.error('Missing --step (ROADMAP step ID, e.g. 2.1.5)');
    process.exit(1);
  }
  if (!agentIds.length) {
    console.error('Missing --agent (one or more cloud agent IDs or cursor.com/agents/… URLs)');
    process.exit(1);
  }

  const apiKey = process.env.CURSOR_API_KEY;
  const manualTokensTemplate = readManualTokens();
  if (!manualTokensTemplate && !apiKey) {
    console.error('CURSOR_API_KEY is required (unless using --tokens-total from Cursor Dashboard)');
    process.exit(1);
  }

  const row = lookupRoadmapRow(stepId);
  const title = getArg('--title') || (row ? row.name : `Step ${stepId}`);
  const client = manualTokensTemplate ? null : createCursorClient(apiKey);

  console.log(
    `Import ${agentIds.length} manual agent(s) → step ${stepId}${DRY_RUN ? ' [dry-run]' : ''}`
  );

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
      tokensSource = 'cursor-api-manual';
      console.log(
        `  ${agentId.slice(0, 12)}… → in=${tokens.input ?? '?'} out=${tokens.output ?? '?'} total=${tokens.total ?? '?'}`
      );
      if (tokens.total == null) {
        console.error(`  ⚠ No usage data for ${agentId} — check ID and API key`);
        continue;
      }
      if (tokens.total === 0) {
        console.error(
          `  ⚠ Agent ${agentId.slice(0, 12)}… has zero usage via API — use --tokens-total from Cursor Dashboard`
        );
        continue;
      }
    }

    const phase = {
      phaseKey: `manual-${agentId}-${label}`,
      type:
        label.startsWith('EXECUTE') || label === 'PLAN' || label === 'FINALIZE'
          ? label.split(' ')[0]
          : 'MANUAL',
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
        ? 'Imported manually from Cursor Dashboard (API usage unavailable)'
        : 'Imported manually (pre-Conductor cloud agent)',
      outcome: 'success',
      manualImport: true,
      notes: 'Manual import via import-manual-agents.mjs'
    };
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
    session = readJson(join(ROOT, SESSIONS_DIR, `${existingSessionId}.json`));
    if (!session) {
      console.error(`Session not found: ${existingSessionId}`);
      process.exit(1);
    }
    for (const p of phases) {
      if (!session.phases.some(x => x.phaseKey === p.phaseKey)) session.phases.push(p);
    }
  } else {
    const sessionId = randomUUID();
    session = {
      schemaVersion: SCHEMA_VERSION,
      sessionId,
      roadmapStepId: stepId,
      title,
      taskSlug: null,
      taskPrompt: null,
      issue: row?.issue ?? null,
      branch: null,
      model: null,
      status: 'completed',
      startedAt: null,
      completedAt: null,
      phases,
      totals: {},
      manualImport: {
        at: new Date().toISOString(),
        source: 'import-manual-agents.mjs',
        note: 'Pre-Conductor cloud agent run(s)'
      }
    };
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
    `\nWrote session ${session.sessionId.slice(0, 8)}… (${phases.length} phase(s), total=${session.totals.tokens.total})`
  );

  if (COPY_LOCAL) copyToLocalPreview();
}

main().catch(e => {
  console.error(e.stack || e.message);
  process.exit(1);
});
