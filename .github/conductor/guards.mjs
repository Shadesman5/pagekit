// Pure Conductor V2 guards — ticket path, Plan-ready match, cross-job repeat cap.
// Kept side-effect free so node:test can pin the overnight PLAN-loop failure mode.

import { basename } from 'node:path';

/** Consecutive chained jobs of the same phase type before the driver refuses another launch. */
export const DEFAULT_MAX_PHASE_REPEATS = 3;

/**
 * Ticket path the driver (and Execute/Finalize) will look for.
 * Always the task-prompt basename, including any `PROMPT_` prefix. The workflow `slug` /
 * `branch` inputs only name the feature branch — they must never rename the ticket.
 */
export function ticketPathFromPrompt(taskPrompt) {
  const slug = basename(String(taskPrompt || ''))
    .replace(/\.md$/i, '')
    .trim();
  return `migration-docs/tickets/active/${slug}_plan.md`;
}

export function escapeRegExp(s) {
  return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Success sentinel for PLAN. Audits may report any path (the deliverable is a report + PR).
 * Normal tickets must name the driver path verbatim — `/^Plan ready:/i` alone accepted a
 * renamed file and auto_chain then re-ran PLAN forever (Step 2.7.1, 58 jobs).
 */
export function planExpectRegex(ticket, audit = false) {
  if (audit) return /^Plan ready:/i;
  return new RegExp(`^Plan ready:\\s*${escapeRegExp(ticket)}(?:\\s|$)`, 'i');
}

/** First `Plan ready:` line → path (strips an optional ` — PR #n` audit suffix). */
export function parsePlanReadyPath(result) {
  const line = String(result || '')
    .split(/\r?\n/)
    .map(l => l.trim())
    .find(l => /^Plan ready:/i.test(l));
  if (!line) return null;
  const rest = line.replace(/^Plan ready:\s*/i, '');
  const path = rest.split(/\s+[—–-]\s+PR\s+#/i)[0].trim();
  return path || null;
}

export function planReadyMatchesTicket(result, ticket) {
  const got = parsePlanReadyPath(result);
  if (!got || !ticket) return false;
  return got.replace(/\\/g, '/') === String(ticket).replace(/\\/g, '/');
}

/**
 * How many trailing chained GHA jobs ran `type`? In-job retries share a runId and count as one
 * job — MAX_ESCALATIONS already caps those. This cap is the cross-job loop breaker.
 */
export function consecutiveJobCount(phases, type) {
  const jobs = [];
  for (const p of phases || []) {
    const id = String(p.github?.runId || p.phaseKey || '');
    if (!id) continue;
    const last = jobs[jobs.length - 1];
    if (last && last.id === id) continue;
    jobs.push({ id, type: p.type });
  }
  let n = 0;
  for (let i = jobs.length - 1; i >= 0; i--) {
    if (jobs[i].type !== type) break;
    n += 1;
  }
  return n;
}

export function phaseRepeatExceeded(phases, type, max = DEFAULT_MAX_PHASE_REPEATS) {
  return consecutiveJobCount(phases, type) >= max;
}
