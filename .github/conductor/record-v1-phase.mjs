#!/usr/bin/env node
// Record one V1 (cursor.com/agents UI) metrics phase into conductor-metrics.
//
// Usage (on a cursor.com/agents Cloud VM — agent id auto-resolved via OIDC socket):
//   CURSOR_API_KEY=… node .github/conductor/record-v1-phase.mjs \
//     --step 2.7 --type PLAN [--session UUID] [--push]
//
// Optional --agent bc-… overrides self-discovery (hybrid / debugging).
//
// Token deltas: session.v1UsageCursor[agentId] stores the last cumulative usage
// snapshot. Each record stores max(0, current - last) as phase tokens, then
// advances the cursor. --seed-cursor sets the baseline without attributing
// (hybrid Conductor→V1 or start-of-run). Missing cursor without --seed-cursor
// attributes the full cumulative total to this phase (pure V1 first record).

import {
  readFileSync,
  writeFileSync,
  existsSync,
  mkdirSync,
  cpSync,
  rmSync,
  mkdtempSync
} from 'node:fs';
import { execSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { join, dirname } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import { parseRoadmapTable } from './sync-roadmap-snapshot.mjs';
import {
  INDEX_PATH,
  SESSIONS_DIR,
  METRICS_DIR,
  SCHEMA_VERSION,
  DEFAULT_METRICS_BRANCH,
  emptyTokens,
  normalizeTokens,
  createCursorClient,
  fetchAgentMetricsFromCursor,
  recomputeSessionTotals,
  applySessionTimestamps,
  resolveSessionId,
  resolveSelfCloudAgentId
} from './metrics.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const ROADMAP = join(ROOT, '.cursor/ROADMAP.md');
const PHASE_TYPES = new Set(['PLAN', 'EXECUTE', 'FINALIZE']);

const DRY_RUN = process.argv.includes('--dry-run');
const DO_PUSH = process.argv.includes('--push');
const SEED_CURSOR = process.argv.includes('--seed-cursor');

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

function sh(cmd, opts = {}) {
  return execSync(cmd, {
    cwd: ROOT,
    stdio: ['ignore', 'pipe', 'pipe'],
    encoding: 'utf8',
    ...opts
  }).trim();
}

function log(msg) {
  console.log(`${new Date().toISOString()} ${msg}`);
}

function lookupRoadmapRow(stepId) {
  if (!existsSync(ROADMAP)) return null;
  const md = readFileSync(ROADMAP, 'utf8');
  const { rows } = parseRoadmapTable(md);
  return rows.find(r => r.id.toLowerCase() === stepId.toLowerCase()) || null;
}

function parseBatchSteps(raw) {
  if (!raw || !String(raw).trim()) return null;
  const steps = String(raw)
    .split(',')
    .map(s => Number(s.trim()))
    .filter(n => Number.isFinite(n) && n > 0);
  return steps.length ? steps : null;
}

function tokenSnapshot(tokens) {
  const t = normalizeTokens(tokens || {});
  return {
    input: t.input,
    output: t.output,
    cacheRead: t.cacheRead,
    cacheWrite: t.cacheWrite,
    total: t.total
  };
}

function deltaTokens(prev, current) {
  if (!prev) return tokenSnapshot(current);
  const cur = tokenSnapshot(current);
  const p = tokenSnapshot(prev);
  return {
    input: Math.max(0, cur.input - p.input),
    output: Math.max(0, cur.output - p.output),
    cacheRead: Math.max(0, cur.cacheRead - p.cacheRead),
    cacheWrite: Math.max(0, cur.cacheWrite - p.cacheWrite),
    total: Math.max(0, cur.total - p.total)
  };
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

function ensureGitIdentity() {
  try {
    sh('git config user.email');
  } catch {
    sh('git config user.email "41898282+github-actions[bot]@users.noreply.github.com"');
    sh('git config user.name "github-actions[bot]"');
  }
}

function currentBranch() {
  try {
    return sh('git rev-parse --abbrev-ref HEAD');
  } catch {
    return null;
  }
}

function syncMetricsFromRemote() {
  const target = DEFAULT_METRICS_BRANCH;
  const remote = sh(`git ls-remote --heads origin ${target}`);
  if (!remote) {
    log(`metrics: creating origin/${target} from origin/develop`);
    sh('git fetch origin develop');
    try {
      sh(`git branch -f ${target} origin/develop`);
    } catch {
      sh(`git branch ${target} origin/develop`);
    }
    sh(`git push -u origin ${target}`);
  }
  sh(`git fetch origin ${target}`);
  try {
    sh(`git checkout origin/${target} -- ${METRICS_DIR}`);
  } catch (e) {
    log(`metrics: no ${METRICS_DIR} on origin/${target} yet (${e.message})`);
    mkdirSync(join(ROOT, SESSIONS_DIR), { recursive: true });
  }
}

function pushMetrics(message, returnBranch) {
  ensureGitIdentity();
  if (!existsSync(join(ROOT, METRICS_DIR))) {
    log('metrics: no metrics dir to commit');
    return false;
  }

  const target = DEFAULT_METRICS_BRANCH;
  const tmp = mkdtempSync(join(tmpdir(), 'pagekit-v1-metrics-'));
  try {
    cpSync(join(ROOT, METRICS_DIR), join(tmp, 'metrics'), { recursive: true });

    try {
      sh(`git reset HEAD -- ${METRICS_DIR}`);
    } catch {
      /* unstaged is fine */
    }
    try {
      sh(`git checkout HEAD -- ${METRICS_DIR}`);
    } catch {
      /* may be absent on this branch */
    }
    try {
      sh(`git clean -fd -- ${METRICS_DIR}`);
    } catch {
      /* ok */
    }

    syncMetricsFromRemote();
    sh(`git checkout -B ${target} origin/${target}`);

    rmSync(join(ROOT, METRICS_DIR), { recursive: true, force: true });
    cpSync(join(tmp, 'metrics'), join(ROOT, METRICS_DIR), { recursive: true });

    sh(`git add ${METRICS_DIR}/`);
    try {
      sh('git diff --cached --quiet');
      log('metrics: no changes to commit');
      if (returnBranch) {
        sh(`git fetch origin ${returnBranch}`);
        sh(`git checkout -B ${returnBranch} origin/${returnBranch}`);
      }
      return false;
    } catch {
      sh(`git commit -m ${JSON.stringify(message)}`);
      sh(`git fetch origin ${target}`);
      try {
        sh(`git rebase origin/${target}`);
      } catch (e) {
        try {
          sh('git rebase --abort');
        } catch {
          /* clean */
        }
        throw new Error(`metrics rebase onto origin/${target} failed: ${e.message}`, { cause: e });
      }
      sh(`git push origin ${target}`);
      log(`metrics: pushed to ${target}`);
      try {
        sh('gh workflow run pages-deploy.yml --ref develop');
        log('metrics: dispatched pages-deploy.yml (ref=develop)');
      } catch (e) {
        log(`metrics: pages-deploy dispatch skipped (${e.message})`);
      }
      if (returnBranch) {
        sh(`git fetch origin ${returnBranch}`);
        sh(`git checkout -B ${returnBranch} origin/${returnBranch}`);
      }
      return true;
    }
  } finally {
    rmSync(tmp, { recursive: true, force: true });
  }
}

function wallClockDurationMs(startedAt, endedAt) {
  if (!startedAt || !endedAt) return null;
  const span = Date.parse(endedAt) - Date.parse(startedAt);
  return Number.isFinite(span) && span >= 0 ? span : null;
}

async function main() {
  const stepId = getArg('--step');
  const typeRaw = (getArg('--type') || '').trim().toUpperCase();
  let agentIds = getAllArgs('--agent').map(normalizeAgentId).filter(Boolean);
  const existingSessionId = getArg('--session');
  const batchSteps = parseBatchSteps(getArg('--batch-steps'));
  const attempt = Number(getArg('--attempt') || 0) || 0;
  const outcome = (getArg('--outcome') || 'success').trim().toLowerCase();
  const startedAtArg = getArg('--started-at');
  const endedAtArg = getArg('--ended-at') || new Date().toISOString();
  const statusArg = getArg('--status');
  const resultArg = getArg('--result');

  if (!stepId) {
    console.error('Missing --step (ROADMAP step ID, e.g. 2.7)');
    process.exit(1);
  }
  if (!SEED_CURSOR || typeRaw) {
    if (!PHASE_TYPES.has(typeRaw)) {
      console.error('Missing or invalid --type (PLAN|EXECUTE|FINALIZE)');
      process.exit(1);
    }
  }

  if (!agentIds.length) {
    const selfId = await resolveSelfCloudAgentId();
    if (selfId) {
      agentIds = [selfId];
      log(`resolved self cloud_agent_id=${selfId.slice(0, 12)}… via OIDC socket`);
    }
  }
  if (!agentIds.length) {
    console.error(
      'Missing --agent and could not resolve self cloud_agent_id (CURSOR_AGENT_SOCKET / OIDC). ' +
        'Run on a cursor.com/agents Cloud VM, or pass --agent bc-…'
    );
    process.exit(1);
  }

  const apiKey = process.env.CURSOR_API_KEY;
  if (!apiKey) {
    console.error('CURSOR_API_KEY is required');
    process.exit(1);
  }

  const row = lookupRoadmapRow(stepId);
  const title = getArg('--title') || (row ? row.name : `Step ${stepId}`);
  const issueArg = getArg('--issue');
  const issue = issueArg ? Number(issueArg) : (row?.issue ?? null);
  const branch = getArg('--branch') || null;
  const taskSlug = getArg('--task-slug') || null;
  const returnBranch = currentBranch();

  const client = createCursorClient(apiKey);
  const primaryAgentId = agentIds[0];

  log(
    `V1 metrics ${SEED_CURSOR && !typeRaw ? 'seed-cursor' : typeRaw} step=${stepId} agent=${primaryAgentId.slice(0, 12)}…${DRY_RUN ? ' [dry-run]' : ''}`
  );

  if (!DRY_RUN) {
    mkdirSync(join(ROOT, SESSIONS_DIR), { recursive: true });
    syncMetricsFromRemote();
  }

  let sessionId;
  let session;
  let created = false;

  if (existingSessionId) {
    sessionId = resolveSessionId(existingSessionId);
    session = readJson(join(ROOT, SESSIONS_DIR, `${sessionId}.json`));
    if (!session && !DRY_RUN) {
      console.error(`Session not found: ${sessionId}`);
      process.exit(1);
    }
    if (!session && DRY_RUN) {
      session = {
        schemaVersion: SCHEMA_VERSION,
        sessionId,
        roadmapStepId: stepId,
        title,
        phases: [],
        v1UsageCursor: {},
        source: 'v1-ui'
      };
    }
  } else {
    sessionId = randomUUID();
    created = true;
    session = {
      schemaVersion: SCHEMA_VERSION,
      sessionId,
      roadmapStepId: stepId,
      title,
      taskSlug,
      taskPrompt: null,
      issue,
      branch,
      model: null,
      status: 'in_progress',
      startedAt: new Date().toISOString(),
      completedAt: null,
      phases: [],
      totals: {},
      source: 'v1-ui',
      v1UsageCursor: {}
    };
  }

  if (!session.v1UsageCursor) session.v1UsageCursor = {};
  if (session.source !== 'v1-ui' && !session.v1Continued) {
    // Hybrid append onto a Conductor (or other) session — keep original source, mark continuation.
    session.v1Continued = true;
  } else if (!session.source) {
    session.source = 'v1-ui';
  }
  if (title && !session.title) session.title = title;
  if (issue != null && session.issue == null) session.issue = issue;
  if (branch && !session.branch) session.branch = branch;
  if (taskSlug && !session.taskSlug) session.taskSlug = taskSlug;

  const metrics = await fetchAgentMetricsFromCursor(client, primaryAgentId);
  if (metrics.tokens?.total == null) {
    console.error(`No usage data for ${primaryAgentId} — check ID and API key`);
    process.exit(1);
  }

  const cumulative = tokenSnapshot(metrics.tokens);
  const prevCursor = session.v1UsageCursor[primaryAgentId] || null;

  if (SEED_CURSOR && !typeRaw) {
    session.v1UsageCursor[primaryAgentId] = cumulative;
    log(`seeded cursor ${primaryAgentId.slice(0, 12)}… total=${cumulative.total} (no phase)`);
    if (DRY_RUN) {
      console.log(JSON.stringify({ sessionId, seeded: cumulative, session }, null, 2));
      return;
    }
    recomputeSessionTotals(session);
    applySessionTimestamps(session);
    writeJson(join(ROOT, SESSIONS_DIR, `${sessionId}.json`), session);
    const index = readJson(join(ROOT, INDEX_PATH), {
      schemaVersion: SCHEMA_VERSION,
      updatedAt: null,
      steps: {}
    });
    touchIndex(index, session);
    writeJson(join(ROOT, INDEX_PATH), index);
    if (DO_PUSH) {
      pushMetrics(
        `chore(metrics): v1 seed cursor · ${sessionId.slice(0, 8)}`,
        returnBranch && returnBranch !== DEFAULT_METRICS_BRANCH ? returnBranch : null
      );
    }
    console.log(`SESSION_ID=${sessionId}`);
    return;
  }

  let phaseTokens;
  if (SEED_CURSOR && prevCursor == null) {
    session.v1UsageCursor[primaryAgentId] = cumulative;
    phaseTokens = emptyTokens();
    log(`seeded cursor then recorded ${typeRaw} with 0 delta`);
  } else if (prevCursor == null) {
    phaseTokens = cumulative;
    session.v1UsageCursor[primaryAgentId] = cumulative;
  } else {
    phaseTokens = deltaTokens(prevCursor, cumulative);
    session.v1UsageCursor[primaryAgentId] = cumulative;
  }

  const wallMs = wallClockDurationMs(startedAtArg, endedAtArg);
  const phaseStartedAt = startedAtArg || metrics.startedAt || new Date().toISOString();
  const phaseCompletedAt = endedAtArg || metrics.completedAt || new Date().toISOString();
  const phaseDurationMs =
    wallMs != null ? wallMs : metrics.durationMs != null ? metrics.durationMs : null;

  const batchKey = batchSteps?.length ? batchSteps.join(',') : 'none';
  const phaseKey = `v1-${primaryAgentId}-${typeRaw}-${batchKey}-a${attempt}`;
  if (session.phases.some(p => p.phaseKey === phaseKey)) {
    log(`skip duplicate phase ${phaseKey}`);
    console.log(`SESSION_ID=${sessionId}`);
    return;
  }

  const phase = {
    phaseKey,
    type: typeRaw,
    attempt,
    batchSteps: typeRaw === 'EXECUTE' ? batchSteps : null,
    startedAt: phaseStartedAt,
    completedAt: phaseCompletedAt,
    durationMs: phaseDurationMs,
    agent: {
      id: primaryAgentId,
      runId: metrics.runId || null,
      url: `https://cursor.com/agents/${primaryAgentId}`,
      model: null,
      runCount: metrics.runCount || 0,
      runs: metrics.runs || []
    },
    github: null,
    tokens: phaseTokens,
    tokensSource: 'cursor-api-v1',
    result: String(
      resultArg ||
        (typeRaw === 'EXECUTE' && batchSteps?.length
          ? `V1 Step ${batchSteps.join(',')} done`
          : `V1 ${typeRaw}`)
    ).slice(0, 500),
    outcome,
    v1: true,
    notes:
      metrics.runCount > 1
        ? `Recorded via record-v1-phase.mjs · Timing from Cursor API (${metrics.runCount} agent runs aggregated)`
        : 'Recorded via record-v1-phase.mjs (cursor.com/agents UI)'
  };

  session.phases.push(phase);

  if (statusArg) {
    session.status = statusArg;
    if (statusArg === 'completed' || statusArg === 'failed' || statusArg === 'cancelled') {
      session.completedAt = phaseCompletedAt;
    }
  } else if (typeRaw === 'FINALIZE' && outcome === 'success') {
    session.status = 'completed';
    session.completedAt = phaseCompletedAt;
  } else if (!session.status) {
    session.status = 'in_progress';
  }

  recomputeSessionTotals(session);
  applySessionTimestamps(session);

  log(
    `${typeRaw} tokens_delta=${phaseTokens.total} cumulative=${cumulative.total} duration=${Math.round((phase.durationMs || 0) / 1000)}s`
  );

  if (DRY_RUN) {
    console.log(
      JSON.stringify({ sessionId, created, phase, cursor: session.v1UsageCursor }, null, 2)
    );
    console.log(`SESSION_ID=${sessionId}`);
    return;
  }

  writeJson(join(ROOT, SESSIONS_DIR, `${sessionId}.json`), session);
  const index = readJson(join(ROOT, INDEX_PATH), {
    schemaVersion: SCHEMA_VERSION,
    updatedAt: null,
    steps: {}
  });
  touchIndex(index, session);
  writeJson(join(ROOT, INDEX_PATH), index);

  if (DO_PUSH) {
    const label =
      typeRaw === 'EXECUTE' && batchSteps?.length ? `EXECUTE ${batchSteps.join(',')}` : typeRaw;
    pushMetrics(
      `chore(metrics): v1 ${label} · ${sessionId.slice(0, 8)}`,
      returnBranch && returnBranch !== DEFAULT_METRICS_BRANCH ? returnBranch : null
    );
  }

  console.log(`SESSION_ID=${sessionId}`);
  if (created) log(`created session ${sessionId}`);
}

main().catch(e => {
  console.error(e.stack || e.message);
  process.exit(1);
});
