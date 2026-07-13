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
      meta.innerHTML += ' · <em>Demo data — Step 2.2 will enable live CI metrics</em>';
    }
    root.appendChild(meta);

    const table = el('table', 'quality-table');
    const thead = el('thead');
    const headRow = el('tr');
    ['Check', 'Result', 'Details'].forEach((h) => headRow.appendChild(el('th', null, h)));
    thead.appendChild(headRow);
    table.appendChild(thead);

    const tbody = el('tbody');

    const php82 = data.phpunit?.['8.2'];
    const php83 = data.phpunit?.['8.3'];
    const rows = [
      [
        'PHPUnit (8.2 × SQLite)',
        php82?.failures === 0 ? '✅' : '❌',
        php82 ? `${php82.tests} tests · ${php82.failures} failures` : '—'
      ],
      [
        'PHPUnit (8.3 × MySQL)',
        php83?.failures === 0 ? '✅' : '❌',
        php83 ? `${php83.tests} tests · ${php83.failures} failures` : '—'
      ],
      [
        'PHPStan',
        data.phpstan?.errors === 0 ? '✅' : '❌',
        data.phpstan
          ? `L${data.phpstan.level} · ${data.phpstan.errors} new errors · baseline ${data.phpstan.baselineBlocks}/${data.phpstan.suppressedErrors}`
          : '—'
      ],
      [
        'Line coverage (8.3)',
        data.coverage?.linePercent >= data.coverage?.pinnedFloor ? '✅' : '❌',
        data.coverage
          ? `${data.coverage.linePercent}% (floor ${data.coverage.pinnedFloor}%)`
          : '—'
      ],
      [
        'E2E (CI merge)',
        data.e2e?.specsPassed === data.e2e?.specsTotal ? '✅' : '❌',
        data.e2e
          ? `${data.e2e.specsPassed}/${data.e2e.specsTotal} specs · ${(data.e2e.viewports || []).join(', ')}`
          : '—'
      ],
      [
        'Infection (daily full)',
        'ℹ️',
        data.infection?.dailyFull
          ? `MSI ${formatPercent(data.infection.dailyFull.msi)} · covered ${formatPercent(data.infection.dailyFull.coveredMsi)} @ ${formatDate(data.infection.dailyFull.runAt)}`
          : '—'
      ],
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
