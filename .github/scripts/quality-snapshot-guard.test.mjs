import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { guardSnapshot, historyChanged, retainHistory } from './quality-snapshot-guard.mjs';

const OLD = 'a'.repeat(40);
const MID = 'b'.repeat(40);
const TIP = 'c'.repeat(40);

function linear(order) {
  return (older, newer) =>
    order.indexOf(older) !== -1 && order.indexOf(older) < order.indexOf(newer);
}

function point(sha, tests, coverage, extra = {}) {
  return {
    at: extra.at ?? `2026-01-01T00:00:0${tests % 10}.000Z`,
    sha,
    coverage: { linePercent: coverage, pinnedFloor: 13.3 },
    phpunit: { tests, failures: 0 },
    phpstan: { errors: 0, baselineBlocks: 342, suppressedErrors: 693 },
    infection: {
      msi: extra.msi ?? 98.92,
      coveredMsi: extra.msi ?? 98.92,
      killed: extra.killed ?? 364,
      escaped: 4,
      timedOut: 2,
      errors: 1
    },
    e2e: { specsPassed: 25, specsTotal: 25, scope: 'smoke' }
  };
}

function snap(commit, tests, coverage, extra = {}) {
  return {
    updatedAt: extra.at ?? '2026-09-28T12:00:00.000Z',
    commit,
    workflows: {
      phpTests: { runId: extra.phpRun ?? 1 },
      e2eTests: { runId: extra.e2eRun ?? 2 },
      infectionFull: { runId: extra.nightlyRun ?? 3, conclusion: 'success', scheduled: true }
    },
    phpunit: { '8.5-sqlite': { tests, failures: 0 } },
    coverage: { linePercent: coverage, pinnedFloor: 13.3 },
    phpstan: { errors: 0, baselineBlocks: 342, suppressedErrors: 693 },
    infection: {
      dailyFull: {
        msi: extra.msi ?? 98.92,
        coveredMsi: extra.msi ?? 98.92,
        killed: extra.killed ?? 364,
        escaped: 4
      }
    },
    e2e: { specsPassed: 25, specsTotal: 25, scope: 'smoke' }
  };
}

describe('retainHistory', () => {
  const isAncestor = linear([OLD, MID, TIP]);

  it('drops a null measurement and the unchanged point that only filled it back in', () => {
    const kept = retainHistory(
      [
        point(OLD, 1124, 8.68, { at: '2026-08-14T04:05:48.112Z' }),
        {
          ...point(MID, null, null, { at: '2026-08-17T17:31:58.456Z' }),
          phpunit: { tests: null, failures: null },
          coverage: { linePercent: null, pinnedFloor: 7.1 }
        },
        point(TIP, 1124, 8.68, { at: '2026-08-17T17:33:29.504Z' })
      ],
      isAncestor
    );
    assert.deepEqual(
      kept.map(p => p.sha),
      [OLD]
    );
  });

  it('drops a later point whose commit is an ancestor of the published tip', () => {
    const kept = retainHistory(
      [
        point(TIP, 2270, 14.88, { at: '2026-09-27T22:28:37.951Z' }),
        point(OLD, 1124, 8.68, { at: '2026-09-28T08:10:11.199Z' })
      ],
      isAncestor
    );
    assert.deepEqual(
      kept.map(p => p.at),
      ['2026-09-27T22:28:37.951Z']
    );
  });

  it('keeps a real increase and an infection-only change', () => {
    const kept = retainHistory(
      [
        point(OLD, 1124, 8.68, { at: '2026-08-14T04:05:48.112Z', killed: 365 }),
        point(TIP, 2270, 14.88, { at: '2026-09-27T22:28:37.951Z', killed: 364 })
      ],
      isAncestor
    );
    assert.equal(kept.length, 2);
  });
});

describe('historyChanged', () => {
  it('does not treat a missing number as a change', () => {
    const prev = point(TIP, 2270, 14.88);
    const next = point(TIP, 2270, 14.88);
    next.phpunit = { tests: null, failures: null };
    assert.equal(historyChanged(prev, next), false);
  });

  it('still sees a real drop, so the ancestry check has to reject it', () => {
    assert.equal(historyChanged(point(TIP, 2270, 14.88), point(OLD, 1124, 8.68)), true);
  });
});

describe('guardSnapshot', () => {
  const isAncestor = linear([OLD, TIP]);

  it('refuses to roll the tip back to an older commit with the same MSI', () => {
    const previous = snap(TIP, 2270, 14.88);
    const candidate = snap(OLD, 1124, 8.68, { phpRun: 32877092626, e2eRun: 32877092496 });
    assert.equal(guardSnapshot(previous, candidate, isAncestor), null);
  });

  it('refuses a candidate that did not report tests or coverage', () => {
    const previous = snap(TIP, 2270, 14.88);
    const candidate = snap(TIP, null, null);
    assert.equal(guardSnapshot(previous, candidate, isAncestor), null);
  });

  it('keeps the published tip when only the nightly MSI moved', () => {
    const previous = snap(TIP, 2270, 14.88, { killed: 365, nightlyRun: 66 });
    const candidate = snap(OLD, 1124, 8.68, {
      killed: 364,
      nightlyRun: 67,
      at: '2026-09-28T08:10:11.199Z'
    });
    const guarded = guardSnapshot(previous, candidate, isAncestor);
    assert.equal(guarded.commit, TIP);
    assert.equal(guarded.phpunit['8.5-sqlite'].tests, 2270);
    assert.equal(guarded.coverage.linePercent, 14.88);
    assert.equal(guarded.infection.dailyFull.killed, 364);
    assert.equal(guarded.workflows.infectionFull.runId, 67);
    assert.equal(guarded.updatedAt, '2026-09-28T08:10:11.199Z');
  });

  it('keeps the previous MSI when the new nightly did not report one', () => {
    const previous = snap(TIP, 2270, 14.88, { nightlyRun: 66 });
    const candidate = snap(TIP, 2281, 15.1, { at: '2026-09-28T23:00:00.000Z' });
    candidate.infection = { dailyFull: { msi: null } };
    const guarded = guardSnapshot(previous, candidate, isAncestor);
    assert.equal(guarded.commit, TIP);
    assert.equal(guarded.phpunit['8.5-sqlite'].tests, 2281);
    assert.equal(guarded.infection.dailyFull.msi, 98.92);
    assert.equal(guarded.workflows.infectionFull.runId, 66);
    assert.equal(guarded.updatedAt, '2026-09-28T23:00:00.000Z');
  });
});
