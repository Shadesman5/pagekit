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
// Ticket filename ALWAYS derives from the task-prompt basename — the Architect derives it the same way,
// so a custom slug/branch input (which only renames the branch) can never desync the ticket path.
const TICKET_SLUG = basename(TASK_PROMPT).replace(/\.md$/i, "").trim();
const TICKET = `migration-docs/tickets/active/${TICKET_SLUG}_plan.md`;
const BUDGET = Number(process.env.BATCH_BUDGET || 6);
const MODEL = (process.env.MODEL || "auto").trim();
const MODE_INPUT = (process.env.MODE || "auto").trim().toLowerCase(); // dispatch: auto|full|plan ("auto" = task prompt self-declares, see resolveMode)
const MAX_ESCALATIONS = Number(process.env.MAX_ESCALATIONS || 2);
const POLL_MS = Number(process.env.POLL_MS || 15000);
const MAX_POLL_FAILS = Number(process.env.MAX_POLL_FAILS || 6); // consecutive poll errors before a phase fails
const WEIGHTS = { S: 1, M: 2, L: 4 };

// Validate everything that flows into a shell command or a path (defense-in-depth; only
// collaborators can dispatch this workflow, but never trust interpolation).
validate("SLUG", SLUG, /^[A-Za-z0-9._/-]+$/);
validate("BRANCH", BRANCH, /^[A-Za-z0-9._/-]+$/);
validate("BASE", BASE, /^[A-Za-z0-9._/-]+$/);
validate("TASK_PROMPT", TASK_PROMPT, /^[A-Za-z0-9._/-]+$/);
validate("MODEL", MODEL, /^[A-Za-z0-9._-]+$/);
validate("MODE", MODE_INPUT, /^(auto|full|plan)$/);
if (ISSUE) validate("ISSUE", ISSUE, /^[0-9]+$/);
if (!Number.isInteger(BUDGET) || BUDGET < 1) fail(`Invalid BATCH_BUDGET: ${process.env.BATCH_BUDGET}`);

let current = null; // { agentId, runId } of the in-flight run, for cancellation

