/**
 * Quality Dashboard — client-side loader for quality-snapshot.json and quality-history.json.
 * Both are deployed at the site root and fetched relative to the quality/ page: the snapshot renders
 * the develop tip with its PASS/FAIL verdicts, the history the trend behind it. History is optional —
 * it only exists once the collector has published a series — and the page falls back to the tip-only
 * table when it is missing.
 */
(function () {
  'use strict';

  const SNAPSHOT_CANDIDATES = [
    '../quality-snapshot.json',
    '/pagekit/quality-snapshot.json',
    '/quality-snapshot.json',
    '../../data/quality-snapshot.demo.json'
  ];

  const HISTORY_CANDIDATES = [
    '../quality-history.json',
    '/pagekit/quality-history.json',
    '/quality-history.json'
  ];

  // Mirrors minMsi / minCoveredMsi in infection.json.dist — the threshold the nightly run enforces.
  const MSI_THRESHOLD = 80;

  const CHART_JS_URL = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';

  const SERIES = [
    { key: 'coverage', label: 'Line coverage %', pick: p => p.coverage?.linePercent },
    { key: 'msi', label: 'Infection MSI % (full)', pick: p => p.infection?.msi },
    { key: 'tests', label: 'PHPUnit tests', pick: p => p.phpunit?.tests },
    { key: 'debt', label: 'PHPStan suppressed errors', pick: p => p.phpstan?.suppressedErrors }
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

  const DB_LABELS = {
    sqlite: 'SQLite',
    mysql: 'MySQL',
    pgsql: 'PostgreSQL',
    postgres: 'PostgreSQL'
  };

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
      return [
        label,
        leg.failures === 0 ? '✅' : '❌',
        `${leg.tests} tests · ${leg.failures} failures`
      ];
    });
  }

  // The nightly full-suite run is the only source of this number, and it lands independently of the
  // merge that produced the rest of the snapshot. Until it has run, the object exists with null
  // fields — say so, rather than formatting the nulls into "MSI — · covered — @ —". Once real numbers
  // exist the row carries a verdict like every other, measured against the threshold Infection itself
  // enforces.
  function infectionRow(full) {
    const label = 'Infection (daily full)';
    if (!full || full.msi == null) return [label, 'ℹ️', 'awaiting nightly'];

    const detail = [`MSI ${formatPercent(full.msi)}`, `covered ${formatPercent(full.coveredMsi)}`];
    if (full.killed != null) detail.push(`${full.killed} killed`);
    if (full.escaped != null) detail.push(`${full.escaped} escaped`);

    const meetsThreshold =
      full.msi >= MSI_THRESHOLD && (full.coveredMsi ?? full.msi) >= MSI_THRESHOLD;
    return [
      label,
      meetsThreshold ? '✅' : '❌',
      `${detail.join(' · ')} @ ${formatDate(full.runAt)}`
    ];
  }

  async function fetchJson(candidates) {
    for (const url of candidates) {
      try {
        const res = await fetch(url, { cache: 'no-store' });
        if (res.ok) return await res.json();
      } catch {
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
    ['Check', 'Result', 'Details'].forEach(h => headRow.appendChild(el('th', null, h)));
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
      [
        'Frontend (lint/build)',
        gateIcon(data.gates?.frontendLint),
        data.gates?.frontendLint || '—'
      ],
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

  // Loaded from a CDN on demand rather than vendored: the docs site has no JS build step, and the
  // charts are the only thing on the page that needs a library.
  function loadChartJs() {
    if (window.Chart) return Promise.resolve(window.Chart);
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = CHART_JS_URL;
      script.onload = () => resolve(window.Chart);
      script.onerror = () => reject(new Error('Chart.js failed to load'));
      document.head.appendChild(script);
    });
  }

  function seriesPoints(points, pick) {
    return points.map(p => ({ x: p.at, y: pick(p) })).filter(p => p.y != null);
  }

  function renderChart(Chart, container, series, points) {
    const data = seriesPoints(points, series.pick);
    // A metric the collector has never captured (an MSI series before the first nightly) has nothing
    // to draw, and a single point is a dot rather than a trend.
    if (data.length < 2) return;

    const card = el('div', 'quality-chart');
    card.appendChild(el('h4', null, series.label));
    const canvas = el('canvas');
    card.appendChild(canvas);
    container.appendChild(card);

    new Chart(canvas, {
      type: 'line',
      data: {
        labels: data.map(p => new Date(p.x).toISOString().slice(0, 10)),
        datasets: [
          {
            label: series.label,
            data: data.map(p => p.y),
            borderColor: '#3f51b5',
            backgroundColor: 'rgba(63, 81, 181, 0.12)',
            borderWidth: 2,
            pointRadius: 2,
            fill: true,
            tension: 0.25
          }
        ]
      },
      options: {
        responsive: true,
        // Height is derived from the container width. Turning this off would need a styled container
        // with an explicit height, and the charts would collapse without it.
        aspectRatio: 3,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: false } }
      }
    });
  }

  async function renderHistory(points) {
    const root = document.getElementById('quality-dashboard-app');
    if (!root || !Array.isArray(points) || points.length < 2) return;

    const section = el('section', 'quality-history');
    section.appendChild(el('h3', null, 'Trend'));
    const grid = el('div', 'quality-charts');
    section.appendChild(grid);
    section.appendChild(
      el(
        'p',
        'quality-foot',
        `${points.length} collected data points · appended only when a metric changes`
      )
    );
    root.appendChild(section);

    let Chart;
    try {
      Chart = await loadChartJs();
    } catch (e) {
      grid.appendChild(el('p', 'quality-error', e.message));
      return;
    }
    SERIES.forEach(series => renderChart(Chart, grid, series, points));
  }

  function renderError(message) {
    const root = document.getElementById('quality-dashboard-app');
    if (!root) return;
    root.innerHTML = `<p class="quality-error">${message}</p>`;
  }

  async function init() {
    if (!document.getElementById('quality-dashboard-app')) return;

    const [data, history] = await Promise.all([
      fetchJson(SNAPSHOT_CANDIDATES),
      fetchJson(HISTORY_CANDIDATES)
    ]);
    if (!data) {
      renderError(
        'Could not load quality-snapshot.json. Check deploy workflow or local preview setup.'
      );
      return;
    }
    renderTable(data);
    await renderHistory(history?.points);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
