// Conductor metrics — session files + index for GitHub Pages dashboard.
// Zero npm deps. Persisted on the unprotected `conductor-metrics` branch only —
// never on the feature-branch tip (PR CI / bot approval) and never direct to
// protected `develop` (Ruleset requires PRs, no Actions bypass).

import {
  readFileSync,
  writeFileSync,
  existsSync,
  mkdirSync,
  cpSync,
  rmSync,
  mkdtempSync
} from 'node:fs';
import { basename, join } from 'node:path';
import { tmpdir } from 'node:os';
import { randomUUID } from 'node:crypto';

export const METRICS_DIR = '.github/conductor/metrics';
export const SESSIONS_DIR = `${METRICS_DIR}/sessions`;
export const INDEX_PATH = `${METRICS_DIR}/index.json`;
export const SCHEMA_VERSION = 1;
export const CURSOR_API = 'https://api.cursor.com';
/** Long-lived branch for session JSON only (not Ruleset-protected like develop). */
export const DEFAULT_METRICS_BRANCH = 'conductor-metrics';

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export function emptyTokens() {
  return { input: 0, output: 0, cacheRead: 0, cacheWrite: 0, total: 0 };
}

export function normalizeTokens(raw = {}) {
  const input = Number(raw.inputTokens ?? raw.input ?? 0) || 0;
  const output = Number(raw.outputTokens ?? raw.output ?? 0) || 0;
  const cacheRead = Number(raw.cacheReadTokens ?? raw.cacheRead ?? 0) || 0;
  const cacheWrite = Number(raw.cacheWriteTokens ?? raw.cacheWrite ?? 0) || 0;
  const total =
    Number(raw.totalTokens ?? raw.total ?? input + output + cacheRead + cacheWrite) || 0;
  return { input, output, cacheRead, cacheWrite, total };
}

export function normalizeTokensNullable(raw = {}) {
  if (
    !raw ||
    (raw.totalTokens == null && raw.total == null && raw.inputTokens == null && raw.input == null)
  ) {
    return { input: null, output: null, cacheRead: null, cacheWrite: null, total: null };
  }
  const t = normalizeTokens(raw);
  return {
    input: t.input,
    output: t.output,
    cacheRead: t.cacheRead,
    cacheWrite: t.cacheWrite,
    total: t.total
  };
}

