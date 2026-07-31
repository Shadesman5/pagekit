// Quality Snapshot — builds the live CI quality snapshot for the develop branch and publishes it to
// the unprotected `quality-data` branch that the docs site overlays onto the Quality Dashboard.
//
// Zero npm dependencies: Node 20 + git + gh, all preinstalled on ubuntu-latest. Invoked by
// quality-collect.yml after a gate workflow (PHP Tests / E2E) completes a push (merge) to develop,
// or manually via workflow_dispatch.
//
// The snapshot blends CI results into ONE coherent branch state:
//   - PHP Tests + E2E -> the newest MERGE commit that is green on BOTH gates, matched by head SHA so
//     the two halves are never stitched together from different merges. PHP Tests yields line coverage
//     (Clover), PHPStan errors (json), PHPUnit counts (junit) and the non-blocking MySQL leg's job
//     conclusion; E2E yields Playwright smoke results (json report).
//   - Nightly -> full-suite Infection MSI, an independent scheduled metric (null until the first
//     nightly ran), intentionally decoupled from the merge commit.
// It publishes only when some commit is green on BOTH gate workflows, so the dashboard never shows a
// half-built snapshot or numbers pulled from two unrelated merges. A gate whose run is missing (e.g.
// path-filtered, not yet uploaded, or a 404 workflow) yields no pairing / null metric, keeping the
// blend null-safe.
//
// Writes land on `quality-data` only — never on develop (no Ruleset bypass) — mirroring the
// conductor-metrics push. GITHUB_TOKEN pushes do not re-trigger workflows, so the collector
// dispatches pages-deploy explicitly to rebuild the site from the fresh snapshot.

import { execFileSync, execSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync, readdirSync, readFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, basename } from 'node:path';

const REPO = required('GITHUB_REPOSITORY');
const BRANCH = (process.env.BRANCH || 'develop').trim() || 'develop';

// Single-writer data branch the docs site overlays. Created from develop when absent (mirroring the
// conductor-metrics push); the snapshot and its history are the only meaningful content it carries.
const DATA_BRANCH = 'quality-data';
const SNAPSHOT_PATH = '.github/quality/quality-snapshot.json';
const HISTORY_PATH = '.github/quality/quality-history.json';

// Keeps the file small enough for the dashboard to fetch on every page load. Metrics move slowly and
// the diff-guard already drops no-op collections, so 90 points span a long stretch of real change.
const HISTORY_CAP = 90;

const DRY_RUN = process.env.DRY_RUN === '1';

// Workflow files whose latest runs feed the snapshot (keyed by file, not display name, so the query
// is exact). A workflow file that does not exist yet yields a 404 -> null.
const WF_PHP_TESTS = 'php-tests.yml';
const WF_E2E = 'e2e.yml';
const WF_NIGHTLY = 'nightly.yml';

main();

function main() {
  log(`collecting quality snapshot for ${BRANCH}`);

  // Read repo-file inputs from the current (protected-branch) checkout BEFORE the branch switch:
  // the coverage floor is the SSoT in php-tests.yml and the PHPStan baseline counts come from the tree.
  const floor = readFloor();
  const baseline = readBaseline();

  // Pair PHP Tests + E2E from the SAME merge commit (matched by head SHA). Selecting each gate's latest
  // green run independently could blend fresh coverage/PHPUnit numbers from the tip with an older E2E
  // report from a previous merge — misrepresenting the branch tip. Walk back to the newest commit that
  // is green on BOTH gates instead; publish nothing until such a commit exists.
  const phpRuns = successfulRuns(WF_PHP_TESTS, { branch: BRANCH, event: 'push' });
  const e2eRuns = successfulRuns(WF_E2E, { branch: BRANCH, event: 'push' });
  const pair = latestCommonRun(phpRuns, e2eRuns);
  if (!pair) {
    log(
      `skipping publish — no commit on ${BRANCH} is green on BOTH gates ` +
        `(PHP Tests green runs: ${phpRuns.length}, E2E green runs: ${e2eRuns.length}).`
    );
    return;
  }
  const { phpRun, e2eRun } = pair;

  // Full-suite Infection MSI comes from the latest Nightly on this branch whose infection-full
  // job actually ran (conclusion success). Idle Nightlies only run the guard and skip infection-
  // full — those still conclude the workflow as success, but have no infection.json. Picking the
  // newest workflow success blindly would null out a previously published MSI after an idle night.
  const nightlyRun = latestNightlyWithInfection({ branch: BRANCH });

  const snapshot = buildSnapshot({ floor, baseline, phpRun, e2eRun, nightlyRun });
  publish(snapshot);
}

