#!/usr/bin/env node
// Backfill Conductor metrics from GitHub Actions run logs (historical runs before live collection).
//
// Usage:
//   node .github/conductor/backfill-metrics.mjs [--dry-run] [--limit N] [--run-id ID]
//
// Fetches ALL job attempts per workflow run (gh run view --log only shows the latest attempt).
// Requires: gh CLI authenticated, Node 20+

import { execFileSync } from 'node:child_process';
import { writeFileSync, mkdirSync, readFileSync, existsSync } from 'node:fs';
import { createHash } from 'node:crypto';
import {
  INDEX_PATH,
  SESSIONS_DIR,
  SCHEMA_VERSION,
  parseRoadmapStepId,
  parsePhaseLabel,
  createCursorClient,
  enrichSessionTokensFromCursor,
  recomputeSessionTotals
} from './metrics.mjs';
import { parseRoadmapTable } from './sync-roadmap-snapshot.mjs';

const DRY_RUN = process.argv.includes('--dry-run');
const SKIP_CURSOR = process.argv.includes('--skip-cursor');
const LIMIT = Number(getArg('--limit') || 50);
const SINGLE_RUN = getArg('--run-id');
const REPO = process.env.GITHUB_REPOSITORY || 'Shadesman5/pagekit';
const SERVER = (process.env.GITHUB_SERVER_URL || 'https://github.com').replace(/\/$/, '');

const RE_START =
  /Conductor start — slug=([^\s]+) branch=([^\s]+) base=([^\s]+) budget=(\d+) model=([^\s]+) mode=([^\s(]+)/;
const RE_PHASE = /▶ (.+?): launching cloud agent \(model=([^)]+)\)/;
const RE_AGENT = /agent=([^\s]+) run=([^\s]+)(?: url=(\S+))?/;
const RE_TOKENS =
  /tokens: in=(\d+|[^\s]+) out=(\d+|[^\s]+) cacheR=(\d+|[^\s]+) cacheW=(\d+|[^\s]+) total=(\d+|[^\s]+)/;
const RE_RESULT = /(?:PLAN|EXECUTE|FINALIZE) result: (.+)/;
const RE_EXECUTE_RESULT = /EXECUTE result: (.+)/;
const RE_CANCEL = /The operation was canceled/i;

function getArg(name) {
  const i = process.argv.indexOf(name);
  return i >= 0 ? process.argv[i + 1] : null;
}

function sh(args) {
  return execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).trim();
}

function ghApi(path) {
  return JSON.parse(sh(['api', path]));
}

function sessionIdFromRun(runId) {
  const hash = createHash('sha256').update(`conductor-backfill:${runId}`).digest('hex');
  return `${hash.slice(0, 8)}-${hash.slice(8, 12)}-4${hash.slice(13, 16)}-8${hash.slice(17, 20)}-${hash.slice(20, 32)}`;
}

function parseNum(v) {
  if (v === '?' || v == null) return null;
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
}

function emptyTokens() {
  return { input: null, output: null, cacheRead: null, cacheWrite: null, total: null };
}

function addTokens(a, b) {
  const sum = (x, y) => (x == null && y == null ? null : (x || 0) + (y || 0));
  return {
    input: sum(a.input, b.input),
    output: sum(a.output, b.output),
    cacheRead: sum(a.cacheRead, b.cacheRead),
    cacheWrite: sum(a.cacheWrite, b.cacheWrite),
    total: sum(a.total, b.total)
  };
}

function extractLogTimestamp(line) {
  const m = line.match(/(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z)/);
  return m ? m[1] : null;
}

