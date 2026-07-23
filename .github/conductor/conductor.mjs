// V2 Conductor — drives the orchestrator pipeline from GitHub Actions.
//
// Zero npm dependencies on purpose: Node 20 (global fetch) + git + gh (preinstalled on
// ubuntu-latest). It launches ONE fresh cloud agent per GitHub Actions job via the Cloud Agents
// REST API (api.cursor.com/v1), reads progress from the ticket's `## EXECUTION STATE` checkboxes,
// and reacts only to ESCALATE / fatal results. It holds no LLM context itself.
//
// Chained runs (auto_chain=true, default): each GHA job runs at most ONE cloud-agent phase
// (PLAN, or one EXECUTE batch sized by batch_budget, or FINALIZE), then dispatches a fresh
// workflow run when more work remains. This keeps every job under GitHub's 360-minute hosted cap.
//
// NOTE (verify on first real run): the v1 Cloud Agents API is in public beta. The field names
// used below (`agent.id`, `run.id`, run `status`/`result`, `/usage`, `/runs/{id}/cancel`) follow
// the documented surface; if a field name drifts, adjust the small `api()` call sites here.

import { execSync, execFileSync } from "node:child_process";
import { readFileSync, existsSync } from "node:fs";
import { basename } from "node:path";
import { createMetricsCollector, DEFAULT_METRICS_BRANCH } from "./metrics.mjs";

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
const MODEL = (process.env.MODEL || "").trim(); // empty -> omit `model` (account default); else passed as model.id (see runPhase)
const MODE_INPUT = (process.env.MODE || "auto").trim().toLowerCase(); // dispatch: auto|full|plan ("auto" = task prompt self-declares, see resolveMode)
const AUTO_CHAIN = parseBool(process.env.AUTO_CHAIN, true); // when true, dispatch a fresh workflow run after each phase/batch
const TITLE = (process.env.TITLE || "").trim(); // optional run display title (passed through on chain)
const WORKFLOW_FILE = (process.env.WORKFLOW_FILE || "conductor.yml").trim();
const WORKFLOW_REF = (process.env.WORKFLOW_REF || BASE).trim();
const MAX_ESCALATIONS = Number(process.env.MAX_ESCALATIONS || 2);
const POLL_MS = Number(process.env.POLL_MS || 15000);
const MAX_POLL_FAILS = Number(process.env.MAX_POLL_FAILS || 6); // consecutive poll errors before a phase fails
// After status=FINISHED the Cloud Agents API can lag a few seconds before `result` is set.
// Without a grace window we treat "" as failure and relaunch PLAN/FINALIZE (seen on 2.1.12).
const RESULT_GRACE_MS = Number(process.env.RESULT_GRACE_MS || 60000);
const WEIGHTS = { S: 1, M: 2, L: 4 };

// Validate everything that flows into a shell command or a path (defense-in-depth; only
// collaborators can dispatch this workflow, but never trust interpolation).
validate("SLUG", SLUG, /^[A-Za-z0-9._/-]+$/);
validate("BRANCH", BRANCH, /^[A-Za-z0-9._/-]+$/);
validate("BASE", BASE, /^[A-Za-z0-9._/-]+$/);
const METRICS_BRANCH = (process.env.METRICS_BRANCH || DEFAULT_METRICS_BRANCH).trim();
validate("METRICS_BRANCH", METRICS_BRANCH, /^[A-Za-z0-9._/-]+$/);
validate("TASK_PROMPT", TASK_PROMPT, /^[A-Za-z0-9._/-]+$/);
if (TASK_PROMPT.split("/").includes("..")) fail(`TASK_PROMPT must not contain '..' path segments: ${TASK_PROMPT}`);
validate("MODEL", MODEL, /^[A-Za-z0-9._-]+$/);
validate("MODE", MODE_INPUT, /^(auto|full|plan)$/);
validate("WORKFLOW_FILE", WORKFLOW_FILE, /^[A-Za-z0-9._-]+\.ya?ml$/);
validate("WORKFLOW_REF", WORKFLOW_REF, /^[A-Za-z0-9._/-]+$/);
if (ISSUE) validate("ISSUE", ISSUE, /^[0-9]+$/);
if (!Number.isInteger(BUDGET) || BUDGET < 1) fail(`Invalid BATCH_BUDGET: ${process.env.BATCH_BUDGET}`);
if (!Number.isInteger(MAX_ESCALATIONS) || MAX_ESCALATIONS < 0) fail(`Invalid MAX_ESCALATIONS: ${process.env.MAX_ESCALATIONS}`);
if (!Number.isInteger(MAX_POLL_FAILS) || MAX_POLL_FAILS < 1) fail(`Invalid MAX_POLL_FAILS: ${process.env.MAX_POLL_FAILS}`);
if (!Number.isInteger(POLL_MS) || POLL_MS < 1) fail(`Invalid POLL_MS: ${process.env.POLL_MS}`);
if (!Number.isInteger(RESULT_GRACE_MS) || RESULT_GRACE_MS < 0) fail(`Invalid RESULT_GRACE_MS: ${process.env.RESULT_GRACE_MS}`);

