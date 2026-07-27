#!/usr/bin/env node
// One-shot: completed ROADMAP steps → GHA log backfill → Cursor API token enrich → audit report.
//
// Usage:
//   CURSOR_API_KEY=… node .github/conductor/backfill-completed-steps.mjs [options]
//
// Options:
//   --dry-run              Report only, no writes
//   --skip-gha-backfill    Skip `backfill-metrics.mjs` (use existing session files)
//   --skip-cursor          Skip Cursor API enrichment
//   --copy-local           Copy metrics → docs-site/data/conductor-metrics for mkdocs preview
//   --status completed     Only steps with ✅ in ROADMAP (default)
//   --status all           All steps that have metrics index entries
//   --limit N              Pass through to backfill-metrics.mjs
//
// What is easy vs hard:
//   ✅ Steps run via Conductor V2 — agent IDs live in session JSON or GHA logs; tokens via /usage API.
//   ⚠️ Steps completed before V2 (manual / V1 orchestrator) — no cloud-agent trail; cannot recover tokens.

import { spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync, existsSync, mkdirSync, cpSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseRoadmapTable } from './sync-roadmap-snapshot.mjs';
import {
  INDEX_PATH,
  SESSIONS_DIR,
  METRICS_DIR,
  SCHEMA_VERSION,
  createCursorClient,
  enrichSessionTokensFromCursor
} from './metrics.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const ROADMAP = join(ROOT, '.cursor/ROADMAP.md');

const DRY_RUN = process.argv.includes('--dry-run');
const SKIP_GHA = process.argv.includes('--skip-gha-backfill');
const SKIP_CURSOR = process.argv.includes('--skip-cursor');
const COPY_LOCAL = process.argv.includes('--copy-local');
const STATUS_FILTER = getArg('--status') || 'completed';
const BACKFILL_LIMIT = getArg('--limit');

function getArg(name) {
  const i = process.argv.indexOf(name);
  return i >= 0 ? process.argv[i + 1] : null;
}

function readJson(path, fallback = null) {
  if (!existsSync(path)) return fallback;
  return JSON.parse(readFileSync(path, 'utf8'));
}

function writeJson(path, data) {
  writeFileSync(path, `${JSON.stringify(data, null, 2)}\n`, 'utf8');
}

function isCompletedStatus(status) {
  return String(status || '').includes('✅');
}

function loadCompletedSteps() {
  const md = readFileSync(ROADMAP, 'utf8');
  const { rows } = parseRoadmapTable(md);
  if (STATUS_FILTER === 'all') return rows;
  return rows.filter(r => isCompletedStatus(r.status));
}

function runGhaBackfill() {
  const args = ['.github/conductor/backfill-metrics.mjs'];
  if (DRY_RUN) args.push('--dry-run');
  if (SKIP_CURSOR) args.push('--skip-cursor');
  if (BACKFILL_LIMIT) args.push('--limit', BACKFILL_LIMIT);

  console.log('\n── Step 1: GHA log backfill ──');
  const res = spawnSync('node', args, { cwd: ROOT, stdio: 'inherit', env: process.env });
  if (res.status !== 0) {
    console.error('GHA backfill failed — continuing with existing session files.');
  }
}

function sessionIdsForStep(index, stepId) {
  return index?.steps?.[stepId]?.sessionIds || [];
}

function loadSession(sessionId) {
  return readJson(join(SESSIONS_DIR, `${sessionId}.json`));
}

function auditStep(stepId, index, sessions) {
  let phases = 0;
  let agents = 0;
  let missingTokens = 0;
  let totalTokens = 0;

  for (const s of sessions) {
    for (const p of s.phases || []) {
      phases += 1;
      if (p.agent?.id) agents += 1;
      if (p.agent?.id && p.tokens?.total == null) missingTokens += 1;
      if (p.tokens?.total != null) totalTokens += p.tokens.total;
    }
  }

  return { stepId, phases, agents, missingTokens, totalTokens, sessionCount: sessions.length };
}

async function enrichSessions(sessions, client) {
  let enriched = 0;
  for (const session of sessions) {
    const { session: updated, enriched: n } = await enrichSessionTokensFromCursor(session, client, {
      log: msg => console.log(msg)
    });
    if (n) {
      enriched += n;
      Object.assign(session, updated);
      if (!DRY_RUN) {
        writeJson(join(SESSIONS_DIR, `${session.sessionId}.json`), updated);
      }
    }
  }
  return enriched;
}