// ---------------------------------------------------------------- snapshot assembly
function buildSnapshot({ floor, baseline, phpRun, e2eRun, nightlyRun }) {
  const dir = mkdtempSync(join(tmpdir(), 'quality-snapshot-'));
  try {
    downloadRunArtifacts(phpRun.id, join(dir, 'php'));
    downloadRunArtifacts(e2eRun.id, join(dir, 'e2e'));
    if (nightlyRun) downloadRunArtifacts(nightlyRun.id, join(dir, 'nightly'));

    const coverage = safe(() => readCoverage(findFileByName(join(dir, 'php'), 'coverage.xml')));
    const junit = safe(() => readJunit(findFileByName(join(dir, 'php'), 'junit.xml')));
    const phpstan = safe(() => readPhpstan(findFileByName(join(dir, 'php'), 'phpstan.json')));
    const playwright = safe(() => readPlaywright(findPlaywrightReport(join(dir, 'e2e'))));
    const infection = nightlyRun
      ? safe(() => readInfection(findFileByName(join(dir, 'nightly'), 'infection.json')))
      : null;

    // The MySQL leg uploads no artifact — its truth is the job conclusion (continue-on-error keeps it
    // non-blocking; the snapshot records the real outcome and flags the leg required:false).
    const mysqlConclusion = jobConclusion(phpRun.id, 'phpunit-mysql');

    return assemble({
      floor,
      baseline,
      phpRun,
      e2eRun,
      nightlyRun,
      coverage,
      junit,
      phpstan,
      playwright,
      infection,
      mysqlConclusion
    });
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

function assemble(d) {
  return {
    schemaVersion: 3,
    source: 'github-actions',
    updatedAt: new Date().toISOString(),
    branch: BRANCH,
    // The merge commit both gate runs describe — also the identity of each history point.
    commit: d.phpRun.head_sha ?? null,
    workflows: {
      phpTests: { runId: Number(d.phpRun.id), conclusion: d.phpRun.conclusion },
      e2eTests: { runId: Number(d.e2eRun.id), conclusion: d.e2eRun.conclusion },
      infectionFull: d.nightlyRun
        ? { runId: Number(d.nightlyRun.id), conclusion: d.nightlyRun.conclusion, scheduled: true }
        : { runId: null, conclusion: null, scheduled: true }
    },
    phpunit: {
      '8.5-sqlite': {
        db: 'sqlite',
        required: true,
        tests: d.junit?.tests ?? null,
        failures: d.junit ? d.junit.failures + d.junit.errors : null
      },
      '8.5-mysql': {
        db: 'mysql',
        required: false,
        conclusion: d.mysqlConclusion
      }
    },
    phpstan: {
      level: 8,
      errors: d.phpstan?.errors ?? null,
      baselineBlocks: d.baseline?.blocks ?? null,
      suppressedErrors: d.baseline?.suppressed ?? null
    },
    coverage: {
      linePercent: d.coverage ? round(d.coverage.percent, 2) : null,
      pinnedFloor: d.floor,
      statements: d.coverage?.statements ?? null,
      covered: d.coverage?.covered ?? null
    },
    infection: {
      // Diff-scoped MSI is a per-PR metric, not a develop-tip number — left null on the live snapshot.
      prDiff: { msi: null, coveredMsi: null, scope: 'diff' },
      dailyFull: {
        msi: d.infection?.msi ?? null,
        coveredMsi: d.infection?.coveredMsi ?? null,
        killed: d.infection?.killed ?? null,
        escaped: d.infection?.escaped ?? null,
        timedOut: d.infection?.timedOut ?? null,
        errors: d.infection?.errors ?? null,
        totalMutants: d.infection?.totalMutants ?? null,
        scope: 'full',
        runAt: d.nightlyRun?.run_started_at ?? null
      }
    },
    e2e: {
      scope: 'smoke',
      viewports: d.playwright?.viewports ?? [],
      specsTotal: d.playwright?.total ?? null,
      specsPassed: d.playwright?.passed ?? null,
      durationMs: d.playwright?.durationMs ?? null
    },
    // Required PR gates. The Ruleset makes cs-fixer, security-audit and frontend mandatory to merge,
    // and this snapshot only ever publishes for a SUCCESSFUL push (merge) run to a protected branch —
    // so on the develop tip these gates are green by construction. codecov is configured non-blocking;
    // bugbot is a local review step, not a CI gate.
    gates: {
      csFixer: 'pass',
      securityAudit: 'pass',
      frontendLint: 'pass',
      codecov: 'non-blocking',
      bugbot: 'n/a'
    }
  };
}

// ---------------------------------------------------------------- history series
// One point per snapshot whose numbers actually moved. Flattened to the few series the dashboard
// charts, so the file stays small enough to fetch client-side no matter how long the series grows.
function historyEntry(s) {
  const full = s.infection?.dailyFull ?? {};
  return {
    at: s.updatedAt,
    sha: s.commit,
    coverage: { linePercent: s.coverage.linePercent, pinnedFloor: s.coverage.pinnedFloor },
    phpunit: { tests: s.phpunit['8.5-sqlite'].tests, failures: s.phpunit['8.5-sqlite'].failures },
    phpstan: {
      errors: s.phpstan.errors,
      baselineBlocks: s.phpstan.baselineBlocks,
      suppressedErrors: s.phpstan.suppressedErrors
    },
    infection: {
      msi: full.msi ?? null,
      coveredMsi: full.coveredMsi ?? null,
      killed: full.killed ?? null,
      escaped: full.escaped ?? null,
      timedOut: full.timedOut ?? null,
      errors: full.errors ?? null
    },
    e2e: { specsPassed: s.e2e.specsPassed, specsTotal: s.e2e.specsTotal, scope: s.e2e.scope }
  };
}

// Collect runs on every merge and every nightly, but the metrics rarely move. Appending regardless
// would bury real change under duplicate points and grow the file the dashboard fetches for nothing.
function historyChanged(prev, next) {
  if (!prev) return true;
  const watched = [
    ['coverage', 'linePercent'],
    ['phpunit', 'tests'],
    ['phpunit', 'failures'],
    ['phpstan', 'errors'],
    ['phpstan', 'baselineBlocks'],
    ['phpstan', 'suppressedErrors'],
    ['e2e', 'specsPassed'],
    ['e2e', 'specsTotal']
  ];
  if (watched.some(([group, key]) => next[group]?.[key] !== prev[group]?.[key])) return true;

  // Infection counts only while the nightly reports. A run that is temporarily missing would
  // otherwise register as a change on the way out and again on the way back in.
  return ['msi', 'coveredMsi', 'killed', 'escaped'].some(key => {
    const value = next.infection?.[key];
    return value != null && value !== prev.infection?.[key];
  });
}

function readHistoryFile() {
  if (!existsSync(HISTORY_PATH)) return [];
  const parsed = safe(() => JSON.parse(readFileSync(HISTORY_PATH, 'utf8')));
  return Array.isArray(parsed?.points) ? parsed.points : [];
}

function writeHistoryFile(points) {
  const body = { schemaVersion: 3, branch: BRANCH, cap: HISTORY_CAP, points };
  writeFileSync(HISTORY_PATH, `${JSON.stringify(body, null, 2)}\n`);
}

// ---------------------------------------------------------------- run + artifact lookup
// Successful runs of a workflow on the branch, newest-first. The API already returns newest-first;
// sort by id defensively so both head-SHA pairing and "latest" selection are deterministic.
function successfulRuns(workflowFile, { branch, event } = {}) {
  const qs = new URLSearchParams({ status: 'completed', per_page: '30' });
  if (branch) qs.set('branch', branch);
  if (event) qs.set('event', event);
  const obj = ghApiObject(`/repos/${REPO}/actions/workflows/${workflowFile}/runs?${qs}`);
  const runs = Array.isArray(obj?.workflow_runs) ? obj.workflow_runs : [];
  return runs.filter(r => r.conclusion === 'success').sort((a, b) => Number(b.id) - Number(a.id));
}

// Prefer a Nightly whose infection-full job succeeded (produced MSI artifacts). Idle scheduled
// Nightlies skip that job via the 24h guard but still mark the workflow success — those must not
// replace a real full-suite result with nulls on the dashboard.
function latestNightlyWithInfection({ branch } = {}) {
  for (const run of successfulRuns(WF_NIGHTLY, { branch })) {
    if (jobConclusion(run.id, 'infection-full') === 'success') return run;
  }
  return null;
}

// Newest commit that is green on BOTH gate workflows, returned as the matching run from each. Runs are
// paired by head_sha (both gates run on the same push, so a merge produces the same SHA in each list),
// so the snapshot's PHP Tests and E2E numbers always describe one coherent commit, not two merges.
function latestCommonRun(phpRuns, e2eRuns) {
  const e2eBySha = new Map();
  for (const run of e2eRuns) {
    if (run.head_sha && !e2eBySha.has(run.head_sha)) e2eBySha.set(run.head_sha, run);
  }
  for (const phpRun of phpRuns) {
    const e2eRun = phpRun.head_sha ? e2eBySha.get(phpRun.head_sha) : undefined;
    if (e2eRun) return { phpRun, e2eRun };
  }
  return null;
}

function downloadRunArtifacts(runId, dir) {
  // A run with no artifacts, or one that has not uploaded yet, fails here — ignore it; the affected
  // metric simply stays null in the snapshot.
  gh(['run', 'download', String(runId), '--repo', REPO, '--dir', dir], { allowFail: true });
}

function jobConclusion(runId, jobName) {
  const obj = ghApiObject(`/repos/${REPO}/actions/runs/${runId}/jobs?per_page=100`);
  const jobs = Array.isArray(obj?.jobs) ? obj.jobs : [];
  return jobs.find(j => j.name === jobName)?.conclusion ?? null;
}

// ---------------------------------------------------------------- artifact parsers
// Coverage floor is the single source of truth in php-tests.yml (MIN_LINE_COVERAGE); read it from the
// checked-out workflow so the snapshot never drifts from the gate that enforces it.
function readFloor() {
  const path = '.github/workflows/php-tests.yml';
  if (!existsSync(path)) return null;
  const m = readFileSync(path, 'utf8').match(/MIN_LINE_COVERAGE:\s*['"]?([0-9.]+)/);
  return m ? Number(m[1]) : null;
}

// PHPStan reports only the count of NON-baselined errors; the baseline file supplies the historical
// debt shown on the dashboard: one block per `-` entry (== per `count:` line), summed to total errors.
function readBaseline() {
  const path = 'phpstan-baseline.neon';
  if (!existsSync(path)) return null;
  const counts = [...readFileSync(path, 'utf8').matchAll(/^\s*count:\s*(\d+)\s*$/gm)].map(m =>
    Number(m[1])
  );
  if (!counts.length) return null;
  return { blocks: counts.length, suppressed: counts.reduce((a, b) => a + b, 0) };
}

function readCoverage(path) {
  const xml = readFileSync(path, 'utf8');
  // Clover's project-level aggregate is the <metrics/> element directly before </project>.
  let attrs = xml.match(/<metrics\b([^>]*?)\/>\s*<\/project>/)?.[1];
  if (attrs == null) {
    const all = [...xml.matchAll(/<metrics\b([^>]*?)\/>/g)];
    attrs = all.length ? all[all.length - 1][1] : null;
  }
  if (attrs == null) return null;
  const statements = Number(attrs.match(/\bstatements="(\d+)"/)?.[1]);
  const covered = Number(attrs.match(/\bcoveredstatements="(\d+)"/)?.[1]);
  if (!Number.isFinite(statements) || statements <= 0 || !Number.isFinite(covered)) return null;
  return { statements, covered, percent: (covered / statements) * 100 };
}

function readPhpstan(path) {
  const totals = JSON.parse(readFileSync(path, 'utf8')).totals || {};
  return { errors: Number(totals.file_errors ?? 0) + Number(totals.errors ?? 0) };
}

function readJunit(path) {
  const xml = readFileSync(path, 'utf8');
  // The grand-total suite always carries the largest `tests` count; take it regardless of nesting.
  let best = null;
  for (const tag of xml.match(/<testsuite\b[^>]*>/g) || []) {
    const tests = attrNum(tag, 'tests');
    if (tests == null) continue;
    if (!best || tests > best.tests) {
      best = {
        tests,
        failures: attrNum(tag, 'failures') ?? 0,
        errors: attrNum(tag, 'errors') ?? 0
      };
    }
  }
  return best;
}

function readInfection(path) {
  const stats = JSON.parse(readFileSync(path, 'utf8')).stats || {};
  return {
    msi: stats.msi ?? null,
    coveredMsi: stats.coveredCodeMsi ?? stats.coveredMsi ?? null,
    killed: stats.killedCount ?? null,
    escaped: stats.escapedCount ?? null,
    timedOut: stats.timeOutCount ?? null,
    errors: stats.errorCount ?? null,
    totalMutants: stats.totalMutantsCount ?? null
  };
}

function readPlaywright(path) {
  const report = JSON.parse(readFileSync(path, 'utf8'));
  const stats = report.stats || {};
  const expected = Number(stats.expected ?? 0);
  const unexpected = Number(stats.unexpected ?? 0);
  const flaky = Number(stats.flaky ?? 0);
  const skipped = Number(stats.skipped ?? 0);
  return {
    passed: expected + flaky,
    failed: unexpected,
    skipped,
    total: expected + unexpected + flaky + skipped,
    durationMs: Math.round(Number(stats.duration ?? 0)),
    viewports: deriveViewports(report)
  };
}

// Project names encode the viewport class (…-desktop / …-tablet / …-mobile). Collect every project
// that actually ran (from results and the resolved config) and map it to its viewport bucket.
function deriveViewports(report) {
  const names = new Set();
  (function collect(node) {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) {
      for (const child of node) collect(child);
      return;
    }
    if (typeof node.projectName === 'string' && node.projectName) names.add(node.projectName);
    for (const key of Object.keys(node)) collect(node[key]);
  })(report);
  for (const p of report.config?.projects ?? []) if (p?.name) names.add(p.name);

  const order = ['mobile', 'tablet', 'desktop'];
  const found = order.filter(v => [...names].some(n => n.toLowerCase().includes(v)));
  return found.length ? found : ['desktop'];
}

// ---------------------------------------------------------------- publish (quality-data branch)
// The collector only ever runs from a protected branch's checkout, so a local run must not touch git.
// Reading the previous series over the API instead makes the diff-guard verdict real rather than a
// guess — the one thing worth checking before this script reaches the default branch.
function dryRunPublish(snapshot) {
  const raw = gh(
    [
      'api',
      '-H',
      'Accept: application/vnd.github.raw',
      `/repos/${REPO}/contents/${HISTORY_PATH}?ref=${DATA_BRANCH}`
    ],
    { allowFail: true }
  );
  const parsed = raw ? safe(() => JSON.parse(raw)) : null;
  const points = Array.isArray(parsed?.points) ? parsed.points : [];
  const entry = historyEntry(snapshot);
  const changed = historyChanged(points.at(-1) ?? null, entry);

  log(`DRY_RUN=1 — nothing written. ${points.length} existing history point(s) on ${DATA_BRANCH}.`);
  log(
    changed
      ? 'watched metrics changed — would append:'
      : 'watched metrics unchanged — would NOT append:'
  );
  console.log(`\n--- snapshot ---\n${JSON.stringify(snapshot, null, 2)}`);
  console.log(`\n--- history entry ---\n${JSON.stringify(entry, null, 2)}\n`);
}

function publish(snapshot) {
  if (DRY_RUN) return dryRunPublish(snapshot);

  const body = `${JSON.stringify(snapshot, null, 2)}\n`;
  ensureGitIdentity();
  ensureDataBranch();
  sh(`git fetch origin ${DATA_BRANCH}`);
  sh(`git checkout -B ${DATA_BRANCH} origin/${DATA_BRANCH}`);
  writeFileSync(SNAPSHOT_PATH, body);

  // The checkout above put the branch's own history on disk, so this compares against exactly what is
  // about to be amended. An unchanged tuple still lets the snapshot refresh its updatedAt / runIds —
  // only the series stays put.
  const points = readHistoryFile();
  const entry = historyEntry(snapshot);
  if (historyChanged(points.at(-1) ?? null, entry)) {
    writeHistoryFile([...points, entry].slice(-HISTORY_CAP));
    log(`appended history point (${points.length + 1} total, cap ${HISTORY_CAP}).`);
  } else {
    log('watched metrics unchanged — history not extended.');
  }

  sh(`git add ${SNAPSHOT_PATH}`);
  if (existsSync(HISTORY_PATH)) sh(`git add ${HISTORY_PATH}`);
  if (stagedTreeIsClean()) {
    log('snapshot unchanged — nothing to publish.');
    return;
  }
  sh(`git commit -m ${JSON.stringify('chore(quality): update live quality snapshot')}`);
  // A concurrent collect run (develop + main both merging) may push first — rebase before pushing.
  sh(`git fetch origin ${DATA_BRANCH}`);
  try {
    sh(`git rebase origin/${DATA_BRANCH}`);
  } catch (e) {
    try {
      sh('git rebase --abort');
    } catch {
      /* nothing to abort */
    }
    throw new Error(
      `rebase onto origin/${DATA_BRANCH} failed (resolve conflict or retry): ${e.message}`,
      { cause: e }
    );
  }
  sh(`git push origin ${DATA_BRANCH}`);
  log(`published snapshot to ${DATA_BRANCH}`);
  // GITHUB_TOKEN pushes never re-trigger `push` workflows — dispatch pages-deploy so the site rebuilds
  // (it overlays this snapshot from quality-data).
  const dispatch = gh(['workflow', 'run', 'pages-deploy.yml', '--ref', 'develop'], {
    allowFail: true
  });
  log(
    dispatch === null
      ? 'pages-deploy dispatch skipped (see gh output above)'
      : 'dispatched pages-deploy.yml (ref=develop)'
  );
}

function ensureGitIdentity() {
  try {
    sh('git config user.email');
  } catch {
    sh('git config user.email "41898282+github-actions[bot]@users.noreply.github.com"');
    sh('git config user.name "github-actions[bot]"');
  }
}

function ensureDataBranch() {
  if (sh(`git ls-remote --heads origin ${DATA_BRANCH}`)) return;
  log(`creating origin/${DATA_BRANCH} from origin/develop`);
  sh('git fetch origin develop');
  try {
    sh(`git branch -f ${DATA_BRANCH} origin/develop`);
  } catch {
    sh(`git branch ${DATA_BRANCH} origin/develop`);
  }
  sh(`git push -u origin ${DATA_BRANCH}`);
}

function stagedTreeIsClean() {
  try {
    sh('git diff --cached --quiet');
    return true;
  } catch {
    return false;
  }
}

// ---------------------------------------------------------------- filesystem helpers
function* walk(dir) {
  let entries;
  try {
    entries = readdirSync(dir, { withFileTypes: true });
  } catch {
    return;
  }
  for (const entry of entries) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) yield* walk(full);
    else yield full;
  }
}

function findFileByName(root, name) {
  for (const path of walk(root)) {
    if (basename(path) === name) return path;
  }
  return null;
}

function findPlaywrightReport(root) {
  for (const path of walk(root)) {
    if (!path.endsWith('.json')) continue;
    try {
      const text = readFileSync(path, 'utf8');
      if (!text.includes('"suites"') || !text.includes('"stats"')) continue;
      const json = JSON.parse(text);
      if (json?.stats && Array.isArray(json.suites)) return path;
    } catch {
      /* not the Playwright report */
    }
  }
  return null;
}

// ---------------------------------------------------------------- low-level
function ghApiObject(endpoint) {
  const out = gh(['api', endpoint], { allowFail: true });
  if (!out) return null;
  try {
    return JSON.parse(out);
  } catch {
    return null;
  }
}

function gh(args, { allowFail = false } = {}) {
  try {
    return execFileSync('gh', args, {
      stdio: ['ignore', 'pipe', 'pipe'],
      encoding: 'utf8',
      maxBuffer: 64 * 1024 * 1024
    }).trim();
  } catch (e) {
    if (allowFail) return null;
    const detail = e.stderr?.toString().trim() || e.stdout?.toString().trim() || e.message;
    throw new Error(`gh ${args.join(' ')} failed: ${detail}`, { cause: e });
  }
}

function sh(cmd) {
  try {
    return execSync(cmd, {
      stdio: ['ignore', 'pipe', 'pipe'],
      encoding: 'utf8',
      maxBuffer: 64 * 1024 * 1024
    }).trim();
  } catch (e) {
    const detail = e.stderr?.toString().trim() || e.stdout?.toString().trim() || e.message;
    throw new Error(`${cmd} failed: ${detail}`, { cause: e });
  }
}

function attrNum(tag, attr) {
  const m = tag.match(new RegExp(`\\b${attr}="(\\d+)"`));
  return m ? Number(m[1]) : null;
}

function round(n, dp) {
  const f = 10 ** dp;
  return Math.round(n * f) / f;
}

function safe(fn) {
  try {
    return fn();
  } catch (e) {
    log(`artifact parse skipped: ${e.message}`);
    return null;
  }
}

function log(...args) {
  console.log(new Date().toISOString(), ...args);
}

function required(name) {
  const v = process.env[name];
  if (!v) {
    console.error(`Missing required env: ${name}`);
    process.exit(1);
  }
  return v.trim();
}
