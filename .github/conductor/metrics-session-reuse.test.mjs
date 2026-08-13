import { test } from 'node:test';
import assert from 'node:assert/strict';
import { findReusableSessionByAgent, mergeImportedPhases, sessionAgentIds } from './metrics.mjs';

test('sessionAgentIds is case-insensitive and unique', () => {
  const ids = sessionAgentIds({
    phases: [{ agent: { id: 'bc-AAA' } }, { agent: { id: 'BC-aaa' } }, { agent: { id: '' } }, {}]
  });
  assert.deepEqual(ids, ['bc-aaa']);
});

test('findReusableSessionByAgent matches the parent agent on the step', () => {
  const sessions = [
    { sessionId: 'other', phases: [{ agent: { id: 'bc-other' } }] },
    { sessionId: 'keep', phases: [{ agent: { id: 'bc-9590820d-aecf-42eb-98d5-ada024446917' } }] }
  ];
  const found = findReusableSessionByAgent(sessions, ['BC-9590820d-aecf-42eb-98d5-ada024446917']);
  assert.equal(found.sessionId, 'keep');
  assert.equal(findReusableSessionByAgent(sessions, ['bc-missing']), null);
});

test('mergeImportedPhases replaces the same agent instead of appending', () => {
  const session = {
    phases: [
      {
        phaseKey: 'manual-bc-1-V1',
        agent: { id: 'bc-1', runCount: 2 },
        tokens: { total: 10 }
      }
    ]
  };
  mergeImportedPhases(session, [
    {
      phaseKey: 'manual-bc-1-V1',
      agent: { id: 'bc-1', runCount: 3, runs: [{ runId: 'a' }, { runId: 'b' }, { runId: 'c' }] },
      tokens: { total: 20 }
    }
  ]);
  assert.equal(session.phases.length, 1);
  assert.equal(session.phases[0].agent.runCount, 3);
  assert.equal(session.phases[0].tokens.total, 20);
});

test('mergeImportedPhases still appends a different parent agent', () => {
  const session = {
    phases: [{ phaseKey: 'manual-bc-1-V1', agent: { id: 'bc-1' } }]
  };
  mergeImportedPhases(session, [{ phaseKey: 'manual-bc-2-V1', agent: { id: 'bc-2' } }]);
  assert.equal(session.phases.length, 2);
});
