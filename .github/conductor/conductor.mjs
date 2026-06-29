// V2 Conductor — drives the orchestrator pipeline from GitHub Actions.
//
// Zero npm dependencies on purpose: Node 20 (global fetch) + git + gh (preinstalled on
// ubuntu-latest). It launches ONE fresh cloud agent per phase via the Cloud Agents REST API
// (api.cursor.com/v1), reads progress from the ticket's `## EXECUTION STATE` checkboxes, and
// reacts only to ESCALATE / fatal results. It holds no LLM context itself.
//
// NOTE (verify on first real run): the v1 Cloud Agents API is in public beta. The field names
// used below (`agent.id`, `run.id`, run `status`/`result`, `/usage`, `/runs/{id}/cancel`) follow
// the documented surface; if a field name drifts, adjust the small `api()` call sites here.

import { execSync } from "node:child_process";
import { readFileSync, existsSync } from "node:fs";
import { basename } from "node:path";

// ---------------------------------------------------------------- config (from env)
const API = "https://api.cursor.com";
const KEY = required("CURSOR_API_KEY");
const REPO_URL = required("REPO_URL");
const TASK_PROMPT = required("TASK_PROMPT");
const ISSUE = (process.env.ISSUE || "").trim();
const BASE = (process.env.BASE || "develop").trim();
const SLUG = (process.env.SLUG || basename(TASK_PROMPT).replace(/\.md$/i, "")).trim();
const BRANCH = (process.env.BRANCH || `feature/${SLUG}`).trim();
const TICKET = `migration-docs/tickets/active/${SLUG}_plan.md`;
const BUDGET = Number(process.env.BATCH_BUDGET || 6);
const MODEL = (process.env.MODEL || "auto").trim();
const MAX_ESCALATIONS = Number(process.env.MAX_ESCALATIONS || 2);
const POLL_MS = Number(process.env.POLL_MS || 15000);
const WEIGHTS = { S: 1, M: 2, L: 4 };

// Validate everything that flows into a shell command or a path (defense-in-depth; only
// collaborators can dispatch this workflow, but never trust interpolation).
validate("SLUG", SLUG, /^[A-Za-z0-9._/-]+$/);
validate("BRANCH", BRANCH, /^[A-Za-z0-9._/-]+$/);
validate("BASE", BASE, /^[A-Za-z0-9._/-]+$/);
validate("TASK_PROMPT", TASK_PROMPT, /^[A-Za-z0-9._/-]+$/);
validate("MODEL", MODEL, /^[A-Za-z0-9._-]+$/);
if (ISSUE) validate("ISSUE", ISSUE, /^[0-9]+$/);
if (!Number.isInteger(BUDGET) || BUDGET < 1) fail(`Invalid BATCH_BUDGET: ${process.env.BATCH_BUDGET}`);

let current = null; // { agentId, runId } of the in-flight run, for cancellation

// ---------------------------------------------------------------- main
(async () => {
  log(`Conductor start — slug=${SLUG} branch=${BRANCH} base=${BASE} budget=${BUDGET} model=${MODEL}`);
  ensureBranch();

  // PLAN phase — only if the ticket does not exist yet (resume-safe).
  pullBranch();
  if (!existsSync(TICKET)) {
    await runPhaseWithEscalation("PLAN", () => planPrompt());
    pullBranch();
  } else {
    log(`ticket already present (${TICKET}) — skipping PLAN`);
  }

  // EXECUTE loop — recompute the batch from the live checkboxes each iteration.
  let escalations = 0;
  let prevOpen = Infinity;
  for (;;) {
    await gate();
    pullBranch();
    const steps = readSteps();
    if (steps === null) fail(`ticket not found after PLAN: ${TICKET}`);
    if (steps.length === 0) fail(`no parseable "## EXECUTION STATE" steps in ${TICKET}`);

    const open = steps.filter((s) => !s.checked);
    if (open.length === 0) { log("all checklist steps complete"); break; }
    if (open.length < prevOpen) escalations = 0; // progress resets the stuck counter
    prevOpen = open.length;

    const batch = nextBatch(open);
    log(`next batch: steps ${batch.join(",")}  (open ${open.length}/${steps.length})`);

    let result;
    try {
      result = await runPhase(`EXECUTE ${batch.join(",")}`, stepPrompt(batch));
    } catch (e) {
      if (++escalations > MAX_ESCALATIONS) fail(`EXECUTE run error ${escalations}x: ${e.message}`);
      log(`run error (${e.message}); relaunching fresh (${escalations}/${MAX_ESCALATIONS})`);
      continue;
    }
    if (result.startsWith("ESCALATE")) {
      if (++escalations > MAX_ESCALATIONS) fail(`EXECUTE escalated ${escalations}x: ${result}`);
      log(`escalated; relaunching fresh, batch recomputed (${escalations}/${MAX_ESCALATIONS})`);
      continue;
    }
    log(`EXECUTE result: ${result}`); // "Batch done" or "Batch stopped (preCompact) ..." -> loop re-reads
  }

  // FINALIZE phase.
  await gate();
  await runPhaseWithEscalation("FINALIZE", () => finalizePrompt());

  log("✅ Conductor done. Review and merge the PR (it is intentionally left open).");
})().catch((e) => { log(`fatal: ${e.stack || e.message}`); process.exit(1); });

