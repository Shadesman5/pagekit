#!/usr/bin/env node
// Import V1 XL Review+E2E tokens into the in-progress Conductor session.
// Called from Conductor FINALIZE (same workflow_dispatch job — not a feature-branch
// CI check). Missing agent / missing session is a skip, never a failed check.
// Full V1 tickets (no Conductor session) still use import-v1-metrics.yml at merge.

import { readFileSync, existsSync } from 'node:fs';
import { join, dirname, basename, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { execFileSync } from 'node:child_process';
import {
  INDEX_PATH,
  SESSIONS_DIR,
  createCursorClient,
  fetchAgentUsageFromCursor,
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
const MAX_LIST_PAGES = 4;

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

export function hyphenate(value) {
  return String(value || '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');
}

/** `feature/snapshot-three-stage-uninstall` → `snapshot-three-stage-uninstall` */
export function branchSlug(branchNorm) {
  return hyphenate(
    String(branchNorm || '')
      .replace(/^refs\/heads\//, '')
      .replace(/^feature\//, '')
  );
}

export function lastSessionPhaseAt(session) {
  let max = 0;
  for (const phase of session?.phases || []) {
    const t = Date.parse(phase.completedAt || phase.startedAt || '') || 0;
    if (t > max) max = t;
  }
  return max || null;
}

/** Newest unused parent with usage. Prefer a name/branch hit over a random newer agent. */
export function pickXlHandoffAgent(candidates, excludeIds = []) {
  const exclude = new Set((excludeIds || []).map(id => String(id || '').toLowerCase()));
  const eligible = (candidates || []).filter(c => {
    const id = String(c.id || '').toLowerCase();
    return id.startsWith('bc-') && !exclude.has(id) && (Number(c.total) || 0) > 0;
  });
  if (!eligible.length) return null;
  const named = eligible.filter(c => c.nameHit || c.branchHit);
  const pool = named.length ? named : eligible;
  pool.sort((a, b) => {
    const ta = Date.parse(a.createdAt || a.startedAt || '') || 0;
    const tb = Date.parse(b.createdAt || b.startedAt || '') || 0;
    if (tb !== ta) return tb - ta;
    return (Number(b.total) || 0) - (Number(a.total) || 0);
  });
  return pool[0].id.toLowerCase();
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

/**
 * V1 UI agents often have no `target.branchName`. Their `name` is the ticket title
 * ("Snapshot three-stage uninstall"), not `feature/<slug>`.
 */
export function listFieldsMatchBranch(item, branchNorm) {
  const norm = String(branchNorm || '')
    .replace(/^refs\/heads\//, '')
    .toLowerCase();
  if (!norm) return 'no';
  const slug = branchSlug(norm);
  const b = agentBranch(item);
  const nameHyph = hyphenate(item?.name);
  if (b === norm || b === slug || (slug && b.endsWith(`/${slug}`))) return 'yes';
  if (slug && nameHyph && (nameHyph === slug || nameHyph.includes(slug))) return 'yes';
  if (!b) return 'unknown';
  return 'no';
}

export async function resolveXlHandoffAgent(
  client,
  { branch, excludeIds = [], after = null, log = () => {} } = {}
) {
  const branchNorm = String(branch || '')
    .replace(/^refs\/heads\//, '')
    .toLowerCase();
  if (!client) return null;
  const exclude = new Set((excludeIds || []).map(id => String(id || '').toLowerCase()));
  const afterMs = after ? Date.parse(after) || Number(after) || 0 : 0;
  const named = [];
  const unknown = [];
  let cursor = null;
  for (let page = 0; page < MAX_LIST_PAGES; page += 1) {
    const res = await listAgentsFromCursor(client, { limit: 50, cursor });
    for (const item of res.items) {
      const id = String(item.id || '').toLowerCase();
      if (!id.startsWith('bc-') || exclude.has(id)) continue;
      const createdAt = item.createdAt || item.created_at || null;
      const createdMs = Date.parse(createdAt || '') || 0;
      if (afterMs && createdMs && createdMs < afterMs) continue;
      const listed = branchNorm ? listFieldsMatchBranch(item, branchNorm) : 'unknown';
      if (listed === 'no') continue;
      const row = { id, createdAt };
      if (listed === 'yes') named.push(row);
      else unknown.push(row);
    }
    cursor = res.nextCursor;
    if (!cursor) break;
  }

  async function score(rows, nameHit) {
    const scored = [];
    for (const row of rows) {
      let total = 0;
      try {
        const tokens = await fetchAgentUsageFromCursor(client, row.id);
        total = Number(tokens?.total) || 0;
      } catch {
        /* keep 0 */
      }
      if (total <= 0) continue;
      scored.push({
        id: row.id,
        total,
        createdAt: row.createdAt,
        nameHit,
        branchHit: nameHit
      });
    }
    return scored;
  }

  const namedScored = await score(named, true);
  let picked = pickXlHandoffAgent(namedScored, []);
  if (picked) {
    log(`resolve-xl: picked ${picked} (name/branch match)`);
    return picked;
  }
  const unknownScored = await score(unknown, false);
  picked = pickXlHandoffAgent(unknownScored, []);
  if (picked) {
    log(`resolve-xl: picked ${picked} (usage fallback)`);
    return picked;
  }
  log('resolve-xl: no unused parent agent with usage after last Conductor phase');
  return null;
}

async function resolveAgentById(client, agentId, log) {
  const id = String(agentId || '').toLowerCase();
  if (!id.startsWith('bc-')) return null;
  try {
    const tokens = await fetchAgentUsageFromCursor(client, id);
    const total = Number(tokens?.total) || 0;
    if (total > 0) {
      log(`resolve-xl: using ${id} (usage=${total})`);
      return id;
    }
    log(`resolve-xl: ${id} has zero usage`);
  } catch (e) {
    log(`resolve-xl: GET ${id} failed (${e.message})`);
  }
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

async function waitForXlAgent(client, { branch, excludeIds, after, attempts, delayMs, log }) {
  for (let i = 1; i <= attempts; i += 1) {
    const id = await resolveXlHandoffAgent(client, { branch, excludeIds, after, log });
    if (id) return id;
    log(`no unused XL parent agent with usage yet (attempt ${i}/${attempts})`);
    if (i < attempts) await sleep(delayMs);
  }
  return null;
}

function runImporter({ session, stepId, agentId, branch, doPush, dryRun, log }) {
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
  log(`import-manual-agents ${args.slice(1).join(' ')}`);
  execFileSync(process.execPath, args, { cwd: ROOT, stdio: 'inherit' });
}

/**
 * Best-effort import. Never throws for "nothing to import".
 * @returns {Promise<{ imported: boolean, reason?: string, agentId?: string, sessionId?: string }>}
 */
export async function importXlHandoffForSession({
  sessionId = null,
  branch,
  ticketPath = '',
  stepId: stepIdArg = null,
  agentId: agentIdArg = null,
  push = false,
  dryRun = false,
  attempts = 2,
  delayMs = 8000,
  env = process.env,
  log = msg => console.log(msg)
} = {}) {
  let stepId = stepIdArg;
  const ticketAbs = ticketPath ? join(ROOT, ticketPath) : '';
  if (!stepId && ticketAbs && existsSync(ticketAbs)) {
    stepId = parseTicketRoadmapStepId(readFileSync(ticketAbs, 'utf8'), ticketPath);
  }
  if (!stepId) return { imported: false, reason: 'no ROADMAP step id' };

  if (!dryRun) {
    syncMetricsFromRemote({ root: ROOT, log });
  }

  const session = sessionId
    ? readJson(join(ROOT, SESSIONS_DIR, `${sessionId}.json`))
    : pickXlHandoffSession(loadSessionsForStep(stepId), { branch });
  if (!session) {
    return { imported: false, reason: `no Conductor session for ${stepId}` };
  }

  const apiKey = (env.CURSOR_API_KEY || '').trim();
  if (!apiKey) return { imported: false, reason: 'CURSOR_API_KEY missing' };

  const client = createCursorClient(apiKey);
  const after = lastSessionPhaseAt(session);
  let agentId = null;
  if (agentIdArg) {
    agentId = await resolveAgentById(client, agentIdArg, log);
  }
  if (!agentId) {
    agentId = await waitForXlAgent(client, {
      branch,
      excludeIds: sessionAgentIds(session),
      after,
      attempts,
      delayMs,
      log
    });
  }
  if (!agentId) {
    return {
      imported: false,
      sessionId: session.sessionId,
      reason: `no unused V1 parent with usage after last Conductor phase on ${branch}`
    };
  }

  runImporter({
    session,
    stepId,
    agentId,
    branch,
    doPush: push,
    dryRun,
    log
  });
  return { imported: true, agentId, sessionId: session.sessionId };
}

export async function main(argv = process.argv, env = process.env, io = console) {
  const dryRun = argv.includes('--dry-run');
  const doPush = argv.includes('--push');
  const branch = (getArg('--branch', argv) || env.GITHUB_REF_NAME || '').replace(
    /^refs\/heads\//,
    ''
  );
  const diffPath = getArg('--diff', argv);
  const sessionId = getArg('--session', argv);
  const ticketPath = getArg('--ticket', argv);
  const stepId = getArg('--step', argv);
  const agentId = getArg('--agent', argv);
  const attempts = Number(env.XL_IMPORT_ATTEMPTS || 2);
  const delayMs = Number(env.XL_IMPORT_DELAY_MS || 8000);

  let resolvedTicket = ticketPath;
  if (diffPath) {
    const ticks = detectXlTicks(readFileSync(diffPath, 'utf8'));
    if (!ticks.length) {
      io.log('No XL checkbox tick in this diff — skip.');
      return 0;
    }
    resolvedTicket = resolvedTicket || ticks[0].file;
    io.log(`XL tick: checklist step ${ticks[0].checklistStep} in ${ticks[0].file}`);
  }

  if (!sessionId && !resolvedTicket && !stepId) {
    io.log('Nothing to import (pass --session / --ticket / --diff) — skip.');
    return 0;
  }

  const result = await importXlHandoffForSession({
    sessionId,
    branch,
    ticketPath: resolvedTicket,
    stepId,
    agentId,
    push: doPush,
    dryRun,
    attempts,
    delayMs,
    env,
    log: msg => io.log(msg)
  });
  if (!result.imported) {
    io.log(`XL metrics skip: ${result.reason}`);
    return 0;
  }
  io.log(`XL metrics imported agent=${result.agentId} session=${result.sessionId}`);
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