// ---------------------------------------------------------------- main
(async () => {
  ensureBranch();
  pullBranch();
  // Resolve mode/audit AFTER checking out the feature branch, so a marker/prompt that exists on the
  // branch (not just the base checkout) is honored.
  const { mode: MODE, audit: AUDIT } = resolveRun();
  log(`Conductor start — slug=${SLUG} branch=${BRANCH} base=${BASE} budget=${BUDGET} model=${MODEL} mode=${MODE}${AUDIT ? " (audit/report)" : ""}`);
  // Issue is mandatory for normal runs (PR Closes #N + stop/pause control labels); audits have none.
  if (!AUDIT && !ISSUE) fail("ISSUE is required for non-audit runs (PR Closes #N + conductor:stop/pause labels). Only audit tasks (a `conductor-mode: plan` marker in the task prompt) may omit it.");

  // Fully-finalized resume guard: Finalize archives the ticket to done/. If it is there, the whole
  // pipeline already completed for this slug — nothing to redo on a re-dispatch.
  if (!AUDIT && existsSync(`migration-docs/tickets/done/${TICKET_SLUG}_plan.md`)) {
    log("✅ already finalized (ticket archived to done/) — nothing to do.");
    return;
  }

  // PLAN phase — skip only if the plan work is already done: the ticket for normal tasks, or an open PR
  // for audits (which deliver a report, not a ticket). Keeps audits resume-safe too.
  const planAlreadyDone = AUDIT ? openPrExists() : existsSync(TICKET);
  let planRan = false;
  if (!planAlreadyDone) {
    await runPhaseWithEscalation("PLAN", () => planPrompt(AUDIT));
    pullBranch();
    planRan = true;
  } else {
    log(`plan already done (${AUDIT ? `open PR for ${BRANCH}` : TICKET}) — skipping PLAN`);
  }

  // Plan-only (Step-0 gate): stop cleanly after PLAN (audit/report task or review-only gate). Normal
  // runs (mode=full) fall through to EXECUTE.
  if (MODE === "plan") {
    const detail = AUDIT ? (planRan ? " (audit: report + PR opened this run)" : " (audit: PR already open)") : "";
    log(`✅ Conductor done (mode=plan): Step-0 gate complete${detail} - EXECUTE/FINALIZE skipped by design.`);
    return;
  }

  // EXECUTE loop — recompute the batch from the live checkboxes each iteration.
  let escalations = 0;
  let prevOpen = Infinity;
  for (;;) {
    await gate();
    pullBranch();
    const steps = readSteps();
    if (steps === null) fail(`ticket not found after PLAN: ${TICKET} (audit/report task? re-dispatch with MODE=plan for a Step-0-gate-only run)`);
    if (steps.length === 0) fail(`no parseable "## EXECUTION STATE" steps in ${TICKET} (audit/report task? use MODE=plan)`);

    const open = steps.filter((s) => !s.checked);
    if (open.length === 0) { log("all checklist steps complete"); break; }
    // Stuck detection is progress-based: fewer open steps than the previous pass = progress (reset the
    // counter); no reduction after a batch = a stuck round — whether the agent ESCALATEd, errored, or
    // falsely claimed "done". Bail after MAX_ESCALATIONS stuck rounds so a no-op can't loop to the job cap.
    if (open.length < prevOpen) {
      escalations = 0;
    } else if (prevOpen !== Infinity) {
      if (++escalations > MAX_ESCALATIONS) fail(`EXECUTE stuck: no progress in ${escalations} rounds (open ${open.length}/${steps.length})`);
      log(`no progress since last batch (open ${open.length}/${steps.length}); stuck ${escalations}/${MAX_ESCALATIONS}`);
    }
    prevOpen = open.length;

    const batch = nextBatch(open);
    log(`next batch: steps ${batch.join(",")}  (open ${open.length}/${steps.length})`);

    let result;
    try {
      result = await runPhase(`EXECUTE ${batch.join(",")}`, stepPrompt(batch));
    } catch (e) {
      log(`run error (${e.message}); relaunching fresh (stuck ${escalations}/${MAX_ESCALATIONS})`);
      continue;
    }
    if (result.startsWith("ESCALATE")) {
      log(`escalated (${result}); relaunching fresh, batch recomputed (stuck ${escalations}/${MAX_ESCALATIONS})`);
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
  let fails = 0;
  for (;;) {
    await sleep(POLL_MS);
    let run;
    try {
      run = await api("GET", `/v1/agents/${agentId}/runs/${runId}`);
      fails = 0; // a good poll clears transient errors
    } catch (e) {
      if (++fails > MAX_POLL_FAILS) throw new Error(`poll failed ${fails}x (last: ${e.message})`);
      log(`  poll error ${fails}/${MAX_POLL_FAILS} (retrying): ${e.message}`);
      continue;
    }
    const status = String(run.status ?? run.run?.status ?? "").toUpperCase();
    if (!TERMINAL.has(status)) continue;
    if (status !== "FINISHED") throw new Error(`run ${status}`);
    return String(run.result ?? run.run?.result ?? "").trim();
  }
}

// ---------------------------------------------------------------- prompts
function planPrompt(audit) {
  return [
    "You are the Orchestrator for the PLAN phase. Follow the rule .cursor/rules/orchestrator-v2-plan.mdc exactly.",
    `Task prompt: ${TASK_PROMPT}`,
    `Branch: ${BRANCH} (verify you are on it first; checkout/create if needed).`,
    `Base branch: ${BASE}`,
    ISSUE ? `GitHub issue: #${ISSUE}` : "",
    audit
      ? `This is an AUDIT/REPORT task (report deliverable, no executable ticket): follow the rule's "Audit / report tasks" section. After the plan-reviewer PASSes, push and open a PR against ${BASE} — docs only: NO version bump, NO CHANGELOG, NO ROADMAP edits; do not merge.`
      : "",
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
    `Base branch: ${BASE} (open the PR against this base).`,
    ISSUE ? `GitHub issue: #${ISSUE}` : "",
    "Do NOT merge. Report exactly one line as that rule specifies.",
  ].filter(Boolean).join("\n");
}

// ---------------------------------------------------------------- ticket state
// Resolve the run's pipeline mode + whether it's an audit/report task. The task prompt's marker
// `<!-- conductor-mode: plan -->` flags an AUDIT/REPORT task (report deliverable, no executable ticket);
// an explicit dispatch input (full|plan) still overrides the *mode*. For audits the Plan phase also opens
// a docs-only PR, since Execute/Finalize never run. Reading one small file is deterministic config, not
// LLM context. "plan" WITHOUT the marker (explicit input) = a review-only gate: plan, then stop, no PR.
function resolveRun() {
  let audit = false;
  try {
    audit = /conductor-mode:\s*plan\b/i.test(readFileSync(TASK_PROMPT, "utf8"));
  } catch (e) {
    log(`could not read ${TASK_PROMPT} for the conductor-mode marker: ${e.message}`);
  }
  // An audit has no executable ticket, so it is ALWAYS plan-only — an explicit mode=full cannot override
  // that (it would only half-run: produce the report/PR in Plan, then fail the Execute guard).
  const mode = audit ? "plan" : (MODE_INPUT === "auto" ? "full" : MODE_INPUT);
  if (audit && MODE_INPUT === "full") log(`note: audit task is plan-only; ignoring mode=full (no executable ticket)`);
  return { mode, audit };
}
function readSteps() {
  if (!existsSync(TICKET)) return null;
  const md = readFileSync(TICKET, "utf8");
  const idx = md.indexOf("## EXECUTION STATE");
  if (idx < 0) return [];
  const section = md.slice(idx).split(/\n## /)[0];
  const steps = [];
  // Parse every checkbox line. The size hint is optional (defaults to M). A checkbox line that clearly
  // means a Step but does not parse is treated as malformed and fails loudly — silently skipping it could
  // drop an open step and finalize prematurely (PR #213 Bugbot #7).
  const seen = new Set();
  for (const line of section.split("\n")) {
    const box = line.match(/^\s*- \[( |x)\]\s*(.*)$/i);
    if (!box) continue;
    const step = box[2].match(/^Step\s+(\d+)\s*(?:\(([SMLsml])\))?/);
    if (!step) {
      if (/^step/i.test(box[2])) fail(`malformed EXECUTION STATE line in ${TICKET}: "${line.trim()}"`);
      continue; // non-step checkbox line (e.g. a note) — ignore
    }
    const n = Number(step[1]);
    if (seen.has(n)) fail(`duplicate Step ${n} in ${TICKET} EXECUTION STATE — step numbers must be unique`);
    seen.add(n);
    steps.push({ n, size: (step[2] || "M").toUpperCase(), checked: box[1].toLowerCase() === "x" });
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

// True if an open PR already exists for this feature branch — makes audit runs resume-safe (audits
// deliver a report + PR, not a ticket, so there is no ticket file to detect already-completed work).
function openPrExists() {
  try {
    return Number(sh(`gh pr list --head ${BRANCH} --base ${BASE} --state open --json number --jq 'length'`)) > 0;
  } catch (e) {
    log(`could not check for an existing PR (${e.message}); assuming none`);
    return false;
  }
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