let current = null; // { agentId, runId, label, startedAt, agentUrl } of the in-flight run, for cancellation
const metrics = createMetricsCollector({
  api,
  sh,
  log,
  env: process.env,
  branch: BRANCH,
  // Unprotected long-lived branch — develop Ruleset blocks direct bot pushes (no bypass).
  metricsBranch: METRICS_BRANCH,
  pullBranch,
});

// ---------------------------------------------------------------- main
(async () => {
  ensureBranch();
  pullBranch();
  metrics.initSession();
  // Resolve mode/audit AFTER checking out the feature branch, so a marker/prompt that exists on the
  // branch (not just the base checkout) is honored.
  const { mode: MODE, audit: AUDIT } = resolveRun();
  log(`Conductor start — slug=${SLUG} branch=${BRANCH} base=${BASE} budget=${BUDGET} model=${MODEL} mode=${MODE} auto_chain=${AUTO_CHAIN}${AUDIT ? " (audit/report)" : ""}`);
  // Issue is mandatory for normal runs (PR Closes #N + stop/pause control labels); audits have none.
  if (!AUDIT && !ISSUE) fail("ISSUE is required for non-audit runs (PR Closes #N + conductor:stop/pause labels). Only audit tasks (a `conductor-mode: plan` marker in the task prompt) may omit it.");

  // Completion signal: Finalize archives the ticket to done/ as its LAST step, so a ticket under done/
  // means Plan+Execute finished. We still run an IDEMPOTENT Finalize below (rather than exiting early),
  // so a re-dispatch can recover an interrupted Finalize instead of being blocked.
  const doneTicket = `migration-docs/tickets/done/${TICKET_SLUG}_plan.md`;
  const alreadyArchived = !AUDIT && existsSync(doneTicket);

  if (!alreadyArchived) {
    // PLAN phase — skip only if the plan work is already done: the ticket for normal tasks, or an open
    // PR for audits (which deliver a report, not a ticket). Keeps audits resume-safe too.
    const planAlreadyDone = AUDIT ? donePrExists() : existsSync(TICKET);
    let planRan = false;
    if (!planAlreadyDone) {
      await runPhaseWithEscalation(
        "PLAN",
        () => planPrompt(AUDIT),
        /^Plan ready:/i,
        // Side-effect recovery: agent may have pushed the ticket/PR before `result` was readable.
        () => {
          pullBranch();
          return AUDIT ? donePrExists() : existsSync(TICKET);
        },
      );
      pullBranch();
      planRan = true;
    } else {
      log(`plan already done (${AUDIT ? `open PR for ${BRANCH}` : TICKET}) — skipping PLAN`);
    }

    // Plan-only (Step-0 gate): stop cleanly after PLAN (audit/report task or review-only gate).
    if (MODE === "plan") {
      const detail = AUDIT ? (planRan ? " (audit: report + PR opened this run)" : " (audit: PR already open)") : "";
      metrics.setSessionStatus("completed");
      log(`✅ Conductor done (mode=plan): Step-0 gate complete${detail} - EXECUTE/FINALIZE skipped by design.`);
      return;
    }

    // After a fresh PLAN, chain so EXECUTE starts in a new GHA job (stays under the 360-minute cap).
    if (planRan) {
      finishJobAndMaybeChain("PLAN complete — next run will EXECUTE");
      return;
    }

    // EXECUTE — one batch per GHA job (batch size still governed by batch_budget + S/M/L hints).
    await gate();
    pullBranch();
    const steps = readSteps();
    if (steps === null) fail(`ticket not found after PLAN: ${TICKET} (audit/report task? re-dispatch with MODE=plan for a Step-0-gate-only run)`);
    if (steps.length === 0) fail(`no parseable "## EXECUTION STATE" steps in ${TICKET} (audit/report task? use MODE=plan)`);

    const open = steps.filter((s) => !s.checked);
    if (open.length > 0) {
      const openBefore = open.length;
      const batch = nextBatch(open);
      log(`next batch: steps ${batch.join(",")}  (open ${open.length}/${steps.length})`);

      await runExecuteBatch(batch);

      pullBranch();
      const stepsAfter = readSteps();
      if (!stepsAfter) fail(`ticket disappeared after EXECUTE batch: ${TICKET}`);
      const openAfter = stepsAfter.filter((s) => !s.checked).length;
      if (openAfter >= openBefore) {
        fail(`EXECUTE stuck: no progress after batch steps ${batch.join(",")} (open ${openAfter}/${stepsAfter.length})`);
      }
      if (openAfter > 0) {
        finishJobAndMaybeChain(`EXECUTE batch ${batch.join(",")} done — ${openAfter} checklist step(s) remaining`);
        return;
      }
      log("all checklist steps complete — chaining FINALIZE to a fresh GHA job");
      finishJobAndMaybeChain("EXECUTE complete — next run will FINALIZE");
      return;
    }

    log("all checklist steps complete — this run will FINALIZE");
  } else {
    log("ticket already archived to done/ — skipping PLAN/EXECUTE; running idempotent FINALIZE to verify.");
  }

  // FINALIZE phase — idempotent (safe to re-enter after a partial or complete prior run).
  await gate();
  await runPhaseWithEscalation(
    "FINALIZE",
    () => finalizePrompt(alreadyArchived ? doneTicket : TICKET),
    /^Finalized\b/i,
    () => {
      pullBranch();
      // Finalize archives the ticket and/or opens the PR — either means the phase landed.
      return existsSync(doneTicket) || donePrExists();
    },
  );

  metrics.setSessionStatus("completed");
  log("✅ Conductor done. Review and merge the PR (it is intentionally left open).");
})().catch((e) => {
  try { metrics.setSessionStatus("failed"); } catch { /* best-effort */ }
  log(`fatal: ${e.stack || e.message}`);
  process.exit(1);
});