// ---------------------------------------------------------------- phases
async function runPhase(label, prompt) {
  log(`▶ ${label}: launching cloud agent (model=${MODEL})`);
  const created = await api("POST", "/v1/agents", {
    prompt: { text: prompt },
    model: { id: MODEL },
    repos: [{ url: REPO_URL, startingRef: BRANCH }],
    workOnCurrentBranch: true,
    skipReviewerRequest: true,
  });
  const agentId = created.agent?.id ?? created.id;
  const runId = created.run?.id ?? created.latestRunId ?? created.run?.runId;
  if (!agentId || !runId) throw new Error(`unexpected create response: ${JSON.stringify(created).slice(0, 300)}`);
  current = { agentId, runId };
  log(`  agent=${agentId} run=${runId} ${created.agent?.url ? `url=${created.agent.url}` : ""}`);

  const text = await poll(agentId, runId);
  current = null;
  await logUsage(agentId);
  return text;
}

// PLAN / FINALIZE: fixed prompt, relaunch fresh on ESCALATE/run-error up to MAX_ESCALATIONS.
async function runPhaseWithEscalation(label, makePrompt) {
  for (let attempt = 0; ; attempt++) {
    await gate();
    let result;
    try {
      result = await runPhase(attempt ? `${label} (retry ${attempt})` : label, makePrompt());
    } catch (e) {
      if (attempt >= MAX_ESCALATIONS) fail(`${label} run error after ${attempt} retries: ${e.message}`);
      log(`run error (${e.message}); relaunching fresh (${attempt + 1}/${MAX_ESCALATIONS})`);
      continue;
    }
    if (result.startsWith("ESCALATE")) {
      if (attempt >= MAX_ESCALATIONS) fail(`${label} escalated ${attempt + 1}x: ${result}`);
      log(`escalated (${result}); relaunching fresh (${attempt + 1}/${MAX_ESCALATIONS})`);
      continue;
    }
    log(`${label} result: ${result}`);
    return result;
  }
}

async function poll(agentId, runId) {
  const TERMINAL = new Set(["FINISHED", "ERROR", "CANCELLED", "EXPIRED"]);
  for (;;) {
    await sleep(POLL_MS);
    let run;
    try {
      run = await api("GET", `/v1/agents/${agentId}/runs/${runId}`);
    } catch (e) {
      log(`  poll error (retrying): ${e.message}`);
      continue;
    }
    const status = String(run.status ?? run.run?.status ?? "").toUpperCase();
    if (!TERMINAL.has(status)) continue;
    if (status !== "FINISHED") throw new Error(`run ${status}`);
    return String(run.result ?? run.run?.result ?? "").trim();
  }
}

// ---------------------------------------------------------------- prompts
function planPrompt() {
  return [
    "You are the Orchestrator for the PLAN phase. Follow the rule .cursor/rules/orchestrator-v2-plan.mdc exactly.",
    `Task prompt: ${TASK_PROMPT}`,
    `Branch: ${BRANCH} (verify you are on it first; checkout/create if needed).`,
    ISSUE ? `GitHub issue: #${ISSUE}` : "",
    "Report exactly one line as that rule specifies.",
  ].filter(Boolean).join("\n");
}
function stepPrompt(batch) {
  return [
    "You are the Orchestrator for the EXECUTE phase. Follow the rule .cursor/rules/orchestrator-v2-step.mdc exactly.",
    `Ticket: ${TICKET}`,
    `Steps: ${batch.join(",")}`,
    `Branch: ${BRANCH} (verify you are on it first).`,
    "Report exactly one line as that rule specifies.",
  ].join("\n");
}
function finalizePrompt() {
  return [
    "You are the Orchestrator for the FINALIZE phase. Follow the rule .cursor/rules/orchestrator-v2-finalize.mdc exactly.",
    `Ticket: ${TICKET}`,
    `Branch: ${BRANCH} (verify you are on it first).`,
    ISSUE ? `GitHub issue: #${ISSUE}` : "",
    "Do NOT merge. Report exactly one line as that rule specifies.",
  ].filter(Boolean).join("\n");
}

