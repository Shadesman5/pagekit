// Quality Report — renders the sticky "Quality Report" comment on a pull request.
//
// Zero npm dependencies: Node 20 + git + gh, all preinstalled on ubuntu-latest. Invoked by
// quality-report.yml when a gate workflow (PHP Tests / Infection / E2E / Frontend) completes for a
// pull request, or manually via workflow_dispatch with a `pr` input.
//
// It renders the CURRENT state for a PR head commit, aggregating across every gate:
//   - PASS / FAIL / pending comes from the check-runs API, which spans every workflow for the SHA;
//   - numeric detail (coverage %, PHPStan errors, PHPUnit / E2E counts, Infection MSI) comes from the
//     artifacts uploaded by each gate's latest run for that same SHA.
// Gates that have not reported yet — Infection, E2E and Frontend arrive in later checklist steps —
// render as "pending" instead of failing. The comment is upserted idempotently via a hidden marker,
// so repeated gate completions update one comment rather than posting a new one each time.

import { execFileSync } from "node:child_process";
import { mkdtempSync, rmSync, readdirSync, readFileSync, existsSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, basename } from "node:path";

// Hidden HTML marker keying the single sticky comment (GitHub hides it from the rendered body).
const MARKER = "<!-- quality-report -->";

// Gate workflows keyed by FILE PATH, never by display name: every gate declares a custom `run-name:`,
// and the Actions API returns that evaluated run-name in `workflow_runs[].name` (e.g. "PHP Tests — PR
// #242 (branch)"). Matching on the name would therefore never hit, leaving every metric unresolved.
const GATE_WORKFLOW_PATHS = [
  ".github/workflows/php-tests.yml",
  ".github/workflows/infection.yml",
  ".github/workflows/e2e.yml",
  ".github/workflows/frontend.yml",
];

// Check-run names == the job `name:` values that produce them.
const CHECK_PHPUNIT = "phpunit (8.5)";
const CHECK_PHPSTAN = "phpstan";
const CHECK_INFECTION = "infection-diff";
const CHECK_E2E = "e2e-smoke";
const CHECK_CSFIXER = "cs-fixer";
const CHECK_SECURITY = "security-audit";
const CHECK_FRONTEND = "frontend";

const REPO = required("GITHUB_REPOSITORY");

main();

function main() {
  const ctx = resolveContext();
  if (!ctx) {
    log("no pull-request context resolved — nothing to report.");
    return;
  }
  const { pr, sha } = ctx;
  const trigger = (process.env.TRIGGER_WORKFLOW || "").trim();
  log(`rendering quality report for PR #${pr} @ ${sha}${trigger ? ` (triggered by ${trigger})` : ""}`);

  const checks = indexChecks(checkRunsForSha(sha));
  const artifacts = collectArtifacts(sha);
  const body = renderComment({ sha, checks, floor: readFloor(), ...artifacts });

  upsertComment(pr, body);
}

// ---------------------------------------------------------------- context resolution
function resolveContext() {
  if ((process.env.EVENT_NAME || "").trim() === "workflow_dispatch") {
    const raw = (process.env.DISPATCH_PR || "").trim();
    if (!raw) return null;
    if (!/^\d+$/.test(raw)) throw new Error(`invalid pr input: ${raw}`);
    const view = ghApiObject(`/repos/${REPO}/pulls/${raw}`);
    if (!view || !view.head?.sha) throw new Error(`could not resolve head SHA for PR #${raw}`);
    return { pr: Number(raw), sha: view.head.sha };
  }

  const sha = (process.env.HEAD_SHA || "").trim();
  if (!sha) return null;
  const pr = resolvePrForSha(sha);
  return pr ? { pr, sha } : null;
}

function resolvePrForSha(sha) {
  // The workflow_run payload already links same-repo PRs — prefer it to save an API call.
  const payload = (process.env.PULL_REQUESTS_JSON || "").trim();
  if (payload && payload !== "null") {
    try {
      const arr = JSON.parse(payload);
      if (Array.isArray(arr) && arr.length && arr[0]?.number) return Number(arr[0].number);
    } catch {
      /* fall through to the commit -> pulls lookup */
    }
  }
  const pulls = ghApiArray(`/repos/${REPO}/commits/${sha}/pulls`);
  const chosen = pulls.find((p) => p.state === "open") || pulls[0];
  return chosen ? Number(chosen.number) : null;
}

// ---------------------------------------------------------------- gate status (check-runs)
function checkRunsForSha(sha) {
  const obj = ghApiObject(`/repos/${REPO}/commits/${sha}/check-runs?per_page=100`);
  return Array.isArray(obj?.check_runs) ? obj.check_runs : [];
}