function parseLogLines(logText, ctx) {
  const lines = logText.split('\n');
  let start = null;
  const phases = [];
  let current = null;
  let cancelledAt = null;

  for (const raw of lines) {
    const ts = extractLogTimestamp(raw);
    const content = raw.includes('Z ') ? raw.slice(raw.indexOf('Z ') + 2) : raw;

    if (RE_CANCEL.test(content)) {
      cancelledAt = ts || cancelledAt;
      continue;
    }

    const sm = content.match(RE_START);
    if (sm) {
      start = {
        slug: sm[1],
        branch: sm[2],
        base: sm[3],
        budget: Number(sm[4]),
        model: sm[5] === 'default' ? null : sm[5],
        mode: sm[6],
        audit: content.includes('(audit/report)')
      };
      continue;
    }

    const pm = content.match(RE_PHASE);
    if (pm) {
      if (current) phases.push(current);
      current = {
        label: pm[1],
        model: pm[2] === 'default' ? null : pm[2],
        startedAt: ts,
        agent: { id: null, runId: null, url: null, model: pm[2] === 'default' ? null : pm[2] },
        tokens: emptyTokens(),
        result: null,
        outcome: null,
        durationMs: null,
        github: {
          runId: ctx.databaseId,
          runAttempt: ctx.runAttempt,
          jobId: ctx.jobId,
          jobConclusion: ctx.jobConclusion,
          jobUrl: ctx.jobUrl,
          workflow: ctx.workflowName || 'Conductor (V2)',
          runUrl: `${SERVER}/${REPO}/actions/runs/${ctx.databaseId}`,
          repository: REPO
        },
        phaseKey: `${ctx.databaseId}-${ctx.runAttempt}-${ctx.jobId}-${pm[1]}`,
        backfill: true
      };
      continue;
    }

    if (current) {
      const am = content.match(RE_AGENT);
      if (am) {
        current.agent = {
          id: am[1],
          runId: am[2],
          url: am[3] || `https://cursor.com/agents/${am[1]}`,
          model: current.model
        };
        continue;
      }

      const tm = content.match(RE_TOKENS);
      if (tm) {
        current.tokens = {
          input: parseNum(tm[1]),
          output: parseNum(tm[2]),
          cacheRead: parseNum(tm[3]),
          cacheWrite: parseNum(tm[4]),
          total: parseNum(tm[5])
        };
        if (current.startedAt && ts) {
          current.durationMs = Math.max(0, Date.parse(ts) - Date.parse(current.startedAt));
        }
        continue;
      }

      const rm = content.match(RE_RESULT) || content.match(RE_EXECUTE_RESULT);
      if (rm) {
        current.result = rm[1].slice(0, 500);
        current.outcome = current.result.startsWith('ESCALATE') ? 'escalate' : 'success';
      }
    }
  }

  if (current) {
    if (cancelledAt && current.tokens.total == null) {
      current.outcome = 'cancelled';
      const agentHint = current.agent?.id
        ? ` Agent ${current.agent.id} may have finished on Cursor after GHA cancel.`
        : '';
      current.result = `GitHub Actions job cancelled (360-minute limit).${agentHint}`;
      if (current.startedAt) {
        current.durationMs = Math.max(0, Date.parse(cancelledAt) - Date.parse(current.startedAt));
      }
      current.notes = current.agent?.id
        ? 'No token line in GHA log — fetch via Cursor /usage API if needed'
        : 'Phase started but no token line before job cancellation';
    }
    phases.push(current);
  }

  return { start, phases, cancelledAt };
}

function inferSessionStatus(runMeta, phases) {
  if (runMeta.conclusion === 'success') return 'completed';
  if (runMeta.conclusion === 'cancelled') return phases.length ? 'cancelled' : 'cancelled';
  if (runMeta.conclusion === 'failure') return phases.length ? 'failed' : 'failed';
  return 'failed';
}

function buildSession(runMeta, start, phases, logSnippet) {
  const slug = start?.slug || guessSlugFromTitle(runMeta.displayTitle);
  const title = (runMeta.displayTitle || '').trim() || slug;
  const roadmapStepId =
    parseRoadmapStepId(title, `${slug}.md`) || parseRoadmapStepId('', `${slug}.md`);

  const sessionId = sessionIdFromRun(runMeta.databaseId);
  const startedAt = phases[0]?.startedAt || runMeta.createdAt;

  const totals = {
    tokens: emptyTokens(),
    durationMs: 0,
    phaseCount: phases.length,
    escalations: 0,
    ghaJobs: new Set(phases.map(p => p.github?.jobId).filter(Boolean)).size || 1,
    cloudAgents: phases.filter(p => p.agent?.id).length
  };
  for (const p of phases) {
    totals.tokens = addTokens(totals.tokens, p.tokens);
    totals.durationMs += p.durationMs || 0;
    if (p.outcome === 'escalate') totals.escalations += 1;
  }

  const status = inferSessionStatus(runMeta, phases);
  const issue = lookupIssue(roadmapStepId);

  return {
    schemaVersion: SCHEMA_VERSION,
    sessionId,
    roadmapStepId,
    title: title.replace(/^Conductor \(V2\)$/i, slug) || null,
    taskSlug: slug,
    taskPrompt: slug && slug !== 'unknown' ? `migration-docs/TODO/agent_prompts/${slug}.md` : null,
    issue,
    branch: start?.branch || null,
    model: start?.model || null,
    status,
    startedAt,
    completedAt: runMeta.updatedAt || runMeta.createdAt,
    phases: phases.map(p => {
      const meta = parsePhaseLabel(p.label);
      return {
        phaseKey: p.phaseKey,
        type: meta.type,
        attempt: meta.attempt,
        batchSteps: meta.batchSteps,
        startedAt: p.startedAt,
        durationMs: p.durationMs,
        agent: p.agent,
        github: p.github,
        tokens: p.tokens,
        result: p.result,
        outcome: p.outcome || (p.tokens.total != null ? 'success' : 'unknown'),
        backfill: true,
        notes:
          p.notes ||
          (p.tokens.total == null && p.outcome !== 'cancelled'
            ? 'Token data not found in logs'
            : null)
      };
    }),
    totals,
    backfill: {
      source: 'github-actions-logs',
      workflowRunId: runMeta.databaseId,
      workflowRunAttempt: runMeta.attempt,
      conclusion: runMeta.conclusion,
      createdAt: runMeta.createdAt,
      displayTitle: runMeta.displayTitle,
      logAvailable: phases.length > 0 || !!start,
      jobAttemptsMerged: totals.ghaJobs
    }
  };
}

