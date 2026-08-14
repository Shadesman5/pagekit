import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  ticketPathFromPrompt,
  planExpectRegex,
  parsePlanReadyPath,
  planReadyMatchesTicket,
  consecutiveJobCount,
  phaseRepeatExceeded,
  xlHandoffStep,
  cloudExecuteSteps,
  xlHandoffMessage
} from './guards.mjs';

const PROMPT =
  'migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_1_Snapshot-Three-Stage-Uninstall.md';
const TICKET = 'migration-docs/tickets/active/PROMPT_2_7_1_Snapshot-Three-Stage-Uninstall_plan.md';

test('ticketPathFromPrompt keeps the PROMPT_ prefix and ignores directories', () => {
  assert.equal(ticketPathFromPrompt(PROMPT), TICKET);
});

test('ticketPathFromPrompt never uses a branch slug or step id', () => {
  assert.notEqual(
    ticketPathFromPrompt(PROMPT),
    'migration-docs/tickets/active/2_7_1_Snapshot-Three-Stage-Uninstall_plan.md'
  );
  assert.notEqual(
    ticketPathFromPrompt(PROMPT),
    'migration-docs/tickets/active/snapshot-three-stage-uninstall_plan.md'
  );
});

test('planExpectRegex accepts the driver path and rejects a stripped slug', () => {
  const re = planExpectRegex(TICKET, false);
  assert.equal(re.test(`Plan ready: ${TICKET}`), true);
  assert.equal(
    re.test(
      'Plan ready: migration-docs/tickets/active/2_7_1_Snapshot-Three-Stage-Uninstall_plan.md'
    ),
    false
  );
  assert.equal(
    re.test('Plan ready: migration-docs/tickets/active/snapshot-three-stage-uninstall_plan.md'),
    false
  );
  assert.equal(re.test('Plan ready:'), false);
});

test('planExpectRegex stays loose for audits', () => {
  const re = planExpectRegex(TICKET, true);
  assert.equal(re.test('Plan ready: migration-docs/audits/2026/08/REPORT.md — PR #214'), true);
});

test('parsePlanReadyPath strips an audit PR suffix', () => {
  assert.equal(parsePlanReadyPath(`Plan ready: ${TICKET}`), TICKET);
  assert.equal(
    parsePlanReadyPath('Plan ready: migration-docs/audits/x.md — PR #214'),
    'migration-docs/audits/x.md'
  );
  assert.equal(parsePlanReadyPath('ESCALATE: nope'), null);
});

test('planReadyMatchesTicket is path-strict', () => {
  assert.equal(planReadyMatchesTicket(`Plan ready: ${TICKET}`, TICKET), true);
  assert.equal(
    planReadyMatchesTicket(
      'Plan ready: migration-docs/tickets/active/2_7_1_Snapshot-Three-Stage-Uninstall_plan.md',
      TICKET
    ),
    false
  );
});

test('consecutiveJobCount treats in-job retries as one job', () => {
  const phases = [
    { type: 'PLAN', github: { runId: '111' }, phaseKey: '111-1-PLAN' },
    { type: 'PLAN', github: { runId: '111' }, phaseKey: '111-1-PLAN (retry 1)' }
  ];
  assert.equal(consecutiveJobCount(phases, 'PLAN'), 1);
  assert.equal(phaseRepeatExceeded(phases, 'PLAN', 3), false);
});

test('consecutiveJobCount resets after a different phase', () => {
  const phases = [
    { type: 'PLAN', github: { runId: '1' }, phaseKey: '1' },
    { type: 'EXECUTE', github: { runId: '2' }, phaseKey: '2' },
    { type: 'PLAN', github: { runId: '3' }, phaseKey: '3' }
  ];
  assert.equal(consecutiveJobCount(phases, 'PLAN'), 1);
});

test('the 2.7.1 overnight PLAN chain trips the cap at 3 jobs', () => {
  const phases = [];
  for (let i = 1; i <= 58; i++) {
    phases.push({
      type: 'PLAN',
      github: { runId: String(31750000 + i) },
      phaseKey: `${31750000 + i}-1-PLAN`
    });
  }
  assert.equal(consecutiveJobCount(phases, 'PLAN'), 58);
  assert.equal(phaseRepeatExceeded(phases.slice(0, 2), 'PLAN', 3), false);
  assert.equal(phaseRepeatExceeded(phases.slice(0, 3), 'PLAN', 3), true);
  assert.equal(phaseRepeatExceeded(phases, 'PLAN', 3), true);
});

test('xlHandoffStep fires only when the next open step is XL', () => {
  assert.equal(xlHandoffStep([{ n: 5, size: 'XL' }])?.n, 5);
  assert.equal(
    xlHandoffStep([
      { n: 3, size: 'M' },
      { n: 5, size: 'XL' }
    ]),
    null
  );
  assert.equal(xlHandoffStep([{ n: 5, size: 'XL' }], false), null);
  assert.equal(xlHandoffStep([]), null);
  assert.equal(xlHandoffStep(null), null);
});

test('cloudExecuteSteps drops XL while handoff is on', () => {
  const open = [
    { n: 1, size: 'S' },
    { n: 2, size: 'M' },
    { n: 3, size: 'XL' }
  ];
  assert.deepEqual(
    cloudExecuteSteps(open, true).map(s => s.n),
    [1, 2]
  );
  assert.deepEqual(
    cloudExecuteSteps(open, false).map(s => s.n),
    [1, 2, 3]
  );
  assert.deepEqual(cloudExecuteSteps([{ n: 3, size: 'XL' }], true), []);
});

test('xlHandoffMessage names the step and the V1 resume', () => {
  const msg = xlHandoffMessage({ n: 7, size: 'XL' });
  assert.match(msg, /Step 7/);
  assert.match(msg, /cursor\.com\/agents/);
  assert.match(msg, /session_id/);
  assert.match(msg, /FINALIZE/);
});