// ---------------------------------------------------------------- phases
async function runPhase(label, prompt, outcomeHint) {
  const startedAt = Date.now();
  log(`▶ ${label}: launching cloud agent (model=${MODEL || "account default (unset)"})`);
  // Model resolution: an empty MODEL omits `model`, so Cursor uses the configured default
  // (user -> team -> system). Any non-empty value is passed through as `model.id` — an explicit id
  // (e.g. claude-opus-4-8, composer-2.5), or "auto"/"default" for Cursor's dynamic Auto model (the
  // API resolves the alias). Discover valid ids/aliases via GET /v1/models.
  const body = {
    prompt: { text: prompt },
    repos: [{ url: REPO_URL, startingRef: BRANCH }],
    workOnCurrentBranch: true,
    skipReviewerRequest: true,
  };
  if (MODEL) body.model = { id: MODEL };
  const created = await api("POST", "/v1/agents", body);
  const agentId = created.agent?.id ?? created.id;
  const runId = created.run?.id ?? created.latestRunId ?? created.run?.runId;
  if (!agentId || !runId) throw new Error(`unexpected create response: ${JSON.stringify(created).slice(0, 300)}`);
  const agentUrl = created.agent?.url ?? null;
  current = { agentId, runId, label, startedAt, agentUrl };
  log(`  agent=${agentId} run=${runId} ${agentUrl ? `url=${agentUrl}` : ""}`);

  const text = await poll(agentId, runId);
  current = null;
  await logUsage(agentId);
  const outcome = outcomeHint || (text.startsWith("ESCALATE") ? "escalate" : "success");
  // Metrics push must not invalidate a finished agent run (would relaunch PLAN/EXECUTE).
  try {
    await metrics.recordPhase({ label, startedAt, agentId, runId, agentUrl, result: text, outcome });
  } catch (e) {
    log(`  metrics: recordPhase failed (${e.message}) — continuing with phase result`);
  }
  return text;
}