export function createCursorClient(apiKey) {
  const key = (apiKey || '').trim();
  if (!key) return null;
  return async function cursorApi(method, path, body) {
    const res = await fetch(`${CURSOR_API}${path}`, {
      method,
      headers: { Authorization: `Bearer ${key}`, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    });
    const text = await res.text();
    let json;
    try {
      json = text ? JSON.parse(text) : {};
    } catch {
      json = { raw: text };
    }
    if (!res.ok) {
      const e = new Error(`Cursor API ${method} ${path} -> ${res.status}: ${text.slice(0, 200)}`);
      e.status = res.status;
      throw e;
    }
    return json;
  };
}

export async function fetchAgentUsageFromCursor(client, agentId) {
  const full = await fetchAgentUsageFullFromCursor(client, agentId);
  return full.tokens;
}

/** Agent usage with per-run token rows from `usage.runs[]`. */
export async function fetchAgentUsageFullFromCursor(client, agentId) {
  const empty = { tokens: normalizeTokensNullable({}), runsById: new Map() };
  if (!client || !agentId) return empty;
  try {
    const u = await client('GET', `/v1/agents/${agentId}/usage`);
    const runsById = new Map();
    for (const row of u.runs || []) {
      if (row.id) runsById.set(row.id, normalizeTokens(row.usage ?? row));
    }
    return { tokens: normalizeTokensNullable(u.totalUsage ?? u), runsById };
  } catch {
    return empty;
  }
}

/** List all runs for a cloud agent (paginated). */
export async function listAgentRunsFromCursor(client, agentId, { limit = 100 } = {}) {
  const items = [];
  if (!client || !agentId) return items;
  let cursor = null;
  do {
    const qs = new URLSearchParams({ limit: String(limit) });
    if (cursor) qs.set('cursor', cursor);
    const page = await client('GET', `/v1/agents/${agentId}/runs?${qs}`);
    items.push(...(page.items || page.runs || []));
    cursor = page.nextCursor || null;
  } while (cursor);
  return items;
}

/** Timing + tokens + per-run breakdown for manual / backfilled sessions. */
export async function fetchAgentMetricsFromCursor(client, agentId) {
  const empty = {
    startedAt: null,
    completedAt: null,
    durationMs: null,
    runId: null,
    runCount: 0,
    tokens: normalizeTokensNullable({}),
    runs: []
  };
  if (!client || !agentId) return empty;
  try {
    const [agent, usageResp, listedRuns] = await Promise.all([
      client('GET', `/v1/agents/${agentId}`),
      client('GET', `/v1/agents/${agentId}/usage`),
      listAgentRunsFromCursor(client, agentId)
    ]);

    const runsById = new Map();
    for (const row of usageResp.runs || []) {
      if (row.id) runsById.set(row.id, normalizeTokens(row.usage ?? row));
    }
    const tokens = normalizeTokensNullable(usageResp.totalUsage ?? usageResp);

    let startedAt = agent.createdAt || null;
    let completedAt = agent.updatedAt || null;
    const latestRunId = agent.latestRunId || null;

    let runItems = listedRuns;
    if (!runItems.length && latestRunId) runItems = [{ id: latestRunId }];

    const detailedRuns = await Promise.all(
      runItems.map(async item => {
        const runId = item.id;
        if ((item.durationMs != null && item.createdAt) || !runId) return item;
        try {
          return await client('GET', `/v1/agents/${agentId}/runs/${runId}`);
        } catch {
          return item;
        }
      })
    );

    detailedRuns.sort((a, b) => Date.parse(a.createdAt || 0) - Date.parse(b.createdAt || 0));

    let durationMs = 0;
    let hasDuration = false;
    const starts = [];
    const ends = [];
    const runs = [];

    for (let i = 0; i < detailedRuns.length; i += 1) {
      const run = detailedRuns[i];
      const runId = run.id;
      if (run.createdAt) starts.push(run.createdAt);
      if (run.updatedAt) ends.push(run.updatedAt);

      let runDuration = null;
      if (run.durationMs != null && Number.isFinite(run.durationMs)) {
        runDuration = run.durationMs;
        durationMs += runDuration;
        hasDuration = true;
      } else if (run.createdAt && run.updatedAt) {
        const span = Date.parse(run.updatedAt) - Date.parse(run.createdAt);
        if (Number.isFinite(span) && span > 0) {
          runDuration = span;
          durationMs += span;
          hasDuration = true;
        }
      }

      runs.push({
        runId,
        index: i + 1,
        startedAt: run.createdAt || null,
        completedAt: run.updatedAt || null,
        durationMs: runDuration,
        tokens: runsById.get(runId) || emptyTokens()
      });
    }

    if (starts.length) startedAt = starts.sort()[0];
    if (ends.length) completedAt = ends.sort().reverse()[0];

    if (!hasDuration && startedAt && completedAt) {
      const span = Date.parse(completedAt) - Date.parse(startedAt);
      if (Number.isFinite(span) && span > 0) durationMs = span;
    }

    return {
      startedAt,
      completedAt,
      durationMs: hasDuration || durationMs > 0 ? durationMs : null,
      runId: latestRunId,
      runCount: runs.length,
      tokens,
      runs
    };
  } catch {
    return empty;
  }
}

/** @deprecated Prefer fetchAgentMetricsFromCursor — timing-only subset. */
export async function fetchAgentTimingFromCursor(client, agentId) {
  const metrics = await fetchAgentMetricsFromCursor(client, agentId);
  return {
    startedAt: metrics.startedAt,
    completedAt: metrics.completedAt,
    durationMs: metrics.durationMs,
    runId: metrics.runId,
    runCount: metrics.runCount,
    runs: metrics.runs
  };
}

const TIMING_NOTE_RE = /(?: · )?Timing from Cursor[^·]*(?= · |$)/g;

export function applyAgentMetricsToPhase(phase, metrics) {
  if (!metrics?.startedAt) return false;
  phase.startedAt = metrics.startedAt;
  if (metrics.durationMs != null) phase.durationMs = metrics.durationMs;
  if (metrics.completedAt) phase.completedAt = metrics.completedAt;
  if (metrics.runId) phase.agent.runId = metrics.runId;
  if (metrics.runCount > 0) phase.agent.runCount = metrics.runCount;
  if (metrics.runs?.length) phase.agent.runs = metrics.runs;
  const note =
    metrics.runCount > 1
      ? `Timing from Cursor API (${metrics.runCount} agent runs aggregated)`
      : 'Timing from Cursor API (single agent run)';
  const cleaned = (phase.notes || '').replace(TIMING_NOTE_RE, '').replace(/ · $/, '').trim();
  phase.notes = cleaned ? `${cleaned} · ${note}` : note;
  return true;
}

/** @deprecated Prefer applyAgentMetricsToPhase */
export function applyAgentTimingToPhase(phase, timing) {
  return applyAgentMetricsToPhase(phase, timing);
}

/** Set session startedAt/completedAt from phase timing (after import or enrich). */
export function applySessionTimestamps(session) {
  const starts = [];
  const ends = [];
  for (const phase of session.phases || []) {
    if (phase.startedAt) starts.push(phase.startedAt);
    // Prefer wall-clock end; durationMs may be summed work across gaps between follow-up runs.
    if (phase.completedAt) {
      ends.push(phase.completedAt);
    } else if (phase.durationMs != null && phase.startedAt) {
      ends.push(new Date(Date.parse(phase.startedAt) + phase.durationMs).toISOString());
    }
  }
  if (starts.length) session.startedAt = starts.sort()[0];
  if (ends.length) session.completedAt = ends.sort().reverse()[0];
  return session;
}

export function recomputeSessionTotals(session) {
  const totals = {
    tokens: { input: null, output: null, cacheRead: null, cacheWrite: null, total: null },
    durationMs: 0,
    phaseCount: session.phases?.length || 0,
    escalations: 0,
    ghaJobs: new Set(
      (session.phases || []).map(p => p.github?.jobId || p.github?.runId).filter(Boolean)
    ).size,
    cloudAgents: (session.phases || []).filter(p => p.agent?.id).length
  };
  const add = (a, b) => (a == null && b == null ? null : (a || 0) + (b || 0));
  for (const phase of session.phases || []) {
    const t = phase.tokens || {};
    totals.tokens.input = add(totals.tokens.input, t.input);
    totals.tokens.output = add(totals.tokens.output, t.output);
    totals.tokens.cacheRead = add(totals.tokens.cacheRead, t.cacheRead);
    totals.tokens.cacheWrite = add(totals.tokens.cacheWrite, t.cacheWrite);
    totals.tokens.total = add(totals.tokens.total, t.total);
    totals.durationMs += phase.durationMs || 0;
    if (phase.outcome === 'escalate') totals.escalations += 1;
  }
  if (totals.ghaJobs === 0 && session.phases?.length) totals.ghaJobs = 1;
  session.totals = totals;
  return session;
}

function runsNeedTokenBackfill(phase) {
  const runs = phase.agent?.runs;
  if (!runs?.length || !phase.tokens?.total) return false;
  return runs.every(r => !(r.tokens?.total > 0));
}

export function phaseNeedsMetricsRefresh(phase, { refreshTiming = false } = {}) {
  if (!phase.agent?.id) return false;
  if (refreshTiming) return true;
  if (phase.durationMs == null || !phase.startedAt) return true;
  if ((phase.notes || '').includes('latest run')) return true;
  if (!phase.agent.runs?.length) return true;
  if (runsNeedTokenBackfill(phase)) return true;
  return false;
}

/** @deprecated Prefer phaseNeedsMetricsRefresh */
export function phaseNeedsTimingRefresh(phase, opts = {}) {
  return phaseNeedsMetricsRefresh(phase, opts);
}

export async function enrichSessionTokensFromCursor(
  session,
  client,
  { log = console.log, refreshTiming = false } = {}
) {
  if (!client || !session?.phases?.length) return { session, enriched: 0, timingEnriched: 0 };
  let enriched = 0;
  let timingEnriched = 0;
  for (const phase of session.phases) {
    const agentId = phase.agent?.id;
    if (!agentId) continue;

    const needsTokens = phase.tokens?.total == null;
    const needsMetrics = phaseNeedsMetricsRefresh(phase, { refreshTiming });
    if (!needsTokens && !needsMetrics) continue;

    const metrics = needsMetrics ? await fetchAgentMetricsFromCursor(client, agentId) : null;

    if (needsTokens) {
      const tokens =
        metrics?.tokens?.total != null
          ? metrics.tokens
          : await fetchAgentUsageFromCursor(client, agentId);
      if (tokens.total != null && tokens.total !== 0) {
        phase.tokens = tokens;
        phase.tokensSource = 'cursor-api';
        const note = 'Tokens from Cursor /v1/agents/{id}/usage';
        phase.notes = phase.notes ? `${phase.notes} · ${note}` : note;
        enriched += 1;
        log(`    cursor-api: ${agentId.slice(0, 12)}… → ${tokens.total} tokens (${phase.type})`);
      }
    }

    if (needsMetrics && applyAgentMetricsToPhase(phase, metrics)) {
      timingEnriched += 1;
      const runs = metrics.runCount > 1 ? ` · ${metrics.runCount} runs` : '';
      log(
        `    cursor-api: ${agentId.slice(0, 12)}… → ${metrics.startedAt?.slice(0, 10)} · ${Math.round((metrics.durationMs || 0) / 1000)}s${runs}`
      );
    }
  }
  if (enriched || timingEnriched) {
    applySessionTimestamps(session);
    recomputeSessionTotals(session);
  }
  return { session, enriched, timingEnriched };
}

export function createMetricsCollector({ api, sh, log, env, branch, metricsBranch, pullBranch }) {
  let sessionId = resolveSessionId(env.SESSION_ID);
  // Feature branch for agents; metrics always land on metricsBranch (conductor-metrics).
  const featureBranch = branch;
  const targetBranch = metricsBranch || DEFAULT_METRICS_BRANCH;
  const ctx = {
    roadmapStepId: parseRoadmapStepId(env.TITLE, env.TASK_PROMPT),
    title: (env.TITLE || '').trim() || null,
    taskSlug: basename(env.TASK_PROMPT).replace(/\.md$/i, ''),
    taskPrompt: env.TASK_PROMPT,
    issue: env.ISSUE ? Number(env.ISSUE) : null,
    branch: featureBranch,
    model: (env.MODEL || '').trim() || null
  };

  function sessionPath(id = sessionId) {
    return `${SESSIONS_DIR}/${id}.json`;
  }

  function ensureDirs() {
    mkdirSync(SESSIONS_DIR, { recursive: true });
  }

  function readJson(path, fallback) {
    if (!existsSync(path)) return fallback;
    return JSON.parse(readFileSync(path, 'utf8'));
  }

  function writeJson(path, data) {
    writeFileSync(path, `${JSON.stringify(data, null, 2)}\n`, 'utf8');
  }

  function addTokens(a, b) {
    return {
      input: (a.input || 0) + (b.input || 0),
      output: (a.output || 0) + (b.output || 0),
      cacheRead: (a.cacheRead || 0) + (b.cacheRead || 0),
      cacheWrite: (a.cacheWrite || 0) + (b.cacheWrite || 0),
      total: (a.total || 0) + (b.total || 0)
    };
  }

  function recomputeTotals(session) {
    const totals = {
      tokens: emptyTokens(),
      durationMs: 0,
      phaseCount: session.phases.length,
      escalations: session.phases.filter(p => p.outcome === 'escalate').length,
      ghaJobs: new Set(session.phases.map(p => p.github?.runId).filter(Boolean)).size,
      cloudAgents: session.phases.length
    };
    for (const phase of session.phases) {
      totals.tokens = addTokens(totals.tokens, phase.tokens || emptyTokens());
      totals.durationMs += phase.durationMs || 0;
    }
    session.totals = totals;
    return session;
  }

  function githubContext() {
    const server = (env.GITHUB_SERVER_URL || 'https://github.com').replace(/\/$/, '');
    const repo = env.GITHUB_REPOSITORY || '';
    const runId = env.GITHUB_RUN_ID ? Number(env.GITHUB_RUN_ID) : null;
    const runUrl = runId && repo ? `${server}/${repo}/actions/runs/${runId}` : null;
    return {
      runId,
      runAttempt: env.GITHUB_RUN_ATTEMPT ? Number(env.GITHUB_RUN_ATTEMPT) : null,
      workflow: env.GITHUB_WORKFLOW || null,
      runUrl,
      repository: repo || null
    };
  }

  function ensureGitIdentity() {
    try {
      sh('git config user.email');
    } catch {
      sh('git config user.email "41898282+github-actions[bot]@users.noreply.github.com"');
      sh('git config user.name "github-actions[bot]"');
    }
  }

  /** Create origin/<metricsBranch> from develop if missing (no checkout switch). */
  function ensureMetricsBranchExists() {
    if (sh(`git ls-remote --heads origin ${targetBranch}`)) return;
    log(`  metrics: creating origin/${targetBranch} from origin/develop`);
    sh('git fetch origin develop');
    try {
      sh(`git branch -f ${targetBranch} origin/develop`);
    } catch {
      sh(`git branch ${targetBranch} origin/develop`);
    }
    sh(`git push -u origin ${targetBranch}`);
  }

  /** Load authoritative metrics tree from origin/<metricsBranch> into the working tree. */
  function syncMetricsFromTarget() {
    ensureMetricsBranchExists();
    sh(`git fetch origin ${targetBranch}`);
    try {
      sh(`git checkout origin/${targetBranch} -- ${METRICS_DIR}`);
    } catch (e) {
      log(`  metrics: no ${METRICS_DIR} on origin/${targetBranch} yet (${e.message})`);
    }
  }

  function restoreFeatureCheckout() {
    if (pullBranch) {
      pullBranch();
      return;
    }
    sh(`git fetch origin ${featureBranch}`);
    sh(`git checkout -B ${featureBranch} origin/${featureBranch}`);
  }

  /**
   * Reset tracked metrics files to HEAD, then always remove untracked leftovers.
   * develop still carries historical metrics/; new session files synced from
   * conductor-metrics are not in HEAD — leaving them untracked blocks
   * `git checkout -B conductor-metrics` (would overwrite).
   */
  function discardMetricsWorkingTree() {
    try {
      sh(`git checkout HEAD -- ${METRICS_DIR}`);
    } catch {
      /* METRICS_DIR may be absent on this branch */
    }
    try {
      sh(`git clean -fd -- ${METRICS_DIR}`);
    } catch {
      /* METRICS_DIR may be absent on this branch */
    }
  }

  /**
   * Commit metrics to metricsBranch only, then return to the feature branch.
   * GITHUB_TOKEN pushes do not trigger other workflows — dispatch pages-deploy on develop
   * (site build overlays metrics from conductor-metrics).
   */
  function commitMetrics(message) {
    ensureGitIdentity();
    if (!existsSync(METRICS_DIR)) {
      log('  metrics: no metrics dir to commit');
      return false;
    }

    const tmp = mkdtempSync(join(tmpdir(), 'pagekit-metrics-'));
    try {
      cpSync(METRICS_DIR, join(tmp, 'metrics'), { recursive: true });

      try {
        sh(`git reset HEAD -- ${METRICS_DIR}`);
      } catch {
        /* unstaged / untracked is fine */
      }
      discardMetricsWorkingTree();

      ensureMetricsBranchExists();
      sh(`git fetch origin ${targetBranch}`);
      sh(`git checkout -B ${targetBranch} origin/${targetBranch}`);

      rmSync(METRICS_DIR, { recursive: true, force: true });
      cpSync(join(tmp, 'metrics'), METRICS_DIR, { recursive: true });

      sh(`git add ${METRICS_DIR}/`);
      try {
        sh('git diff --cached --quiet');
        log('  metrics: no changes to commit');
        restoreFeatureCheckout();
        return false;
      } catch {
        sh(`git commit -m ${JSON.stringify(message)}`);
        sh(`git fetch origin ${targetBranch}`);
        try {
          sh(`git rebase origin/${targetBranch}`);
        } catch (e) {
          try {
            sh('git rebase --abort');
          } catch {
            /* already clean or no rebase in progress */
          }
          throw new Error(
            `metrics rebase onto origin/${targetBranch} failed (resolve conflict or retry): ${e.message}`,
            { cause: e }
          );
        }
        sh(`git push origin ${targetBranch}`);
        log(`  metrics: committed and pushed to ${targetBranch}`);
        try {
          // Build site from develop (fresh docs) — workflow overlays metrics from conductor-metrics.
          sh('gh workflow run pages-deploy.yml --ref develop');
          log('  metrics: dispatched pages-deploy.yml (ref=develop)');
        } catch (e) {
          log(`  metrics: pages-deploy dispatch skipped (${e.message})`);
        }
        restoreFeatureCheckout();
        return true;
      }
    } finally {
      rmSync(tmp, { recursive: true, force: true });
    }
  }

  function loadSession(id = sessionId) {
    return readJson(sessionPath(id), null);
  }

  function initSession() {
    syncMetricsFromTarget();
    ensureDirs();
    let session = loadSession();
    if (session) {
      log(`metrics: resume session ${sessionId} (${session.phases.length} phase(s) so far)`);
      discardMetricsWorkingTree();
      return sessionId;
    }

    session = {
      schemaVersion: SCHEMA_VERSION,
      sessionId,
      roadmapStepId: ctx.roadmapStepId,
      title: ctx.title,
      taskSlug: ctx.taskSlug,
      taskPrompt: ctx.taskPrompt,
      issue: ctx.issue,
      branch: ctx.branch,
      model: ctx.model,
      status: 'in_progress',
      startedAt: new Date().toISOString(),
      completedAt: null,
      phases: [],
      totals: {
        tokens: emptyTokens(),
        durationMs: 0,
        phaseCount: 0,
        escalations: 0,
        ghaJobs: 0,
        cloudAgents: 0
      }
    };
    writeJson(sessionPath(), session);
    touchIndex(session);
    commitMetrics(`chore(metrics): start conductor session ${sessionId.slice(0, 8)}`);
    log(`metrics: new session ${sessionId} (step=${ctx.roadmapStepId || '?'}) → ${targetBranch}`);
    return sessionId;
  }

  function touchIndex(session) {
    const index = readJson(INDEX_PATH, {
      schemaVersion: SCHEMA_VERSION,
      updatedAt: null,
      steps: {}
    });
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
    writeJson(INDEX_PATH, index);
  }

  async function fetchAgentUsage(agentId) {
    try {
      const u = await api('GET', `/v1/agents/${agentId}/usage`);
      return normalizeTokens(u.totalUsage ?? u);
    } catch (e) {
      log(`  metrics: usage fetch failed (${e.message})`);
      return emptyTokens();
    }
  }

  async function recordPhase({ label, startedAt, agentId, runId, agentUrl, result, outcome }) {
    syncMetricsFromTarget();
    ensureDirs();
    let session = loadSession();
    if (!session) {
      initSession();
      syncMetricsFromTarget();
      session = loadSession();
    }

    const meta = parsePhaseLabel(label);
    const gh = githubContext();
    const phaseKey = `${gh.runId || 'local'}-${gh.runAttempt || 1}-${label}`;
    if (session.phases.some(p => p.phaseKey === phaseKey)) {
      log(`  metrics: skip duplicate phase ${phaseKey}`);
      discardMetricsWorkingTree();
      return;
    }

    const tokens = await fetchAgentUsage(agentId);
    const durationMs = Math.max(0, Date.now() - startedAt);
    const phase = {
      phaseKey,
      type: meta.type,
      attempt: meta.attempt,
      batchSteps: meta.batchSteps,
      startedAt: new Date(startedAt).toISOString(),
      durationMs,
      agent: {
        id: agentId,
        runId,
        url: agentUrl || null,
        model: ctx.model
      },
      github: gh,
      tokens,
      tokensSource: 'cursor-api',
      result: String(result || '').slice(0, 500),
      outcome: outcome || inferOutcome(result)
    };

    session.phases.push(phase);
    recomputeTotals(session);
    writeJson(sessionPath(), session);
    touchIndex(session);
    commitMetrics(
      `chore(metrics): ${meta.type}${meta.batchSteps?.length ? ` ${meta.batchSteps.join(',')}` : ''} · ${sessionId.slice(0, 8)}`
    );
    log(
      `  metrics: ${meta.type} recorded — tokens total=${tokens.total} duration=${Math.round(durationMs / 1000)}s`
    );
  }

  function setSessionStatus(status) {
    syncMetricsFromTarget();
    const session = loadSession();
    if (!session || session.status === status) {
      discardMetricsWorkingTree();
      return;
    }
    session.status = status;
    if (status === 'completed' || status === 'failed' || status === 'cancelled') {
      session.completedAt = new Date().toISOString();
    }
    recomputeTotals(session);
    writeJson(sessionPath(), session);
    touchIndex(session);
    commitMetrics(`chore(metrics): session ${sessionId.slice(0, 8)} ${status}`);
  }

  function getSessionId() {
    return sessionId;
  }

  return {
    initSession,
    recordPhase,
    setSessionStatus,
    getSessionId,
    sessionId: () => sessionId
  };
}

export function resolveSessionId(raw) {
  const trimmed = (raw || '').trim();
  if (trimmed) {
    if (!UUID_RE.test(trimmed)) {
      throw new Error(`Invalid SESSION_ID (expected UUID v4): ${trimmed}`);
    }
    return trimmed.toLowerCase();
  }
  return randomUUID();
}

export function parseRoadmapStepId(title, taskPrompt) {
  const t = (title || '').trim();
  const fromTitle = t.match(/(?:step\s+)?(\d+\.\d+(?:\.\d+[a-z]?)?)/i);
  if (fromTitle) return fromTitle[1].toLowerCase();

  const base = basename(taskPrompt || '').replace(/\.md$/i, '');
  const three = base.match(/^PROMPT_(\d+)_(\d+)_(\d+[a-z]?)_/i);
  if (three) return `${three[1]}.${three[2]}.${three[3]}`.toLowerCase();

  const two = base.match(/^PROMPT_(\d+)_(\d+)_/i);
  if (two) return `${two[1]}.${two[2]}`.toLowerCase();

  // Audit/report prompts and feature branches use an underscore step form anywhere in the name
  // (e.g. AGENT_PROMPT_AUDIT_STEP_2_1 or ..._STEP_2_1_3). Without this, audits — which usually pass
  // no title and don't follow the PROMPT_X_Y_ convention — fall into the shared "unknown" bucket.
  const step = base.match(/STEP_(\d+)_(\d+)(?:_(\d+[a-z]?))?/i);
  if (step) return [step[1], step[2], step[3]].filter(Boolean).join('.').toLowerCase();

  return null;
}

export function parsePhaseLabel(label) {
  const retry = label.match(/\(retry (\d+)\)/i);
  const attempt = retry ? Number(retry[1]) : 0;
  const base = label.replace(/\s*\(retry \d+\)/i, '').trim();
  if (base.startsWith('EXECUTE')) {
    const rest = base.replace(/^EXECUTE\s*/i, '').trim();
    const batchSteps = rest
      ? rest
          .split(',')
          .map(s => Number(s.trim()))
          .filter(n => !Number.isNaN(n))
      : [];
    return { type: 'EXECUTE', batchSteps, attempt };
  }
  if (base === 'PLAN') return { type: 'PLAN', batchSteps: null, attempt };
  if (base === 'FINALIZE') return { type: 'FINALIZE', batchSteps: null, attempt };
  return { type: base, batchSteps: null, attempt };
}

function inferOutcome(result) {
  const text = String(result || '');
  if (text.startsWith('ESCALATE')) return 'escalate';
  if (/^Plan ready:/i.test(text) || /^Finalized\b/i.test(text)) return 'success';
  if (/^Step \d+ done:/i.test(text)) return 'success';
  return 'success';
}