function copyToLocalPreview() {
  const dest = join(ROOT, 'docs-site/data/conductor-metrics');
  mkdirSync(dest, { recursive: true });
  cpSync(join(ROOT, METRICS_DIR), dest, { recursive: true, force: true });
  console.log(`\nCopied ${METRICS_DIR}/ → docs-site/data/conductor-metrics/`);
}

function printReport(title, rows) {
  console.log(`\n── ${title} ──`);
  if (!rows.length) {
    console.log('  (none)');
    return;
  }
  const pad = (s, n) => String(s).padEnd(n);
  console.log(
    `  ${pad('Step', 8)} ${pad('Sessions', 10)} ${pad('Phases', 8)} ${pad('Agents', 8)} ${pad('Missing', 10)} ${pad('Tokens', 14)}`
  );
  for (const r of rows) {
    console.log(
      `  ${pad(r.stepId, 8)} ${pad(r.sessionCount, 10)} ${pad(r.phases, 8)} ${pad(r.agents, 8)} ${pad(r.missingTokens, 10)} ${pad(r.totalTokens ? r.totalTokens.toLocaleString('de-DE') : '—', 14)}`
    );
  }
}

async function main() {
  const completedSteps = loadCompletedSteps();
  console.log(
    `Backfill completed steps: ${completedSteps.length} ROADMAP row(s) (${STATUS_FILTER})${DRY_RUN ? ' [dry-run]' : ''}`
  );

  if (!SKIP_GHA && !DRY_RUN) {
    runGhaBackfill();
  } else if (!SKIP_GHA && DRY_RUN) {
    console.log('\n── Step 1: GHA log backfill ── skipped (dry-run)');
  } else {
    console.log('\n── Step 1: GHA log backfill ── skipped (--skip-gha-backfill)');
  }

  const index = readJson(join(ROOT, INDEX_PATH), {
    schemaVersion: SCHEMA_VERSION,
    updatedAt: null,
    steps: {}
  });

  const withMetrics = [];
  const withoutMetrics = [];
  const allSessions = new Map();

  for (const row of completedSteps) {
    const ids = sessionIdsForStep(index, row.id);
    if (!ids.length) {
      withoutMetrics.push({ stepId: row.id, name: row.name });
      continue;
    }
    const sessions = ids.map(loadSession).filter(Boolean);
    sessions.forEach(s => allSessions.set(s.sessionId, s));
    withMetrics.push(auditStep(row.id, index, sessions));
  }

  console.log('\n── Step 2: Audit (before Cursor enrich) ──');
  printReport('With Conductor metrics', withMetrics);
  if (withoutMetrics.length) {
    console.log('\n── Completed steps WITHOUT V2 Conductor metrics ──');
    console.log('  (pre-V2 / manual runs — cloud agents not tracked, tokens not recoverable)');
    for (const r of withoutMetrics) {
      console.log(`  ${r.stepId.padEnd(8)} ${r.name}`);
    }
  }

  let enrichedPhases = 0;
  if (!SKIP_CURSOR && process.env.CURSOR_API_KEY) {
    console.log('\n── Step 3: Cursor API token enrich ──');
    const client = createCursorClient(process.env.CURSOR_API_KEY);
    const sessions = [...allSessions.values()];
    enrichedPhases = await enrichSessions(sessions, client);

    if (!DRY_RUN && enrichedPhases) {
      index.updatedAt = new Date().toISOString();
      index.completedStepsBackfill = {
        at: index.updatedAt,
        phasesEnriched: enrichedPhases,
        stepCount: withMetrics.length
      };
      writeJson(join(ROOT, INDEX_PATH), index);
    }
  } else if (!SKIP_CURSOR) {
    console.log('\n── Step 3: Cursor API ── skipped (set CURSOR_API_KEY)');
  } else {
    console.log('\n── Step 3: Cursor API ── skipped (--skip-cursor)');
  }

  if (withMetrics.length) {
    const after = [];
    for (const row of completedSteps) {
      const ids = sessionIdsForStep(index, row.id);
      if (!ids.length) continue;
      const sessions = ids.map(id => allSessions.get(id) || loadSession(id)).filter(Boolean);
      after.push(auditStep(row.id, index, sessions));
    }
    console.log('\n── Step 4: Audit (after enrich) ──');
    printReport('With Conductor metrics', after);
  }

  console.log(
    `\nDone. ${enrichedPhases} phase(s) enriched via Cursor API.` +
      (COPY_LOCAL && !DRY_RUN ? ' Local preview copied.' : '')
  );

  if (COPY_LOCAL && !DRY_RUN) copyToLocalPreview();
}

main().catch(e => {
  console.error(e.stack || e.message);
  process.exit(1);
});