function indexChecks(list) {
  const map = new Map();
  for (const c of list) {
    if (!c?.name) continue;
    const prev = map.get(c.name);
    // Keep the most recent attempt so a re-run supersedes an earlier conclusion.
    if (!prev || Date.parse(c.started_at || 0) >= Date.parse(prev.started_at || 0)) map.set(c.name, c);
  }
  return map;
}

function gateStatus(checks, name) {
  const c = checks.get(name);
  if (!c) return { symbol: "⏳", word: "pending" };
  if (c.status !== "completed") return { symbol: "⏳", word: "running" };
  switch (c.conclusion) {
    case "success":
      return { symbol: "✅", word: "pass" };
    case "failure":
    case "timed_out":
    case "action_required":
      return { symbol: "❌", word: "fail" };
    case "cancelled":
      return { symbol: "⚪", word: "cancelled" };
    case "neutral":
    case "skipped":
      return { symbol: "⚪", word: c.conclusion };
    default:
      return { symbol: "⏳", word: c.conclusion || "pending" };
  }
}

// ---------------------------------------------------------------- gate metrics (artifacts)
function collectArtifacts(sha) {
  const latest = new Map();
  for (const r of runsForSha(sha)) {
    if (!GATE_WORKFLOW_PATHS.includes(r.path)) continue;
    const prev = latest.get(r.path);
    if (!prev || Number(r.id) > Number(prev.id)) latest.set(r.path, r);
  }

  const dir = mkdtempSync(join(tmpdir(), "quality-report-"));
  try {
    for (const [, run] of latest) {
      downloadRunArtifacts(run.id, join(dir, `run-${run.id}`));
    }
    const coverageFile = findFileByName(dir, "coverage.xml");
    const junitFile = findFileByName(dir, "junit.xml");
    const phpstanFile = findFileByName(dir, "phpstan.json");
    const infectionFile = findFileByName(dir, "infection.json");
    const playwrightFile = findPlaywrightReport(dir);
    return {
      coverage: coverageFile ? safe(() => readCoverage(coverageFile)) : null,
      junit: junitFile ? safe(() => readJunit(junitFile)) : null,
      phpstan: phpstanFile ? safe(() => readPhpstan(phpstanFile)) : null,
      infection: infectionFile ? safe(() => readInfection(infectionFile)) : null,
      e2e: playwrightFile ? safe(() => readPlaywright(playwrightFile)) : null,
    };
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

function runsForSha(sha) {
  const obj = ghApiObject(`/repos/${REPO}/actions/runs?head_sha=${sha}&per_page=100`);
  return Array.isArray(obj?.workflow_runs) ? obj.workflow_runs : [];
}

function downloadRunArtifacts(runId, dir) {
  // A gate with no artifacts (e.g. Frontend) or a run that has not uploaded yet fails here — ignore it,
  // the corresponding metric simply stays absent and its row renders from the check-run status.
  gh(["run", "download", String(runId), "--repo", REPO, "--dir", dir], { allowFail: true });
}

// ---------------------------------------------------------------- artifact parsers
// Coverage floor is the single source of truth in php-tests.yml (MIN_LINE_COVERAGE); read it from the
// checked-out workflow so the report never drifts from the gate that actually enforces it.
function readFloor() {
  const path = ".github/workflows/php-tests.yml";
  if (!existsSync(path)) return null;
  const m = readFileSync(path, "utf8").match(/MIN_LINE_COVERAGE:\s*['"]?([0-9.]+)/);
  return m ? Number(m[1]) : null;
}

function readCoverage(path) {
  const xml = readFileSync(path, "utf8");
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
  const totals = JSON.parse(readFileSync(path, "utf8")).totals || {};
  const fileErrors = Number(totals.file_errors ?? 0);
  const generalErrors = Number(totals.errors ?? 0);
  return { errors: fileErrors + generalErrors };
}

function readJunit(path) {
  const xml = readFileSync(path, "utf8");
  // The grand-total suite always carries the largest `tests` count; take it regardless of nesting.
  let best = null;
  for (const tag of xml.match(/<testsuite\b[^>]*>/g) || []) {
    const tests = attrNum(tag, "tests");
    if (tests == null) continue;
    if (!best || tests > best.tests) {
      best = { tests, failures: attrNum(tag, "failures") ?? 0, errors: attrNum(tag, "errors") ?? 0 };
    }
  }
  if (!best) return null;
  best.passed = best.tests - best.failures - best.errors;
  return best;
}

function readInfection(path) {
  const stats = JSON.parse(readFileSync(path, "utf8")).stats || {};
  return { msi: stats.msi ?? null, coveredMsi: stats.coveredCodeMsi ?? stats.coveredMsi ?? null };
}

function readPlaywright(path) {
  const stats = JSON.parse(readFileSync(path, "utf8")).stats || {};
  const expected = Number(stats.expected ?? 0);
  const unexpected = Number(stats.unexpected ?? 0);
  const flaky = Number(stats.flaky ?? 0);
  const skipped = Number(stats.skipped ?? 0);
  return { passed: expected + flaky, failed: unexpected, skipped, total: expected + unexpected + flaky + skipped };
}

// ---------------------------------------------------------------- rendering
function renderComment(d) {
  const rows = [
    row("PHPUnit (8.5 · SQLite)", d.checks, CHECK_PHPUNIT, d.junit ? `${d.junit.passed} / ${d.junit.tests} passed` : null),
    row("Line coverage", d.checks, CHECK_PHPUNIT, coverageDetail(d.coverage, d.floor)),
    row("PHPStan (level 8)", d.checks, CHECK_PHPSTAN, d.phpstan ? plural(d.phpstan.errors, "error") : null),
    row("Infection (diff MSI)", d.checks, CHECK_INFECTION, d.infection?.msi != null ? `MSI ${Number(d.infection.msi).toFixed(1)}%` : null),
    row("E2E (smoke)", d.checks, CHECK_E2E, d.e2e ? `${d.e2e.passed} / ${d.e2e.total} specs` : null),
    row("CS-Fixer", d.checks, CHECK_CSFIXER, null),
    row("Security audit", d.checks, CHECK_SECURITY, null),
    row("Frontend", d.checks, CHECK_FRONTEND, null),
  ];

  return [
    MARKER,
    "",
    "### 🔍 Quality Report",
    "",
    "| Gate | Status | Detail |",
    "| ---- | :----: | ------ |",
    ...rows,
    "",
    `<sub>✅ pass · ❌ fail · ⏳ pending (gate not reported yet) · ⚪ skipped. Commit \`${d.sha.slice(0, 7)}\` · updated ${new Date().toISOString()}.</sub>`,
    "",
  ].join("\n");
}

function row(label, checks, checkName, detail) {
  const status = gateStatus(checks, checkName);
  const cell = detail != null && detail !== "" ? detail : status.word;
  return `| ${label} | ${status.symbol} | ${cell} |`;
}

function coverageDetail(coverage, floor) {
  if (!coverage) return null;
  const pct = `${coverage.percent.toFixed(2)}%`;
  return floor != null ? `${pct} (floor ${floor.toFixed(2)}%)` : pct;
}

// ---------------------------------------------------------------- comment upsert
function upsertComment(pr, body) {
  const existing = ghApiArray(`/repos/${REPO}/issues/${pr}/comments?per_page=100`).find(
    (c) => typeof c.body === "string" && c.body.includes(MARKER),
  );
  if (existing) {
    gh(["api", "--method", "PATCH", `/repos/${REPO}/issues/comments/${existing.id}`, "-F", "body=@-"], { input: body });
    log(`updated sticky comment ${existing.id} on PR #${pr}`);
  } else {
    gh(["api", "--method", "POST", `/repos/${REPO}/issues/${pr}/comments`, "-F", "body=@-"], { input: body });
    log(`created sticky comment on PR #${pr}`);
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
    if (!path.endsWith(".json")) continue;
    try {
      const text = readFileSync(path, "utf8");
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
  const out = gh(["api", endpoint], { allowFail: true });
  if (!out) return null;
  try {
    return JSON.parse(out);
  } catch {
    return null;
  }
}

// For endpoints whose top-level response is a JSON array; gh --paginate merges array pages into one.
function ghApiArray(endpoint) {
  const out = gh(["api", "--paginate", endpoint], { allowFail: true });
  if (!out) return [];
  try {
    const json = JSON.parse(out);
    return Array.isArray(json) ? json : [];
  } catch {
    return [];
  }
}

function gh(args, { allowFail = false, input } = {}) {
  try {
    return execFileSync("gh", args, {
      input,
      stdio: [input === undefined ? "ignore" : "pipe", "pipe", "pipe"],
      encoding: "utf8",
      maxBuffer: 64 * 1024 * 1024,
    }).trim();
  } catch (e) {
    if (allowFail) return null;
    const detail = e.stderr?.toString().trim() || e.stdout?.toString().trim() || e.message;
    throw new Error(`gh ${args.join(" ")} failed: ${detail}`);
  }
}

function attrNum(tag, attr) {
  const m = tag.match(new RegExp(`\\b${attr}="(\\d+)"`));
  return m ? Number(m[1]) : null;
}

function plural(count, noun) {
  return `${count} ${noun}${count === 1 ? "" : "s"}`;
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
