#!/usr/bin/env node
// Import V1 XL Review+E2E tokens into the in-progress Conductor session.
// Trigger: the commit that ticks `- [ ] Step N (XL)` → `- [x]`. Runs before FINALIZE
// so phases stay PLAN → EXECUTE… → XL → FINALIZE. Full V1 tickets (no Conductor
// session) are skipped — import-v1-metrics.yml handles those at merge.

import { readFileSync, existsSync } from 'node:fs';
import { join, dirname, basename, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { execFileSync } from 'node:child_process';
import {
  INDEX_PATH,
  SESSIONS_DIR,
  createCursorClient,
  fetchAgentMetricsFromCursor,
  fetchAgentFromCursor,
  listAgentsFromCursor,
  parseRoadmapStepId,
  sessionAgentIds,
  syncMetricsFromRemote
} from './metrics.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');

const UNCHECKED_XL = /^-\s*-\s*\[\s\]\s*Step\s+(\d+)\s*\(XL\)/i;
const CHECKED_XL = /^\+\s*-\s*\[x\]\s*Step\s+(\d+)\s*\(XL\)/i;
const DIFF_FILE = /^\+\+\+\s+b\/(.+)$/;
const ROADMAP_STEP = /\*\*Current Step \(ROADMAP\):\*\*\s*(\d+\.\d+(?:\.\d+[a-z]?)?)/i;

export function detectXlTicks(unifiedDiff) {
  const ticks = [];
  let file = null;
  const removed = new Set();
  const added = new Set();
  const flush = () => {
    for (const n of added) {
      if (removed.has(n) && file) ticks.push({ file, checklistStep: n });
    }
    removed.clear();
    added.clear();
  };
  for (const raw of String(unifiedDiff || '').split(/\r?\n/)) {
    const fileHit = raw.match(DIFF_FILE);
    if (fileHit) {
      flush();
      file = fileHit[1].replace(/\\/g, '/');
      continue;
    }
    const off = raw.match(UNCHECKED_XL);
    if (off) removed.add(Number(off[1]));
    const on = raw.match(CHECKED_XL);
    if (on) added.add(Number(on[1]));
  }
  flush();
  return ticks.filter(t => t.file.includes('/tickets/active/') && t.file.endsWith('_plan.md'));
}

export function parseTicketRoadmapStepId(markdown, ticketPath = '') {
  const fromBody = String(markdown || '').match(ROADMAP_STEP);
  if (fromBody) return fromBody[1].toLowerCase();
  return parseRoadmapStepId(null, ticketPath);
}