let roadmapIssueMap = null;

function lookupIssue(stepId) {
  if (!stepId || !roadmapIssueMap) return null;
  return roadmapIssueMap.get(stepId) ?? null;
}

function loadRoadmapIssueMap() {
  roadmapIssueMap = new Map();
  try {
    const md = readFileSync('.cursor/ROADMAP.md', 'utf8');
    for (const row of parseRoadmapTable(md).rows) {
      if (row.issue) roadmapIssueMap.set(row.id, row.issue);
    }
  } catch {
    /* optional */
  }
}

function guessSlugFromTitle(title) {
  const t = (title || '').trim();
  if (!t || t === 'Conductor (V2)') return 'unknown';
  return t.replace(/^Step [\d.]+:\s*/i, '').replace(/\s+/g, '-');
}

function listRuns() {
  if (SINGLE_RUN) {
    const json = sh([
      'run',
      'view',
      SINGLE_RUN,
      '--json',
      'databaseId,displayTitle,conclusion,status,createdAt,updatedAt,workflowName,attempt'
    ]);
    return [JSON.parse(json)];
  }
  const json = sh([
    'run',
    'list',
    '--workflow=conductor.yml',
    `--limit=${LIMIT}`,
    '--json',
    'databaseId,displayTitle,conclusion,status,createdAt,updatedAt,workflowName,attempt'
  ]);
  return JSON.parse(json);
}

function listJobs(runId) {
  const data = ghApi(`repos/${REPO}/actions/runs/${runId}/jobs?per_page=100&filter=all`);
  return (data.jobs || []).sort((a, b) => (a.run_attempt || 0) - (b.run_attempt || 0));
}

function fetchJobLog(jobId) {
  try {
    return sh(['api', `repos/${REPO}/actions/jobs/${jobId}/logs`]);
  } catch {
    return '';
  }
}

function parseRun(runMeta) {
  const jobs = listJobs(runMeta.databaseId);
  if (!jobs.length) {
    return buildSession(runMeta, null, [], '');
  }

  let start = null;
  const phases = [];
  let logSnippet = '';

  for (const job of jobs) {
    const log = fetchJobLog(job.id);
    logSnippet = log.slice(0, 5000) || logSnippet;
    const ctx = {
      databaseId: runMeta.databaseId,
      runAttempt: job.run_attempt,
      jobId: job.id,
      jobConclusion: job.conclusion,
      jobUrl: job.html_url,
      workflowName: runMeta.workflowName
    };
    const parsed = parseLogLines(log, ctx);
    if (parsed.start && !start) start = parsed.start;
    phases.push(...parsed.phases);
  }

  if (!phases.length && runMeta.conclusion === 'failure') {
    phases.push({
      phaseKey: `${runMeta.databaseId}-unknown`,
      label: 'UNKNOWN',
      startedAt: runMeta.createdAt,
      durationMs: null,
      agent: { id: null, runId: null, url: null, model: null },
      github: {
        runId: runMeta.databaseId,
        runAttempt: runMeta.attempt || 1,
        workflow: runMeta.workflowName || 'Conductor (V2)',
        runUrl: `${SERVER}/${REPO}/actions/runs/${runMeta.databaseId}`,
        repository: REPO
      },
      tokens: emptyTokens(),
      result: 'Log unavailable or run failed before first phase',
      outcome: 'error',
      backfill: true,
      notes: 'No parseable phases in GHA log'
    });
  }

  return buildSession(runMeta, start, phases, logSnippet);
}

