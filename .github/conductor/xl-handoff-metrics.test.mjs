import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  detectXlTicks,
  parseTicketRoadmapStepId,
  pickXlHandoffSession,
  pickXlHandoffAgent,
  listFieldsMatchBranch,
  resolveXlHandoffAgent,
  lastSessionPhaseAt,
  hyphenate,
  branchSlug
} from './xl-handoff-metrics.mjs';

/** Answers the two calls the resolver makes: one page of agents, then usage per id. */
function fakeClient(items, usage = {}) {
  return async (method, path) => {
    if (path.startsWith('/v1/agents?')) return { items, nextCursor: null };
    const hit = path.match(/^\/v1\/agents\/(.+)\/usage$/);
    if (hit) return { totalUsage: { totalTokens: usage[hit[1]] || 0 } };
    return {};
  };
}

const BRANCH = 'feature/snapshot-three-stage-uninstall';
const LAST_PHASE = '2026-08-14T21:44:57.731Z';

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

test('listFieldsMatchBranch matches a V1 UI name without feature/ prefix', () => {
  assert.equal(
    listFieldsMatchBranch(
      { name: 'Snapshot three-stage uninstall' },
      'feature/snapshot-three-stage-uninstall'
    ),
    'yes'
  );
  assert.equal(hyphenate('Snapshot three-stage uninstall'), 'snapshot-three-stage-uninstall');
  assert.equal(
    branchSlug('feature/snapshot-three-stage-uninstall'),
    'snapshot-three-stage-uninstall'
  );
  assert.equal(
    listFieldsMatchBranch(
      { name: 'Snapshot uninstall orchestrator finalize' },
      'feature/snapshot-three-stage-uninstall'
    ),
    'unknown'
  );
});

test('listFieldsMatchBranch skips other branches without a detail fetch', () => {
  assert.equal(
    listFieldsMatchBranch(
      { target: { branchName: 'feature/other' }, name: 'EXECUTE' },
      'feature/snapshot-three-stage-uninstall'
    ),
    'no'
  );
  assert.equal(
    listFieldsMatchBranch(
      { target: { branchName: 'feature/snapshot-three-stage-uninstall' } },
      'feature/snapshot-three-stage-uninstall'
    ),
    'yes'
  );
  assert.equal(listFieldsMatchBranch({ id: 'bc-1' }, 'feature/foo'), 'unknown');
});

test('pickXlHandoffAgent prefers a name hit over a newer unrelated agent', () => {
  const id = pickXlHandoffAgent([
    {
      id: 'bc-finalize',
      total: 10,
      createdAt: '2026-08-14T23:27:00.000Z',
      nameHit: false
    },
    {
      id: 'bc-cd11ecab-5d76-46f6-86e9-8fea6a3f76ea',
      total: 55,
      createdAt: '2026-08-14T21:53:00.000Z',
      nameHit: true
    }
  ]);
  assert.equal(id, 'bc-cd11ecab-5d76-46f6-86e9-8fea6a3f76ea');
});

test('listFieldsMatchBranch lets a named branch overrule a title that reads like the ticket', () => {
  assert.equal(
    listFieldsMatchBranch(
      { target: { branchName: 'feature/other' }, name: 'Snapshot three-stage uninstall' },
      BRANCH
    ),
    'no'
  );
});

test('listFieldsMatchBranch does not read a one-word slug out of a longer title', () => {
  assert.equal(
    listFieldsMatchBranch({ name: 'Docker prod image hardening' }, 'feature/docker'),
    'unknown'
  );
  assert.equal(
    listFieldsMatchBranch({ name: 'Snapshot three-stage uninstall — finalize' }, BRANCH),
    'yes'
  );
});

test('resolveXlHandoffAgent will not take an agent that does not say when it ran', async () => {
  const client = fakeClient([{ id: 'bc-undated', name: 'Snapshot three-stage uninstall' }], {
    'bc-undated': 500
  });
  assert.equal(await resolveXlHandoffAgent(client, { branch: BRANCH, after: LAST_PHASE }), null);
  assert.equal(await resolveXlHandoffAgent(client, { branch: BRANCH }), 'bc-undated');
});

test('resolveXlHandoffAgent imports nothing when two branchless agents could be the parent', async () => {
  const client = fakeClient(
    [
      { id: 'bc-one', name: 'Post-phase cleanup', createdAt: '2026-08-14T22:00:00.000Z' },
      { id: 'bc-two', name: 'Unrelated agent', createdAt: '2026-08-14T23:00:00.000Z' }
    ],
    { 'bc-one': 10, 'bc-two': 20 }
  );
  assert.equal(await resolveXlHandoffAgent(client, { branch: BRANCH, after: LAST_PHASE }), null);
});

test('resolveXlHandoffAgent still takes the one branchless agent after the cutoff', async () => {
  const client = fakeClient(
    [
      { id: 'bc-only', name: 'Unrelated title', createdAt: '2026-08-14T22:00:00.000Z' },
      {
        id: 'bc-other-branch',
        target: { branchName: 'feature/other' },
        createdAt: '2026-08-14T23:00:00.000Z'
      }
    ],
    { 'bc-only': 42, 'bc-other-branch': 999 }
  );
  assert.equal(
    await resolveXlHandoffAgent(client, { branch: BRANCH, after: LAST_PHASE }),
    'bc-only'
  );
});

test('lastSessionPhaseAt is the latest completed Conductor phase', () => {
  assert.equal(
    lastSessionPhaseAt({
      phases: [
        { completedAt: '2026-08-14T13:57:38.423Z' },
        { completedAt: '2026-08-14T21:44:57.731Z' }
      ]
    }),
    Date.parse('2026-08-14T21:44:57.731Z')
  );
});
