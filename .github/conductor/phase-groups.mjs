/**
 * Consecutive same-type (+ same EXECUTE batch) phases collapse to one dashboard row.
 * Browser copy lives in docs-site/content/javascripts/conductor-dashboard.js
 * (classic <script>, no module import).
 */

export function phaseGroupKey(p) {
  const type = String(p?.type || '');
  const batch = Array.isArray(p?.batchSteps) ? p.batchSteps.join('\u001f') : '';
  return `${type}\0${batch}`;
}

export function groupConsecutivePhases(phases) {
  const groups = [];
  for (const p of phases || []) {
    const key = phaseGroupKey(p);
    const last = groups[groups.length - 1];
    if (last && last.key === key) last.phases.push(p);
    else groups.push({ key, phases: [p] });
  }
  return groups;
}