function updateIndex(index, session) {
  const stepId = session.roadmapStepId || 'unknown';
  if (!index.steps[stepId]) {
    index.steps[stepId] = {
      title: session.title || session.taskSlug,
      latestSessionId: session.sessionId,
      sessionIds: []
    };
  }
  const entry = index.steps[stepId];
  if (session.title) entry.title = session.title;
  if (!entry.sessionIds.includes(session.sessionId)) {
    entry.sessionIds.push(session.sessionId);
  }
  entry.sessionIds.sort((a, b) => {
    const sa = index._sessions?.[a]?.startedAt || '';
    const sb = index._sessions?.[b]?.startedAt || '';
    return sb.localeCompare(sa);
  });
  if (
    !entry.latestSessionId ||
    session.startedAt >= (index._sessions?.[entry.latestSessionId]?.startedAt || '')
  ) {
    entry.latestSessionId = session.sessionId;
  }
}

async function enrichSessionsFromCursor(sessions) {
  const apiKey = process.env.CURSOR_API_KEY;
  if (SKIP_CURSOR || !apiKey) {
    if (!SKIP_CURSOR && !apiKey) {
      console.log(
        '\nTip: set CURSOR_API_KEY to backfill token gaps via Cursor /v1/agents/{id}/usage'
      );
    }
    return 0;
  }

  const client = createCursorClient(apiKey);
  let enriched = 0;
  console.log('\nCursor API enrichment (phases missing token lines in GHA logs):');
  for (const session of sessions) {
    const before = session.totals?.tokens?.total;
    const { session: updated, enriched: n } = await enrichSessionTokensFromCursor(session, client, {
      log: msg => console.log(msg)
    });
    if (n) {
      enriched += n;
      Object.assign(session, updated);
      console.log(
        `  session ${session.sessionId.slice(0, 8)}… +${n} phase(s) · tokens ${before ?? 'N/A'} → ${session.totals.tokens.total ?? 'N/A'}`
      );
    }
  }
  return enriched;
}

async function main() {
  loadRoadmapIssueMap();
  const runs = listRuns().sort((a, b) => a.createdAt.localeCompare(b.createdAt));
  console.log(
    `Backfill: ${runs.length} workflow run(s)${DRY_RUN ? ' (dry-run)' : ''} (all job attempts per run)`
  );

  const index = existsSync(INDEX_PATH)
    ? JSON.parse(readFileSync(INDEX_PATH, 'utf8'))
    : { schemaVersion: SCHEMA_VERSION, updatedAt: null, steps: {} };
  index._sessions = {};

  const sessions = [];
  for (const runMeta of runs) {
    const session = parseRun(runMeta);
    recomputeSessionTotals(session);
    index._sessions[session.sessionId] = session;
    sessions.push(session);
    console.log(
      `  run ${runMeta.databaseId} (attempt ${runMeta.attempt}) → step ${session.roadmapStepId || '?'} · ${session.phases.length} phase(s) · ${session.status} · tokens=${session.totals.tokens.total ?? 'N/A'} · jobs=${session.totals.ghaJobs}`
    );
  }

  const cursorEnriched = await enrichSessionsFromCursor(sessions);

  delete index._sessions;
  index.updatedAt = new Date().toISOString();
  index.schemaVersion = SCHEMA_VERSION;
  index.backfill = {
    at: index.updatedAt,
    runCount: sessions.length,
    cursorPhasesEnriched: cursorEnriched || undefined,
    note: 'Historical data from GHA logs (all job attempts merged per workflow run)'
  };

  for (const session of sessions) {
    updateIndex(index, session);
  }

  if (DRY_RUN) {
    console.log('\nDry-run complete. Pass without --dry-run to write files.');
    return;
  }

  mkdirSync(SESSIONS_DIR, { recursive: true });
  for (const session of sessions) {
    writeFileSync(
      `${SESSIONS_DIR}/${session.sessionId}.json`,
      `${JSON.stringify(session, null, 2)}\n`
    );
  }
  writeFileSync(INDEX_PATH, `${JSON.stringify(index, null, 2)}\n`);
  console.log(`\nWrote ${sessions.length} session(s) to ${SESSIONS_DIR}/ and ${INDEX_PATH}`);
}

main().catch(e => {
  console.error(e.stack || e.message);
  process.exit(1);
});
