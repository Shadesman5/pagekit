/**
 * Quality Dashboard — client-side loader for quality-snapshot.json
 * Deployed at site root; fetched relative to the quality/ page.
 */
(function () {
  'use strict';

  const SNAPSHOT_CANDIDATES = [
    '../quality-snapshot.json',
    '/pagekit/quality-snapshot.json',
    '/quality-snapshot.json',
    '../../data/quality-snapshot.demo.json'
  ];

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function gateIcon(value) {
    if (value === 'pass' || value === 'success') return '✅';
    if (value === 'fail' || value === 'failure') return '❌';
    if (value === 'non-blocking' || value === 'n/a') return '⚪';
    return 'ℹ️';
  }

  function formatPercent(n) {
    return n == null ? '—' : `${n}%`;
  }

  function formatDate(iso) {
    if (!iso) return '—';
    try {
      return new Date(iso).toUTCString();
    } catch {
      return iso;
    }
  }

  const DB_LABELS = { sqlite: 'SQLite', mysql: 'MySQL', pgsql: 'PostgreSQL', postgres: 'PostgreSQL' };

  function dbLabel(db) {
    if (!db) return '—';
    return DB_LABELS[db] || db.toUpperCase();
  }

  // One row per PHPUnit leg present in the snapshot (keys are "<php>-<db>", e.g. "8.5-sqlite").
  // Required legs show their test/failure counts; non-required legs (the non-blocking MySQL leg
  // uploads no counts) surface the job conclusion as a non-blocking (⚪) informational row.
  function phpunitRows(phpunit) {
    return Object.entries(phpunit || {}).map(([key, leg]) => {
      const version = key.split('-')[0];
      const label = `PHPUnit (${version} × ${dbLabel(leg.db || key.slice(version.length + 1))})`;
      if (leg.required === false) {
        return [label, '⚪', leg.conclusion ? `${leg.conclusion} · non-blocking` : 'non-blocking'];
      }
      if (leg.tests == null) return [label, 'ℹ️', '—'];
      return [label, leg.failures === 0 ? '✅' : '❌', `${leg.tests} tests · ${leg.failures} failures`];
    });
  }

  // The nightly full-suite run is the only source of this number, and it lands independently of the
  // merge that produced the rest of the snapshot. Until it has run, the object exists with null
  // fields — say so, rather than formatting the nulls into "MSI — · covered — @ —".
  function infectionRow(full) {
    const label = 'Infection (daily full)';
    if (!full || full.msi == null) return [label, 'ℹ️', 'awaiting nightly'];
    return [
      label,
      'ℹ️',
      `MSI ${formatPercent(full.msi)} · covered ${formatPercent(full.coveredMsi)} @ ${formatDate(full.runAt)}`
    ];
  }

  async function fetchSnapshot() {
    for (const url of SNAPSHOT_CANDIDATES) {
      try {
        const res = await fetch(url);
        if (res.ok) return await res.json();
      } catch (_) {
        /* try next candidate */
      }
    }
    return null;
  }

  function renderTable(data) {
    const root = document.getElementById('quality-dashboard-app');
    if (!root) return;

    root.innerHTML = '';

    const meta = el('p', 'quality-meta');
    meta.innerHTML =
      `<strong>Source:</strong> ${data.source || 'unknown'} · ` +
      `<strong>Branch:</strong> ${data.branch || '—'} · ` +
      `<strong>Updated:</strong> ${formatDate(data.updatedAt)}`;
    if (data.source === 'demo') {
      meta.innerHTML += ' · <em>Demo data — awaiting the first live collection run</em>';
    }
    root.appendChild(meta);

    const table = el('table', 'quality-table');
    const thead = el('thead');
    const headRow = el('tr');
    ['Check', 'Result', 'Details'].forEach((h) => headRow.appendChild(el('th', null, h)));
    thead.appendChild(headRow);
    table.appendChild(thead);

    const tbody = el('tbody');

    const cov = data.coverage;
    const e2e = data.e2e;
    const rows = [
      ...phpunitRows(data.phpunit),
      [
        'PHPStan',
        data.phpstan?.errors === 0 ? '✅' : '❌',
        data.phpstan
          ? `L${data.phpstan.level} · ${data.phpstan.errors} new errors · baseline ${data.phpstan.baselineBlocks}/${data.phpstan.suppressedErrors}`
          : '—'
      ],
      [
        'Line coverage',
        cov?.linePercent == null ? 'ℹ️' : cov.linePercent >= cov.pinnedFloor ? '✅' : '❌',
        cov ? `${formatPercent(cov.linePercent)} (floor ${formatPercent(cov.pinnedFloor)})` : '—'
      ],
      [
        e2e?.scope ? `E2E (${e2e.scope})` : 'E2E',
        e2e && e2e.specsTotal != null ? (e2e.specsPassed === e2e.specsTotal ? '✅' : '❌') : 'ℹ️',
        e2e
          ? `${e2e.specsPassed ?? '—'}/${e2e.specsTotal ?? '—'} specs · ${(e2e.viewports || []).join(', ')}`
          : '—'
      ],
      infectionRow(data.infection?.dailyFull),
      ['CS-Fixer', gateIcon(data.gates?.csFixer), data.gates?.csFixer || '—'],
      ['Security audit', gateIcon(data.gates?.securityAudit), data.gates?.securityAudit || '—'],
      ['Frontend (lint/build)', gateIcon(data.gates?.frontendLint), data.gates?.frontendLint || '—'],
      ['Codecov', gateIcon(data.gates?.codecov), data.gates?.codecov || '—']
    ];

    rows.forEach(([check, result, details]) => {
      const tr = el('tr');
      tr.appendChild(el('td', null, check));
      tr.appendChild(el('td', 'quality-result', result));
      tr.appendChild(el('td', null, details));
      tbody.appendChild(tr);
    });

    table.appendChild(tbody);
    root.appendChild(table);

    const foot = el('p', 'quality-foot');
    foot.textContent = `schemaVersion: ${data.schemaVersion ?? 1}`;
    root.appendChild(foot);
  }

  function renderError(message) {
    const root = document.getElementById('quality-dashboard-app');
    if (!root) return;
    root.innerHTML = `<p class="quality-error">${message}</p>`;
  }

  async function init() {
    if (!document.getElementById('quality-dashboard-app')) return;

    const data = await fetchSnapshot();
    if (!data) {
      renderError('Could not load quality-snapshot.json. Check deploy workflow or local preview setup.');
      return;
    }
    renderTable(data);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