// EXECUTE: one batch with in-job retries on ESCALATE / run-error (no multi-batch loop in one GHA job).
async function runExecuteBatch(batch) {
  for (let attempt = 0; ; attempt++) {
    await gate();
    let result;
    try {
      result = await runPhase(
        attempt ? `EXECUTE ${batch.join(",")} (retry ${attempt})` : `EXECUTE ${batch.join(",")}`,
        stepPrompt(batch),
      );
    } catch (e) {
      if (attempt >= MAX_ESCALATIONS) fail(`EXECUTE run error after ${attempt} retries: ${e.message}`);
      log(`run error (${e.message}); relaunching fresh (${attempt + 1}/${MAX_ESCALATIONS})`);
      continue;
    }
    if (result.startsWith("ESCALATE")) {
      if (attempt >= MAX_ESCALATIONS) fail(`EXECUTE escalated ${attempt + 1}x: ${result}`);
      log(`escalated (${result}); relaunching fresh (${attempt + 1}/${MAX_ESCALATIONS})`);
      continue;
    }
    log(`EXECUTE result: ${result}`);
    return result;
  }
}

// PLAN / FINALIZE: fixed prompt, relaunch fresh on ESCALATE / run-error / unexpected result up to
// MAX_ESCALATIONS. `expect` is the phase's success sentinel — a finished run whose one-liner does not
// match it (empty/garbled result from the beta API) is retried, never accepted as success.
// Optional `recoverIfDone()`: after an unexpected one-liner, check git side effects (ticket/PR) so a
// successful agent push is not discarded when the API omitted/lagged `result`.
async function runPhaseWithEscalation(label, makePrompt, expect, recoverIfDone) {
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
    if (expect && !expect.test(result)) {
      // Prefer a matching line if the API wraps the orchestrator one-liner in prose.
      const matchedLine = result.split(/\r?\n/).map((l) => l.trim()).find((l) => expect.test(l));
      if (matchedLine) {
        log(`${label} result (extracted): ${matchedLine}`);
        return matchedLine;
      }
      if (typeof recoverIfDone === "function") {
        try {
          if (recoverIfDone()) {
            const recovered = result || `(recovered via side effect; API result was ${JSON.stringify(result)})`;
            log(`${label}: unexpected one-liner ${JSON.stringify(result)} but side effect present — accepting as success`);
            return recovered;
          }
        } catch (e) {
          log(`${label}: side-effect recovery check failed (${e.message})`);
        }
      }
      if (attempt >= MAX_ESCALATIONS) fail(`${label} unexpected result after ${attempt} retries: "${result}"`);
      log(`unexpected result ("${result}"); relaunching fresh (${attempt + 1}/${MAX_ESCALATIONS})`);
      // Phase already recorded with outcome=success; next retry creates a new phase entry.
      continue;
    }
    log(`${label} result: ${result}`);
    return result;
  }
}

