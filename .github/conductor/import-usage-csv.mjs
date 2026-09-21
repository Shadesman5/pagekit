#!/usr/bin/env node
// Overlay Cursor dashboard usage-events CSV onto Conductor sessions.
// Parent /usage is orchestrator-only; CSV rows on the same bc-… include Task children.
//
// Usage:
//   node .github/conductor/import-usage-csv.mjs --csv usage-events.csv --session UUID [--push] [--copy-local]
//   node .github/conductor/import-usage-csv.mjs --csv usage-events.csv --step 2.7.1a [--push]

import { readFileSync, writeFileSync, existsSync, mkdirSync, cpSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  INDEX_PATH,
  SESSIONS_DIR,
  METRICS_DIR,
  DEFAULT_METRICS_BRANCH,
  recomputeSessionTotals,
  applySessionTimestamps,
  currentGitBranch,
  syncMetricsFromRemote,
  pushMetricsToRemote
} from './metrics.mjs';
import { parseDashboardUsageCsv, applyCsvUsageToSession } from './usage-csv.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const DRY_RUN = process.argv.includes('--dry-run');
const COPY_LOCAL = process.argv.includes('--copy-local');
const DO_PUSH = process.argv.includes('--push');

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

function copyToLocalPreview() {
  const dest = join(ROOT, 'docs-site/data/conductor-metrics');
  mkdirSync(dest, { recursive: true });
  cpSync(join(ROOT, METRICS_DIR), dest, { recursive: true, force: true });
  console.log(`Copied ${METRICS_DIR}/ → docs-site/data/conductor-metrics/`);
}

function listSessionIds(index, sessionFilter, stepFilter) {
  if (sessionFilter) return [sessionFilter];
  const ids = [];
  for (const [stepId, entry] of Object.entries(index?.steps || {})) {
    if (stepFilter && stepId.toLowerCase() !== stepFilter) continue;
    for (const id of entry.sessionIds || []) ids.push(id);
  }
  if (!ids.length && existsSync(join(ROOT, SESSIONS_DIR))) {
    for (const name of readdirSync(join(ROOT, SESSIONS_DIR))) {
      if (name.endsWith('.json')) ids.push(name.replace(/\.json$/, ''));
    }
  }
  return [...new Set(ids)];
}

function main() {
  const csvPath = getArg('--csv');
  const sessionFilter = (getArg('--session') || '').trim().toLowerCase() || null;
  const stepFilter = (getArg('--step') || '').trim().toLowerCase() || null;
  if (!csvPath) {
    console.error('Missing --csv (Cursor dashboard usage-events export)');
    process.exit(1);
  }
  if (!existsSync(csvPath)) {
    console.error(`CSV not found: ${csvPath}`);
    process.exit(1);
  }
  if (!sessionFilter && !stepFilter) {
    console.error('Pass --session UUID or --step X.Y so only the intended ticket is updated');
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
  const sessionIds = listSessionIds(index, sessionFilter, stepFilter);
  if (!sessionIds.length) {
    console.error('No matching session on conductor-metrics');
    process.exit(1);
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
