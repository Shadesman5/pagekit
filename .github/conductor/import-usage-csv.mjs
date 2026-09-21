#!/usr/bin/env node
// Overlay Cursor dashboard usage-events CSV onto Conductor sessions.
// Parent /usage is orchestrator-only; CSV rows on the same bc-… include Task children.
//
// Usage:
//   node .github/conductor/import-usage-csv.mjs usage-events.csv
//   node .github/conductor/import-usage-csv.mjs --csv usage-events.csv [--step 2.7.1a] [--dry-run]
//
// A bare path is enough: sessions are chosen by the Cloud Agent IDs in the file.
// Writes and pushes to conductor-metrics. --dry-run prints the match and changes nothing.

import { readFileSync, writeFileSync, existsSync, mkdirSync, cpSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  INDEX_PATH,
  SESSIONS_DIR,
  METRICS_DIR,
  DEFAULT_METRICS_BRANCH,
  recomputeSessionTotals,
  applySessionTimestamps,
  sessionIdsForStep,
  currentGitBranch,
  syncMetricsFromRemote,
  pushMetricsToRemote
} from './metrics.mjs';
import { parseDashboardUsageCsv, applyCsvUsageToSession } from './usage-csv.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const DRY_RUN = process.argv.includes('--dry-run');
const COPY_LOCAL = process.argv.includes('--copy-local');
const DO_PUSH = !DRY_RUN && !process.argv.includes('--no-push');

function getArg(name) {
  const i = process.argv.indexOf(name);
  return i >= 0 ? process.argv[i + 1] : null;
}

/** First path that is not a flag or a flag's value. */
function positionalCsv() {
  const skipValue = new Set(['--csv', '--session', '--step']);
  const args = process.argv.slice(2);
  for (let i = 0; i < args.length; i += 1) {
    const arg = args[i];
    if (skipValue.has(arg)) {
      i += 1;
      continue;
    }
    if (arg.startsWith('-')) continue;
    return arg;
  }
  return null;
}

function readJson(path, fallback = null) {
  if (!existsSync(path)) return fallback;
  return JSON.parse(readFileSync(path, 'utf8'));
}

function writeJson(path, data) {
  writeFileSync(path, `${JSON.stringify(data, null, 2)}\n`, 'utf8');
}

function copyToLocalPreview() {
  const dest = join(ROOT, 'docs-site/data/conductor-metrics');
  mkdirSync(dest, { recursive: true });
  cpSync(join(ROOT, METRICS_DIR), dest, { recursive: true, force: true });
  console.log(`Copied ${METRICS_DIR}/ → docs-site/data/conductor-metrics/`);
}

function sessionsForAgents(index, agentIds) {
  const want = new Set(agentIds);
  const hits = [];
  const seen = new Set();
  for (const entry of Object.values(index?.steps || {})) {
    for (const sessionId of entry?.sessionIds || []) {
      if (!sessionId || seen.has(sessionId)) continue;
      seen.add(sessionId);
      const session = readJson(join(ROOT, SESSIONS_DIR, `${sessionId}.json`));
      if (!session) continue;
      const known = (session.phases || []).some(phase =>
        want.has(String(phase.agent?.id || '').toLowerCase())
      );
      if (known) hits.push(session);
    }
  }
  return hits;
}

function main() {
  const csvPath = getArg('--csv') || positionalCsv();
  const sessionFilter = (getArg('--session') || '').trim().toLowerCase() || null;
  const stepFilter = (getArg('--step') || '').trim().toLowerCase() || null;
  if (!csvPath) {
    console.error(
      'Pass the CSV path: node .github/conductor/import-usage-csv.mjs usage-events.csv'
    );
    process.exit(1);
  }
  if (!existsSync(csvPath)) {
    console.error(`CSV not found: ${csvPath}`);
    process.exit(1);
  }

  const events = parseDashboardUsageCsv(readFileSync(csvPath, 'utf8'));
  const agents = new Set(events.map(e => e.agentId));
  console.log(
    `CSV: ${events.length} billed cloud-agent event(s) across ${agents.size} parent(s)` +
      `${DRY_RUN ? ' (dry-run)' : ''}`
  );
  if (!events.length) {
    console.error('No Cloud Agent ID rows in the CSV — nothing to apply');
    process.exit(1);
  }

  const returnBranch = currentGitBranch(ROOT);
  mkdirSync(join(ROOT, SESSIONS_DIR), { recursive: true });
  syncMetricsFromRemote({ root: ROOT, log: msg => console.log(msg) });

  const index = readJson(join(ROOT, INDEX_PATH), { schemaVersion: 1, updatedAt: null, steps: {} });
  let sessionIds;
  if (sessionFilter) {
    sessionIds = [sessionFilter];
  } else if (stepFilter) {
    sessionIds = sessionIdsForStep(index, stepFilter);
    if (!sessionIds.length) {
      console.error(`No session recorded for step ${stepFilter} in ${INDEX_PATH}`);
      process.exit(1);
    }
  } else {
    sessionIds = sessionsForAgents(index, [...agents]).map(session => session.sessionId);
    if (!sessionIds.length) {
      console.error('No session phase matches a Cloud Agent ID in this CSV');
      process.exit(1);
    }
    console.log(`Matched ${sessionIds.length} session(s) from the CSV agent ids`);
  }

  let sessionsTouched = 0;
  let phasesUpdated = 0;
  for (const sessionId of sessionIds) {
    const path = join(ROOT, SESSIONS_DIR, `${sessionId}.json`);
    const session = readJson(path);
    if (!session) {
      console.log(`  skip ${sessionId.slice(0, 8)}… — file missing`);
      continue;
    }
    console.log(`  ${sessionId.slice(0, 8)}… step=${session.roadmapStepId || '?'}`);
    const n = applyCsvUsageToSession(session, events, { log: msg => console.log(msg) });
    if (!n) {
      console.log('    no parent agent IDs from this session appear in the CSV');
      continue;
    }
    applySessionTimestamps(session);
    recomputeSessionTotals(session);
    session.csvImport = {
      at: new Date().toISOString(),
      source: 'import-usage-csv.mjs',
      events: events.length,
      phasesUpdated: n
    };
    phasesUpdated += n;
    sessionsTouched += 1;
    if (!DRY_RUN) writeJson(path, session);
  }

  if (!sessionsTouched) {
    console.error('No session phases matched CSV Cloud Agent IDs');
    process.exit(1);
  }

  if (!DRY_RUN) {
    index.updatedAt = new Date().toISOString();
    index.csvImport = {
      at: index.updatedAt,
      phasesUpdated,
      sessionsTouched
    };
    writeJson(join(ROOT, INDEX_PATH), index);
    if (COPY_LOCAL) copyToLocalPreview();
    if (DO_PUSH) {
      pushMetricsToRemote({
        root: ROOT,
        message: `chore(metrics): overlay dashboard CSV tokens (${sessionsTouched} session(s))`,
        metricsBranch: DEFAULT_METRICS_BRANCH,
        returnBranch,
        log: msg => console.log(msg)
      });
    }
  }

  console.log(
    `\nDone: ${phasesUpdated} phase(s) in ${sessionsTouched} session(s)` +
      `${DRY_RUN ? ' (dry-run, no files written)' : ''}.`
  );
}

main();