// ---------------------------------------------------------------- ticket state
function readSteps() {
  if (!existsSync(TICKET)) return null;
  const md = readFileSync(TICKET, "utf8");
  const idx = md.indexOf("## EXECUTION STATE");
  if (idx < 0) return [];
  const section = md.slice(idx).split(/\n## /)[0];
  const steps = [];
  const re = /^- \[( |x)\] Step (\d+) \(([SML])\)/gim;
  let m;
  while ((m = re.exec(section))) {
    steps.push({ n: Number(m[2]), size: m[3].toUpperCase(), checked: m[1].toLowerCase() === "x" });
  }
  return steps;
}

function nextBatch(open) {
  const batch = [];
  let w = 0;
  for (const s of open) {
    const sw = WEIGHTS[s.size] ?? 2;
    if (batch.length > 0 && w + sw > BUDGET) break; // always include at least the first open step
    batch.push(s.n);
    w += sw;
  }
  return batch;
}

// ---------------------------------------------------------------- control & lifecycle
function controlSignal() {
  if (!ISSUE) return "run";
  try {
    const out = sh(`gh issue view ${ISSUE} --json labels --jq '.labels[].name'`);
    const labels = out.split("\n").map((s) => s.trim()).filter(Boolean);
    if (labels.includes("conductor:stop")) return "stop";
    if (labels.includes("conductor:pause")) return "pause";
  } catch (e) {
    log(`control check failed (ignoring): ${e.message}`);
  }
  return "run";
}

// Called at every phase boundary: honor stop/pause labels on the tracking issue.
async function gate() {
  for (;;) {
    const s = controlSignal();
    if (s === "stop") { log("⛔ conductor:stop — cancelling and exiting."); await cancelCurrent(); process.exit(3); }
    if (s === "pause") { log("⏸ conductor:pause — waiting (remove the label to resume)…"); await sleep(30000); continue; }
    return;
  }
}

async function cancelCurrent() {
  if (!current) return;
  try {
    await api("POST", `/v1/agents/${current.agentId}/runs/${current.runId}/cancel`);
    log("  cancelled the in-flight run.");
  } catch (e) {
    log(`  cancel failed (may already be terminal): ${e.message}`);
  }
}

// Hard stop: cancelling the GitHub Actions run sends SIGINT/SIGTERM — cancel the live agent first.
for (const signal of ["SIGINT", "SIGTERM"]) {
  process.on(signal, async () => {
    log(`signal ${signal} — cancelling the in-flight run before exit.`);
    await cancelCurrent();
    process.exit(130);
  });
}

async function logUsage(agentId) {
  try {
    const u = await api("GET", `/v1/agents/${agentId}/usage`);
    log(`  tokens: in=${u.inputTokens ?? "?"} out=${u.outputTokens ?? "?"} cacheR=${u.cacheReadTokens ?? "?"} cacheW=${u.cacheWriteTokens ?? "?"} total=${u.totalTokens ?? "?"}`);
  } catch { /* best-effort */ }
  try {
    const a = await api("GET", `/v1/agents/${agentId}/artifacts`);
    const n = Array.isArray(a.artifacts) ? a.artifacts.length : Array.isArray(a) ? a.length : 0;
    if (n) log(`  artifacts: ${n}`);
  } catch { /* best-effort */ }
}

// ---------------------------------------------------------------- git
function ensureBranch() {
  if (sh(`git ls-remote --heads origin ${BRANCH}`)) {
    log(`branch ${BRANCH} already exists on origin`);
    return;
  }
  log(`creating ${BRANCH} from origin/${BASE}`);
  sh(`git fetch origin ${BASE}`);
  sh(`git checkout -B ${BRANCH} origin/${BASE}`);
  sh(`git push -u origin ${BRANCH}`); // empty branch pointer push; no commit/identity needed
}

function pullBranch() {
  sh(`git fetch origin ${BRANCH}`);
  sh(`git checkout -B ${BRANCH} origin/${BRANCH}`);
}

// ---------------------------------------------------------------- low-level
async function api(method, path, body) {
  const res = await fetch(`${API}${path}`, {
    method,
    headers: { Authorization: `Bearer ${KEY}`, "Content-Type": "application/json" },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let json;
  try { json = text ? JSON.parse(text) : {}; } catch { json = { raw: text }; }
  if (!res.ok) {
    const e = new Error(`API ${method} ${path} -> ${res.status}: ${text.slice(0, 300)}`);
    e.status = res.status;
    throw e;
  }
  return json;
}

function sh(cmd) {
  return execSync(cmd, { stdio: ["ignore", "pipe", "pipe"], encoding: "utf8" }).trim();
}
function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }
function log(...a) { console.log(new Date().toISOString(), ...a); }
function required(name) {
  const v = process.env[name];
  if (!v) { console.error(`Missing required env: ${name}`); process.exit(1); }
  return v.trim();
}
function validate(name, val, re) {
  if (val && !re.test(val)) { console.error(`Invalid ${name}: ${val}`); process.exit(1); }
}
function fail(msg) { log(`❌ ${msg}`); process.exit(2); }