export function pickXlHandoffSession(sessions, { branch } = {}) {
  const open = (sessions || []).filter(s => s?.status === 'in_progress');
  if (!open.length) return null;
  const branchNorm = String(branch || '')
    .replace(/^refs\/heads\//, '')
    .toLowerCase();
  const onBranch = branchNorm
    ? open.filter(s => String(s.branch || '').toLowerCase() === branchNorm)
    : [];
  const pool = onBranch.length ? onBranch : open;
  return [...pool].sort((a, b) =>
    String(b.startedAt || '').localeCompare(String(a.startedAt || ''))
  )[0];
}

/** Newest unused parent agent with usage. Conductor phases are already in excludeIds. */
export function pickXlHandoffAgent(candidates, excludeIds = []) {
  const exclude = new Set((excludeIds || []).map(id => String(id || '').toLowerCase()));
  const eligible = (candidates || []).filter(c => {
    const id = String(c.id || '').toLowerCase();
    return id.startsWith('bc-') && !exclude.has(id) && (Number(c.total) || 0) > 0;
  });
  if (!eligible.length) return null;
  eligible.sort((a, b) => {
    const ta = Date.parse(a.createdAt || a.startedAt || '') || 0;
    const tb = Date.parse(b.createdAt || b.startedAt || '') || 0;
    if (tb !== ta) return tb - ta;
    return (Number(b.total) || 0) - (Number(a.total) || 0);
  });
  return eligible[0].id.toLowerCase();
}

function agentBranch(detail, fallback = {}) {
  return String(
    detail?.target?.branchName ||
      detail?.branchName ||
      detail?.source?.ref ||
      fallback?.target?.branchName ||
      fallback?.branchName ||
      ''
  )
    .replace(/^refs\/heads\//, '')
    .toLowerCase();
}

export async function resolveXlHandoffAgent(
  client,
  { branch, excludeIds = [], log = () => {} } = {}
) {
  const branchNorm = String(branch || '')
    .replace(/^refs\/heads\//, '')
    .toLowerCase();
  if (!client || !branchNorm) return null;
  const exclude = new Set((excludeIds || []).map(id => String(id || '').toLowerCase()));
  const scored = [];
  let cursor = null;
  for (let page = 0; page < 5; page += 1) {
    const res = await listAgentsFromCursor(client, { limit: 50, cursor });
    for (const item of res.items) {
      const id = String(item.id || '').toLowerCase();
      if (!id.startsWith('bc-') || exclude.has(id)) continue;
      let detail = item;
      try {
        detail = (await fetchAgentFromCursor(client, id)) || item;
      } catch {
        /* list fields only */
      }
      const name = String(detail?.name || item.name || '').toLowerCase();
      if (agentBranch(detail, item) !== branchNorm && !name.includes(branchNorm)) continue;
      let total = 0;
      try {
        const metrics = await fetchAgentMetricsFromCursor(client, id);
        total = Number(metrics?.tokens?.total) || 0;
      } catch {
        /* keep 0 */
      }
      scored.push({
        id,
        total,
        createdAt: detail?.createdAt || detail?.created_at || item.createdAt || null
      });
    }
    const picked = pickXlHandoffAgent(scored, []);
    if (picked) {
      log(`resolve-xl: picked ${picked}`);
      return picked;
    }
    cursor = res.nextCursor;
    if (!cursor) break;
  }
  log(`resolve-xl: no unused parent agent with usage on ${branchNorm}`);
  return null;
}

function getArg(name, argv) {
  const i = argv.indexOf(name);
  return i >= 0 ? argv[i + 1] : null;
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function readJson(path, fallback = null) {
  if (!existsSync(path)) return fallback;
  return JSON.parse(readFileSync(path, 'utf8'));
}

function loadSessionsForStep(stepId) {
  const index = readJson(join(ROOT, INDEX_PATH), { steps: {} });
  const ids = index.steps[stepId]?.sessionIds || [];
  return ids.map(id => readJson(join(ROOT, SESSIONS_DIR, `${id}.json`))).filter(Boolean);
}

async function waitForXlAgent(client, { branch, excludeIds, attempts, delayMs, log }) {
  for (let i = 1; i <= attempts; i += 1) {
    const id = await resolveXlHandoffAgent(client, { branch, excludeIds, log });
    if (id) return id;
    log(`no unused XL parent agent with usage yet (attempt ${i}/${attempts})`);
    if (i < attempts) await sleep(delayMs);
  }
  return null;
}

export async function main(argv = process.argv, env = process.env, io = console) {
  const dryRun = argv.includes('--dry-run');
  const doPush = argv.includes('--push');
  const branch = (getArg('--branch', argv) || env.GITHUB_REF_NAME || '').replace(
    /^refs\/heads\//,
    ''
  );
  const diffPath = getArg('--diff', argv);
  const attempts = Number(env.XL_IMPORT_ATTEMPTS || 10);
  const delayMs = Number(env.XL_IMPORT_DELAY_MS || 20000);

  if (!diffPath) {
    io.error('Missing --diff <unified-diff-file>');
    process.exit(1);
  }
  const diff = readFileSync(diffPath, 'utf8');
  const ticks = detectXlTicks(diff);
  if (!ticks.length) {
    io.log('No XL checkbox tick in this diff — skip.');
    return 0;
  }

  const tick = ticks[0];
  const ticketAbs = join(ROOT, tick.file);
  if (!existsSync(ticketAbs)) {
    io.error(`Ticket not in checkout: ${tick.file}`);
    process.exit(1);
  }
  const stepId = parseTicketRoadmapStepId(readFileSync(ticketAbs, 'utf8'), tick.file);
  if (!stepId) {
    io.error(`Could not read Current Step (ROADMAP) from ${tick.file}`);
    process.exit(1);
  }
  io.log(`XL tick: checklist step ${tick.checklistStep} in ${tick.file} → ROADMAP ${stepId}`);

  if (!dryRun) {
    syncMetricsFromRemote({ root: ROOT, log: msg => io.log(msg) });
  }
  const session = pickXlHandoffSession(loadSessionsForStep(stepId), { branch });
  if (!session) {
    io.log(
      `No in_progress Conductor session for ${stepId} — skip (full V1 tickets use import-v1-metrics.yml).`
    );
    return 0;
  }
  io.log(`Session ${session.sessionId} (${session.status}, branch=${session.branch || '—'})`);

  const apiKey = (env.CURSOR_API_KEY || '').trim();
  if (!apiKey) {
    io.error('CURSOR_API_KEY is required');
    process.exit(1);
  }
  const client = createCursorClient(apiKey);
  const agentId = await waitForXlAgent(client, {
    branch,
    excludeIds: sessionAgentIds(session),
    attempts,
    delayMs,
    log: msg => io.log(msg)
  });
  if (!agentId) {
    io.error(
      `Could not resolve a parent cloud agent on branch ${branch}. ` +
        `Import manually: CURSOR_API_KEY=… node .github/conductor/import-manual-agents.mjs ` +
        `--step ${stepId} --session ${session.sessionId} --branch ${branch} --label EXECUTE --agent bc-… --push`
    );
    process.exit(1);
  }

  const args = [
    join(ROOT, '.github/conductor/import-manual-agents.mjs'),
    '--step',
    stepId,
    '--session',
    session.sessionId,
    '--agent',
    agentId,
    '--label',
    'EXECUTE',
    '--branch',
    branch
  ];
  if (session.title) args.push('--title', session.title);
  if (session.taskSlug) args.push('--task-slug', session.taskSlug);
  if (session.issue != null) args.push('--issue', String(session.issue));
  if (doPush) args.push('--push');
  if (dryRun) args.push('--dry-run');
  io.log(`import-manual-agents ${args.slice(1).join(' ')}`);
  execFileSync(process.execPath, args, { cwd: ROOT, stdio: 'inherit' });
  return 0;
}

const invoked = process.argv[1] && pathToFileURL(resolve(process.argv[1])).href;
if (
  invoked === import.meta.url ||
  (process.argv[1] && basename(process.argv[1]) === basename(fileURLToPath(import.meta.url)))
) {
  main().then(
    code => {
      if (Number.isInteger(code)) process.exit(code);
    },
    e => {
      console.error(e.stack || e.message);
      process.exit(1);
    }
  );
}
