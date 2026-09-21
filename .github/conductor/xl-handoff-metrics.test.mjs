import { test } from 'node:test';
import assert from 'node:assert/strict';
import { agentBranchNames, agentMatchesBranch } from './metrics.mjs';
import {
  detectXlTicks,
  parseTicketRoadmapStepId,
  pickXlHandoffSession,
  pickXlHandoffAgent,
  resolveXlHandoffAgent
} from './xl-handoff-metrics.mjs';

const TICK_DIFF = `diff --git a/migration-docs/tickets/active/PROMPT_2_7_1_Foo_plan.md b/migration-docs/tickets/active/PROMPT_2_7_1_Foo_plan.md
index 111..222 100644
--- a/migration-docs/tickets/active/PROMPT_2_7_1_Foo_plan.md
+++ b/migration-docs/tickets/active/PROMPT_2_7_1_Foo_plan.md
@@ -10,7 +10,7 @@
 - [x] Step 6 (M) — work
-- [ ] Step 7 (XL) — Review (Bugbot + Security) + E2E
+- [x] Step 7 (XL) — Review (Bugbot + Security) + E2E
`;

test('detectXlTicks requires unchecked→checked on the same step', () => {
  assert.deepEqual(detectXlTicks(TICK_DIFF), [
    {
      file: 'migration-docs/tickets/active/PROMPT_2_7_1_Foo_plan.md',
      checklistStep: 7
    }
  ]);
});

test('detectXlTicks ignores a brand-new ticket that already has [x]', () => {
  const diff = `diff --git a/migration-docs/tickets/active/PROMPT_2_7_Foo_plan.md b/migration-docs/tickets/active/PROMPT_2_7_Foo_plan.md
new file mode 100644
--- /dev/null
+++ b/migration-docs/tickets/active/PROMPT_2_7_Foo_plan.md
@@ -0,0 +1,2 @@
+- **Current Step (ROADMAP):** 2.7
+- [x] Step 7 (XL) — Review (Bugbot + Security) + E2E
`;
  assert.deepEqual(detectXlTicks(diff), []);
});

test('detectXlTicks ignores ticks outside tickets/active', () => {
  const diff = TICK_DIFF.replaceAll('/tickets/active/', '/tickets/done/');
  assert.deepEqual(detectXlTicks(diff), []);
});

test('parseTicketRoadmapStepId prefers the ticket body', () => {
  assert.equal(
    parseTicketRoadmapStepId(
      '- **Current Step (ROADMAP):** 2.7.1 — Snapshot\n',
      'migration-docs/tickets/active/PROMPT_9_9_Wrong_plan.md'
    ),
    '2.7.1'
  );
});

test('parseTicketRoadmapStepId falls back to the PROMPT_ filename', () => {
  assert.equal(
    parseTicketRoadmapStepId('', 'migration-docs/tickets/active/PROMPT_2_7_1_Snapshot_plan.md'),
    '2.7.1'
  );
});

test('pickXlHandoffSession prefers the newest in_progress session on the branch', () => {
  const sessions = [
    {
      sessionId: 'old',
      status: 'in_progress',
      branch: 'feature/foo',
      startedAt: '2026-08-01T00:00:00.000Z'
    },
    {
      sessionId: 'done',
      status: 'completed',
      branch: 'feature/foo',
      startedAt: '2026-08-14T00:00:00.000Z'
    },
    {
      sessionId: 'keep',
      status: 'in_progress',
      branch: 'feature/foo',
      startedAt: '2026-08-10T00:00:00.000Z'
    },
    {
      sessionId: 'other-branch',
      status: 'in_progress',
      branch: 'feature/bar',
      startedAt: '2026-08-13T00:00:00.000Z'
    }
  ];
  assert.equal(pickXlHandoffSession(sessions, { branch: 'feature/foo' }).sessionId, 'keep');
});

test('pickXlHandoffSession falls back to any in_progress session', () => {
  const sessions = [
    {
      sessionId: 'only',
      status: 'in_progress',
      branch: 'feature/other',
      startedAt: '2026-08-10T00:00:00.000Z'
    }
  ];
  assert.equal(pickXlHandoffSession(sessions, { branch: 'feature/foo' }).sessionId, 'only');
  assert.equal(
    pickXlHandoffSession([{ sessionId: 'done', status: 'completed' }], { branch: 'feature/foo' }),
    null
  );
});

test('agentMatchesBranch reads repos.startingRef, the field the API returns', () => {
  const detail = {
    name: 'Atomic mysql restore',
    repos: [
      { url: 'https://github.com/Shadesman5/pagekit', startingRef: 'feature/atomic-mysql-restore' }
    ]
  };
  assert.deepEqual(agentBranchNames(detail), ['feature/atomic-mysql-restore']);
  assert.equal(agentMatchesBranch(detail, 'feature/atomic-mysql-restore'), true);
  assert.equal(agentMatchesBranch(detail, 'refs/heads/feature/atomic-mysql-restore'), true);
  assert.equal(agentMatchesBranch(detail, 'feature/other'), false);
});

test('agentMatchesBranch also accepts a pushed git.branches entry', () => {
  const detail = { git: { branches: [{ branch: 'feature/foo' }] } };
  assert.equal(agentMatchesBranch(detail, 'feature/foo'), true);
});

test('resolveXlHandoffAgent stops once the branch agent is found', async () => {
  const fetched = [];
  const client = async (_method, path) => {
    fetched.push(path);
    if (path.startsWith('/v1/agents?')) {
      return {
        items: [{ id: 'bc-other' }, { id: 'bc-xl' }, { id: 'bc-later' }],
        nextCursor: 'page-2'
      };
    }
    if (path === '/v1/agents/bc-other') {
      return { id: 'bc-other', name: 'Elsewhere', repos: [{ startingRef: 'feature/else' }] };
    }
    if (path === '/v1/agents/bc-xl') {
      return {
        id: 'bc-xl',
        name: 'Atomic mysql restore',
        repos: [{ startingRef: 'feature/atomic-mysql-restore' }],
        createdAt: '2026-09-21T18:44:41.755Z'
      };
    }
    if (path === '/v1/agents/bc-xl/usage') {
      return {
        totalUsage: {
          inputTokens: 10,
          outputTokens: 2,
          cacheReadTokens: 0,
          cacheWriteTokens: 0,
          totalTokens: 12
        }
      };
    }
    if (path.startsWith('/v1/agents/bc-xl/runs')) return { items: [] };
    throw new Error(`unexpected ${path}`);
  };

  const id = await resolveXlHandoffAgent(client, { branch: 'feature/atomic-mysql-restore' });
  assert.equal(id, 'bc-xl');
  assert.equal(
    fetched.some(p => p.includes('bc-later')),
    false
  );
  assert.equal(fetched.filter(p => p.startsWith('/v1/agents?')).length, 1);
});

test('pickXlHandoffAgent skips Conductor ids and zero usage, then takes the newest', () => {
  const id = pickXlHandoffAgent(
    [
      { id: 'bc-old', total: 50, createdAt: '2026-08-01T00:00:00.000Z' },
      { id: 'bc-child', total: 0, createdAt: '2026-08-14T12:00:00.000Z' },
      { id: 'bc-xl', total: 20, createdAt: '2026-08-14T10:00:00.000Z' },
      { id: 'bc-execute', total: 999, createdAt: '2026-08-13T00:00:00.000Z' }
    ],
    ['bc-execute', 'bc-old']
  );
  assert.equal(id, 'bc-xl');
});
