// Decisions for what a collect is allowed to publish. The workflow-run search can hand
// back an ancestor of the commit already on quality-data, or a run whose artifacts did
// not parse. Either one must not replace the published tip or stay in the chart.

const INFECTION_KEYS = ['msi', 'coveredMsi', 'killed', 'escaped'];

const WATCHED = [
  ['coverage', 'linePercent'],
  ['phpunit', 'tests'],
  ['phpunit', 'failures'],
  ['phpstan', 'errors'],
  ['phpstan', 'baselineBlocks'],
  ['phpstan', 'suppressedErrors'],
  ['e2e', 'specsPassed'],
  ['e2e', 'specsTotal']
];

export function coreIncomplete(snapshot) {
  return (
    snapshot?.phpunit?.['8.5-sqlite']?.tests == null || snapshot?.coverage?.linePercent == null
  );
}

function pointIncomplete(point) {
  return point?.phpunit?.tests == null || point?.coverage?.linePercent == null;
}

// A missing value is not a movement. Recording it would draw a hole and, on the next
// real collect, a second point that only fills that hole back in.
export function infectionMoved(previous, next) {
  return INFECTION_KEYS.some(key => {
    const value = next?.[key];
    return value != null && value !== previous?.[key];
  });
}

export function historyChanged(prev, next) {
  if (!prev) return true;
  if (WATCHED.some(([group, key]) => changed(prev[group]?.[key], next[group]?.[key]))) return true;
  return infectionMoved(prev.infection, next.infection);
}

function changed(previous, next) {
  return next != null && next !== previous;
}

function graftInfection(target, source) {
  if (source?.infection?.dailyFull?.msi == null) return target;
  return {
    ...target,
    workflows: {
      ...target.workflows,
      infectionFull: source.workflows?.infectionFull ?? target.workflows?.infectionFull
    },
    infection: source.infection
  };
}

function isOlderCommit(candidate, previous, isAncestor) {
  if (!previous?.commit || !candidate?.commit || candidate.commit === previous.commit) return false;
  return isAncestor(candidate.commit, previous.commit);
}

// `isAncestor(older, newer)` is true when `older` is a strict ancestor of `newer`.
// Returns the snapshot to write, or null to leave the published file untouched.
// A stale or unreadable gate pair can still carry a new Nightly MSI; that MSI is
// written onto the published tip, never onto the stale commit.
export function guardSnapshot(previous, candidate, isAncestor) {
  const staleOrIncomplete =
    coreIncomplete(candidate) || isOlderCommit(candidate, previous, isAncestor);
  if (!staleOrIncomplete) {
    if (candidate?.infection?.dailyFull?.msi != null) return candidate;
    return graftInfection(candidate, previous);
  }
  if (!previous || coreIncomplete(previous)) return null;
  if (!infectionMoved(previous.infection?.dailyFull, candidate?.infection?.dailyFull)) return null;
  return {
    ...graftInfection(previous, candidate),
    updatedAt: candidate.updatedAt
  };
}

// Drop holes already stored, a later point whose commit is older than the one
// before it, and a point that only repeats the previous real numbers.
export function retainHistory(points, isAncestor) {
  const kept = [];
  for (const point of points) {
    if (pointIncomplete(point)) continue;
    const prev = kept.at(-1) ?? null;
    if (prev?.sha && point?.sha && point.sha !== prev.sha && isAncestor(point.sha, prev.sha)) {
      continue;
    }
    if (prev && !historyChanged(prev, point)) continue;
    kept.push(point);
  }
  return kept;
}
