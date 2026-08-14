import { test } from 'node:test';
import assert from 'node:assert/strict';
import { groupConsecutivePhases, phaseGroupKey } from './phase-groups.mjs';

test('58 consecutive PLAN jobs collapse to one group', () => {
  const phases = Array.from({ length: 58 }, (_, i) => ({
    type: 'PLAN',
    batchSteps: null,
    github: { runId: String(31750000 + i) }
  }));
  const groups = groupConsecutivePhases(phases);
  assert.equal(groups.length, 1);
  assert.equal(groups[0].phases.length, 58);
  assert.equal(phaseGroupKey(groups[0].phases[0]), 'PLAN\0');
});

test('different EXECUTE batches stay separate rows', () => {
  const groups = groupConsecutivePhases([
    { type: 'PLAN', batchSteps: null },
    { type: 'PLAN', batchSteps: null },
    { type: 'EXECUTE', batchSteps: [1, 2] },
    { type: 'EXECUTE', batchSteps: [3, 4] },
    { type: 'FINALIZE', batchSteps: null }
  ]);
  assert.deepEqual(
    groups.map(g => [g.phases[0].type, g.phases.length]),
    [
      ['PLAN', 2],
      ['EXECUTE', 1],
      ['EXECUTE', 1],
      ['FINALIZE', 1]
    ]
  );
});

test('same EXECUTE batch retries collapse', () => {
  const groups = groupConsecutivePhases([
    { type: 'EXECUTE', batchSteps: [1, 2] },
    { type: 'EXECUTE', batchSteps: [1, 2] }
  ]);
  assert.equal(groups.length, 1);
  assert.equal(groups[0].phases.length, 2);
});

test('PLAN interrupted by EXECUTE does not merge across the gap', () => {
  const groups = groupConsecutivePhases([
    { type: 'PLAN' },
    { type: 'EXECUTE', batchSteps: [1] },
    { type: 'PLAN' }
  ]);
  assert.equal(groups.length, 3);
  assert.deepEqual(
    groups.map(g => g.phases.length),
    [1, 1, 1]
  );
});

test('empty and missing phases yield no groups', () => {
  assert.deepEqual(groupConsecutivePhases(), []);
  assert.deepEqual(groupConsecutivePhases(null), []);
  assert.deepEqual(groupConsecutivePhases([]), []);
});