async function poll(agentId, runId) {
  const TERMINAL = new Set(["FINISHED", "ERROR", "CANCELLED", "EXPIRED"]);
  let fails = 0;
  let finishedWithoutResultSince = null;
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
    if (!TERMINAL.has(status)) {
      finishedWithoutResultSince = null;
      continue;
    }
    if (status !== "FINISHED") throw new Error(`run ${status}`);
    const text = String(run.result ?? run.run?.result ?? "").trim();
    if (text) return text;
    // Race: status can become FINISHED a poll or two before `result` is populated.
    if (finishedWithoutResultSince == null) {
      finishedWithoutResultSince = Date.now();
      log(`  run FINISHED but result empty — waiting up to ${RESULT_GRACE_MS}ms for result`);
      continue;
    }
    if (Date.now() - finishedWithoutResultSince < RESULT_GRACE_MS) continue;
    log(`  result still empty after ${RESULT_GRACE_MS}ms — returning empty`);
    return "";
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
function finalizePrompt(ticketPath) {
  return [
    "You are the Orchestrator for the FINALIZE phase. Follow the rule .cursor/rules/orchestrator-v2-finalize.mdc exactly.",
    `Ticket: ${ticketPath}`,
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
  return steps.sort((a, b) => a.n - b.n); // ascending step order — never trust the ticket's line order
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
async function recordInflightPhase(outcome, result) {
  if (!current) return;
  const snap = { ...current };
  current = null;
  try {
    await logUsage(snap.agentId);
    await metrics.recordPhase({
      label: snap.label,
      startedAt: snap.startedAt,
      agentId: snap.agentId,
      runId: snap.runId,
      agentUrl: snap.agentUrl,
      result,
      outcome,
    });
    log(`  metrics: recorded in-flight ${snap.label} (${outcome})`);
  } catch (e) {
    log(`  metrics: in-flight record failed (${e.message})`);
  }
}

async function gate() {
  for (;;) {
    const s = controlSignal();
    if (s === "stop") {
      log("⛔ conductor:stop — cancelling and exiting.");
      await cancelCurrent();
      await recordInflightPhase("cancelled", "Stopped via conductor:stop label");
      try { metrics.setSessionStatus("cancelled"); } catch { /* best-effort */ }
      process.exit(3);
    }
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
    log(`signal ${signal} — recording in-flight phase, cancelling agent, exiting.`);
    await cancelCurrent();
    await recordInflightPhase(
      "cancelled",
      "GitHub Actions job cancelled (hosted runner limit or manual cancel)",
    );
    try { metrics.setSessionStatus("cancelled"); } catch { /* best-effort */ }
    process.exit(130);
  });
}

async function logUsage(agentId) {
  try {
    const u = await api("GET", `/v1/agents/${agentId}/usage`);
    const t = u.totalUsage ?? {}; // v1 nests the aggregate under `totalUsage`; `runs[]` holds the per-run breakdown
    log(`  tokens: in=${t.inputTokens ?? "?"} out=${t.outputTokens ?? "?"} cacheR=${t.cacheReadTokens ?? "?"} cacheW=${t.cacheWriteTokens ?? "?"} total=${t.totalTokens ?? "?"}`);
  } catch { /* best-effort */ }
  try {
    const a = await api("GET", `/v1/agents/${agentId}/artifacts`);
    const n = Array.isArray(a.items) ? a.items.length : 0; // v1 returns artifacts under `items`
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

// True if a PR for this feature branch is open OR already merged — makes audit runs resume-safe (audits
// deliver a report + PR, not a ticket, so there is no ticket file to detect completed work). A closed-
// but-unmerged PR (a rejected audit) does NOT count, so the audit can legitimately be re-run.
// Dispatch a fresh workflow run with the same inputs so the next job resumes from ticket state.
function finishJobAndMaybeChain(reason) {
  if (!AUTO_CHAIN) {
    log(`⏭ auto_chain=false — stopping (${reason}). Re-dispatch manually to continue.`);
    return;
  }
  chainWorkflow(reason);
}

function chainWorkflow(reason) {
  log(`🔗 chaining next workflow run — ${reason}`);
  const args = ["workflow", "run", WORKFLOW_FILE, "--ref", WORKFLOW_REF];
  const field = (flag, value) => {
    if (value !== undefined && value !== "") args.push("-f", `${flag}=${value}`);
  };
  field("task_prompt", TASK_PROMPT);
  field("title", TITLE);
  field("issue", ISSUE);
  field("slug", SLUG);
  field("base", BASE);
  field("branch", BRANCH);
  field("batch_budget", String(BUDGET));
  field("model", MODEL);
  field("mode", MODE_INPUT);
  field("session_id", metrics.getSessionId());
  args.push("-f", `auto_chain=${AUTO_CHAIN ? "true" : "false"}`);
  try {
    execFileSync("gh", args, { stdio: ["ignore", "pipe", "pipe"], encoding: "utf8" });
    log("  next workflow run dispatched.");
  } catch (e) {
    const detail = e.stderr?.trim() || e.stdout?.trim() || e.message;
    fail(`failed to chain next workflow run: ${detail}`);
  }
}

function donePrExists() {
  try {
    return Number(sh(`gh pr list --head ${BRANCH} --base ${BASE} --state all --json state --jq '[.[] | select(.state=="OPEN" or .state=="MERGED")] | length'`)) > 0;
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
function parseBool(raw, defaultValue) {
  if (raw === undefined || raw === "") return defaultValue;
  const v = String(raw).trim().toLowerCase();
  if (v === "true" || v === "1" || v === "yes") return true;
  if (v === "false" || v === "0" || v === "no") return false;
  fail(`Invalid AUTO_CHAIN: ${raw} (use true/false)`);
}
function fail(msg) {
  log(`❌ ${msg}`);
  try { metrics.setSessionStatus("failed"); } catch { /* best-effort */ }
  process.exit(2);
}
