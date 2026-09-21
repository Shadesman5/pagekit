import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  parseDashboardUsageCsv,
  matchOrchestratorModel,
  buildSubagents,
  applyCsvUsageToPhase,
  applyCsvUsageToSession,
  prettyModelName
} from './usage-csv.mjs';

const CSV = `Date,Cloud Agent ID,Automation ID,Kind,Model,Max Mode,Input (w/ Cache Write),Input (w/o Cache Write),Cache Read,Output Tokens,Total Tokens,Cost
"2026-09-21T04:23:46.134Z","bc-parent-1","","Included","cursor-grok-4.6-high-fast","No","0","100","200","10","310","Included"
"2026-09-21T04:28:00.495Z","bc-parent-1","","Included","claude-opus-5-thinking-max","No","50","4","800","20","874","Included"
"2026-09-21T16:00:00.000Z","","","Included","cursor-grok-4.6-high-fast","No","0","9","0","1","10","Included"
`;

test('parseDashboardUsageCsv skips rows without a parent bc- id', () => {
  const events = parseDashboardUsageCsv(CSV);
  assert.equal(events.length, 2);
  assert.equal(events[0].agentId, 'bc-parent-1');
  assert.equal(events[0].tokens.total, 310);
  assert.equal(events[1].model, 'claude-opus-5-thinking-max');
  assert.equal(events[1].tokens.cacheWrite, 50);
});

test('matchOrchestratorModel prefers the group whose total equals /usage', () => {
  const groups = [
    { model: 'cursor-grok-4.6-high-fast', tokens: { total: 310 } },
    { model: 'claude-opus-5-thinking-max', tokens: { total: 874 } }
  ];
  assert.equal(matchOrchestratorModel(groups, { total: 310 }), 'cursor-grok-4.6-high-fast');
});

test('buildSubagents puts the orchestrator first and labels the rest', () => {
  const events = parseDashboardUsageCsv(CSV);
  const rows = buildSubagents(events, { total: 310 });
  assert.equal(rows[0].role, 'orchestrator');
  assert.equal(rows[0].label, 'Orchestrator');
  assert.equal(rows[1].role, 'subagent');
  assert.equal(rows[1].label, 'Claude Opus 5');
  assert.equal(prettyModelName('claude-fable-5-thinking-max'), 'Claude Fable 5');
});

test('applyCsvUsageToPhase replaces orchestrator-only totals and keeps the API snapshot', () => {
  const phase = {
    type: 'EXECUTE',
    tokens: { input: 100, output: 10, cacheRead: 200, cacheWrite: 0, total: 310 },
    tokensSource: 'cursor-api',
    notes: 'Timing from Cursor API (single agent run)'
  };
  const events = parseDashboardUsageCsv(CSV);
  assert.equal(applyCsvUsageToPhase(phase, events), true);
  assert.equal(phase.tokens.total, 1184);
  assert.equal(phase.orchestratorTokens.total, 310);
  assert.equal(phase.tokensSource, 'cursor-dashboard-csv');
  assert.equal(phase.subagents.length, 2);
  assert.match(phase.notes, /dashboard CSV/);
});

test('applyCsvUsageToSession updates only phases whose parent id is in the CSV', () => {
  const session = {
    phases: [
      { type: 'PLAN', agent: { id: 'bc-parent-1' }, tokens: { total: 310 } },
      { type: 'EXECUTE', agent: { id: 'bc-other' }, tokens: { total: 9 } }
    ]
  };
  const n = applyCsvUsageToSession(session, parseDashboardUsageCsv(CSV));
  assert.equal(n, 1);
  assert.equal(session.phases[0].tokens.total, 1184);
  assert.equal(session.phases[1].tokens.total, 9);
});

test('re-import matches orchestrator via orchestratorTokens, not the already-summed total', () => {
  const phase = {
    tokens: { total: 310 },
    tokensSource: 'cursor-api'
  };
  const events = parseDashboardUsageCsv(CSV);
  applyCsvUsageToPhase(phase, events);
  applyCsvUsageToPhase(phase, events);
  assert.equal(phase.subagents[0].role, 'orchestrator');
  assert.equal(phase.tokens.total, 1184);
});
