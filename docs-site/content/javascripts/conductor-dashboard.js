/**
 * Conductor Metrics Dashboard — accordion by ROADMAP step ID.
 * Loads index.json + session files from conductor-metrics/ (deployed site root).
 */
(function () {
  'use strict';

  const ROOT_ID = 'conductor-metrics-app';
  const NUMBER_LOCALE = 'de-DE';

  let chartJsPromise = null;
  /** @type {import('chart.js').Chart[]} */
  let activeCharts = [];

  /** Phase colors: .cursor/rules/github-labels.mdc (phase-1 … phase-5). Titles: strategy.md */
  const PHASE_DEFINITIONS = {
    1: {
      title: 'Foundation',
      subtitle: 'Symfony 6.4, PHP 8.2+, DBAL 3.x, ORM attributes',
      color: '#0E8A16'
    },
    2: {
      title: 'Code Quality',
      subtitle: 'PHPStan, CI/CD, Infection, E2E',
      color: '#0052CC'
    },
    3: {
      title: 'Frontend & cross-stack alignment',
      subtitle: 'Vue 3 migration, translation/Intl ICU, service-layer DI',
      color: '#8250DF'
    },
    4: {
      title: 'Public API',
      subtitle: 'Versioned REST API with JWT',
      color: '#D93F0B'
    },
    5: {
      title: 'Future vision',
      subtitle: 'Long-term platform evolution & extension ecosystem',
      color: '#BFDADC'
    }
  };

  const METRIC_SUB_FILTERS = {
    all: { id: 'all', label: 'All runs', runLabel: 'Total runs' },
    pre: { id: 'pre', label: 'Pre-Conductor', runLabel: 'Agent runs' },
    conductor: { id: 'conductor', label: 'Conductor', runLabel: 'Conductor runs' }
  };

  function sitePrefix() {
    return /^\/pagekit(?:\/|$)/.test(window.location.pathname) ? '/pagekit' : '';
  }

  function metricsUrlCandidates(relativePath) {
    const seen = new Set();
    const urls = [];
    const add = url => {
      if (!url || seen.has(url)) return;
      seen.add(url);
      urls.push(url);
    };

    const prefix = sitePrefix();
    add(`${prefix}/conductor-metrics/${relativePath}`);
    add(`/conductor-metrics/${relativePath}`);

    const depth = window.location.pathname.replace(/\/$/, '').split('/').filter(Boolean).length;
    for (let i = 1; i <= depth; i += 1) {
      add(`${'../'.repeat(i)}conductor-metrics/${relativePath}`);
    }

    return urls;
  }

  const CONDUCTOR_CUTOFF = '2.1.7';

  function destroyCharts() {
    activeCharts.forEach(c => {
      try {
        c.destroy();
      } catch {
        /* ignore */
      }
    });
    activeCharts = [];
  }

  function trackChart(chart) {
    if (chart) activeCharts.push(chart);
    return chart;
  }

  function phaseOf(stepId) {
    const n = parseInt(String(stepId).split('.')[0], 10);
    return Number.isNaN(n) ? 0 : n;
  }

  function phaseDef(phase) {
    return PHASE_DEFINITIONS[phase] || { title: `Phase ${phase}`, subtitle: '', color: '#888' };
  }

  function applyPhaseColor(el, phase) {
    const def = phaseDef(phase);
    el.dataset.phase = String(phase);
    if (def.color) el.style.setProperty('--cm-phase-color', def.color);
  }

  function isSubStep(row) {
    return String(row?.name || '')
      .trim()
      .startsWith('↳');
  }

  function isMilestone(row) {
    if (!row?.id || isSubStep(row)) return false;
    return String(row.id).split('.').length === 2 && phaseOf(row.id) >= 2;
  }

  function isStepDone(row) {
    return String(row?.status || '').includes('✅');
  }

  function hasStepMetrics(stepId, index) {
    return (index?.steps?.[stepId]?.sessionIds?.length || 0) > 0;
  }

  function effectiveMetricFilter(ctx) {
    return ctx.phaseFilter === 'all' ? ctx.metricFilter : 'all';
  }

  function runsBadgeClass(stepId, ctx) {
    const filterId = effectiveMetricFilter(ctx);
    if (filterId === 'pre') return 'cm-badge-runs--pre';
    if (filterId === 'conductor') return 'cm-badge-runs--conductor';
    return isPreConductorStep(stepId) ? 'cm-badge-runs--pre' : 'cm-badge-runs--conductor';
  }

  function isPreConductorStep(stepId) {
    if (!stepId || stepId === 'unknown') return false;
    return compareStepIds(stepId, CONDUCTOR_CUTOFF) < 0;
  }

  function isConductorStep(stepId) {
    if (!stepId || stepId === 'unknown') return false;
    return compareStepIds(stepId, CONDUCTOR_CUTOFF) >= 0;
  }

  function stepMatchesMetricFilter(stepId, filterId) {
    if (filterId === 'all') return true;
    if (filterId === 'pre') return isPreConductorStep(stepId);
    if (filterId === 'conductor') return isConductorStep(stepId);
    return true;
  }

  function stepMatchesPhaseFilter(stepId, phaseFilter) {
    if (phaseFilter === 'all') return true;
    return String(phaseOf(stepId)) === String(phaseFilter);
  }

  function stepVisibleInTracking(stepId, ctx) {
    if (!stepMatchesPhaseFilter(stepId, ctx.phaseFilter)) return false;
    const hasMetrics = hasStepMetrics(stepId, ctx.index);
    if (ctx.metricsOnly && !hasMetrics) return false;
    const metricFilter = effectiveMetricFilter(ctx);
    if (metricFilter !== 'all') {
      if (!hasMetrics || !stepMatchesMetricFilter(stepId, metricFilter)) return false;
    }
    return true;
  }

  function filteredRoadmapRows(roadmap, ctx) {
    return (roadmap?.rows || []).filter(row => stepMatchesPhaseFilter(row.id, ctx.phaseFilter));
  }

  function runLabelForStep(stepId, ctx) {
    const filterId = effectiveMetricFilter(ctx);
    if (filterId === 'pre') return 'Agent runs';
    if (filterId === 'conductor') return 'Conductor runs';
    return isPreConductorStep(stepId) ? 'Agent runs' : 'Conductor runs';
  }

  function sessionsLabelForStep(stepId, ctx) {
    const filterId = effectiveMetricFilter(ctx);
    if (filterId === 'pre') return 'Agent sessions';
    if (filterId === 'conductor') return 'Conductor sessions';
    return isPreConductorStep(stepId) ? 'Agent sessions' : 'Conductor sessions';
  }

  function runsBadgeTitle(stepId, ctx) {
    const kind = runLabelForStep(stepId, ctx);
    const filterId = effectiveMetricFilter(ctx);
    return filterId === 'pre' ? `${kind} (Cloud Agent, before Conductor V2)` : kind;
  }

  function el(tag, className, html) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (html !== undefined) node.innerHTML = html;
    return node;
  }

  /**
   * Escape a dynamic value for interpolation into an HTML string (element and
   * quoted-attribute context). Metrics and the roadmap snapshot carry
   * PR-authored strings (session title, branch, step names), so every string
   * from those files passes through here before it reaches markup.
   *
   * Four kinds of interpolation skip it, and only these:
   * - numbers rendered by `formatNumber`/`formatCompactNumber`/`formatDuration`,
   *   which coerce through `numberOrNull` and can only emit digits, locale
   *   separators and “—”;
   * - counts derived in this file (array lengths, `Math.round`), which never see
   *   a JSON value — `pct` also feeds a CSS width and an ARIA value, where
   *   locale formatting would be wrong;
   * - the literal-markup helpers `sourceBadge`, `tokensSourceMarker` and
   *   `outcomeIcon`, which return fixed strings and interpolate nothing;
   * - markup composed by the helpers here (`linkRef`, the row and table
   *   fragments), which escape their own inputs before returning HTML.
   */
  function escapeHtml(value) {
    if (value == null) return '';
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /** Resolve a link target, keeping only http(s); `javascript:`/`data:` yield ''. */
  function safeUrl(url) {
    const raw = String(url ?? '').trim();
    if (!raw) return '';
    let parsed;
    try {
      parsed = new URL(raw, window.location.href);
    } catch {
      return '';
    }
    return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : '';
  }

  /**
   * Read a metrics field as a finite number, or `null` when it is absent or not
   * numeric. A JSON number field can hold any JSON value, and neither
   * `Number.isNaN` nor `String.prototype.toLocaleString` rejects a string — so
   * this is what keeps a string out of the formatters and out of the markup.
   */
  function numberOrNull(value) {
    if (typeof value === 'number') return Number.isFinite(value) ? value : null;
    if (typeof value !== 'string' || value.trim() === '') return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
  }

  function formatNumber(n) {
    const num = numberOrNull(n);
    if (num === null) return '—';
    return num.toLocaleString(NUMBER_LOCALE);
  }

  function formatCompactNumber(n) {
    const num = numberOrNull(n);
    if (num === null || num === 0) return '—';
    if (num >= 1_000_000)
      return `${(num / 1_000_000).toLocaleString(NUMBER_LOCALE, { maximumFractionDigits: 1 })} M`;
    if (num >= 10_000) return `${Math.round(num / 1000).toLocaleString(NUMBER_LOCALE)} k`;
    return num.toLocaleString(NUMBER_LOCALE);
  }

  function formatDuration(ms) {
    const total = numberOrNull(ms);
    if (total === null) return '—';
    const sec = Math.round(total / 1000);
    if (sec < 60) return `${sec}s`;
    const min = Math.floor(sec / 60);
    const rem = sec % 60;
    if (min < 60) return `${min}m ${rem}s`;
    const h = Math.floor(min / 60);
    return `${h}h ${min % 60}m`;
  }

  function sessionDurationMs(session) {
    const direct = numberOrNull(session?.totals?.durationMs) ?? 0;
    if (direct > 0) return direct;
    if (session?.startedAt && session?.completedAt) {
      const span = Date.parse(session.completedAt) - Date.parse(session.startedAt);
      if (Number.isFinite(span) && span > 1000) return span;
    }
    return direct;
  }

  function formatDate(iso) {
    if (!iso) return '—';
    try {
      const d = new Date(iso);
      const y = d.getUTCFullYear();
      const m = String(d.getUTCMonth() + 1).padStart(2, '0');
      const day = String(d.getUTCDate()).padStart(2, '0');
      const h = String(d.getUTCHours()).padStart(2, '0');
      const min = String(d.getUTCMinutes()).padStart(2, '0');
      return `${y}-${m}-${day} ${h}:${min} UTC`;
    } catch {
      return iso;
    }
  }

  function mountLazyCharts(container, Chart) {
    if (!container || !Chart) return;
    container.querySelectorAll('.cm-chart-slot:not([data-mounted])').forEach(slot => {
      let tokens = {};
      try {
        tokens = JSON.parse(slot.dataset.tokens || '{}');
      } catch {
        /* ignore */
      }
      slot.dataset.mounted = '1';
      const canvas = el('canvas');
      slot.appendChild(canvas);
      trackChart(renderTokenChart(canvas, tokens));
    });
  }

  function statusBadge(status) {
    const map = {
      completed: '✅ completed',
      in_progress: '⏳ in progress',
      failed: '❌ failed',
      cancelled: '⛔ cancelled'
    };
    return map[status] || status || '—';
  }

  function outcomeIcon(outcome) {
    if (outcome === 'success') return '✅';
    if (outcome === 'escalate') return '⚠️';
    if (outcome === 'cancelled') return '⛔';
    if (outcome === 'error') return '❌';
    return 'ℹ️';
  }

  function linkRef(label, url) {
    if (!label || label === '—') return '—';
    const text = escapeHtml(label);
    const href = safeUrl(url);
    if (href) return `<a href="${escapeHtml(href)}" target="_blank" rel="noopener">${text}</a>`;
    return text;
  }

  function sessionsForEntry(entry, cache) {
    if (!entry?.sessionIds?.length) return [];
    return entry.sessionIds.map(id => cache.get(id)).filter(Boolean);
  }

  function compareStepIds(a, b) {
    const pa = String(a)
      .split('.')
      .map(p => {
        const m = p.match(/^(\d+)([a-z]?)$/i);
        return m ? [Number(m[1]), m[2] || ''] : [0, p];
      });
    const pb = String(b)
      .split('.')
      .map(p => {
        const m = p.match(/^(\d+)([a-z]?)$/i);
        return m ? [Number(m[1]), m[2] || ''] : [0, p];
      });
    const len = Math.max(pa.length, pb.length);
    for (let i = 0; i < len; i += 1) {
      const xa = pa[i] || [0, ''];
      const xb = pb[i] || [0, ''];
      if (xa[0] !== xb[0]) return xa[0] - xb[0];
      if (xa[1] !== xb[1]) return xa[1].localeCompare(xb[1]);
    }
    return 0;
  }

  async function fetchJson(candidates) {
    for (const url of candidates) {
      try {
        const res = await fetch(url, { cache: 'no-store' });
        if (res.ok) {
          return await res.json();
        }
      } catch {
        /* try next */
      }
    }
    return null;
  }

  async function loadSession(sessionId) {
    return fetchJson(metricsUrlCandidates(`sessions/${sessionId}.json`));
  }

  function loadChartJs() {
    if (window.Chart) return Promise.resolve(window.Chart);
    if (chartJsPromise) return chartJsPromise;
    chartJsPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
      script.onload = () => resolve(window.Chart);
      script.onerror = () => reject(new Error('Chart.js failed to load'));
      document.head.appendChild(script);
    });
    return chartJsPromise;
  }

  function aggregateStepSessions(sessions) {
    const totals = { input: 0, output: 0, cacheRead: 0, cacheWrite: 0, total: 0 };
    let durationMs = 0;
    let phases = 0;
    let escalations = 0;
    for (const s of sessions) {
      const t = s.totals?.tokens || {};
      totals.input += tokenValue(t.input);
      totals.output += tokenValue(t.output);
      totals.cacheRead += tokenValue(t.cacheRead);
      totals.cacheWrite += tokenValue(t.cacheWrite);
      totals.total += tokenValue(t.total);
      durationMs += sessionDurationMs(s);
      phases += tokenValue(s.totals?.phaseCount) || s.phases?.length || 0;
      escalations += tokenValue(s.totals?.escalations);
    }
    return { tokens: totals, durationMs, phases, escalations, runCount: sessions.length };
  }

  function aggregateAllSteps(roadmapRows, index, sessionCache, ctx) {
    const metricFilter = effectiveMetricFilter(ctx);
    const perStep = [];
    let global = {
      tokens: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0, total: 0 },
      durationMs: 0,
      phases: 0,
      escalations: 0,
      runCount: 0
    };
    const roadmapIds = new Set((roadmapRows || []).map(r => r.id));

    for (const row of roadmapRows || []) {
      if (!stepMatchesMetricFilter(row.id, metricFilter)) continue;
      const entry = index?.steps?.[row.id];
      const sessions = sessionsForEntry(entry, sessionCache);
      if (!sessions.length) continue;
      const agg = aggregateStepSessions(sessions);
      perStep.push({ id: row.id, title: row.name, ...agg });
      global.tokens.input += agg.tokens.input;
      global.tokens.output += agg.tokens.output;
      global.tokens.cacheRead += agg.tokens.cacheRead;
      global.tokens.cacheWrite += agg.tokens.cacheWrite;
      global.tokens.total += agg.tokens.total;
      global.durationMs += agg.durationMs;
      global.phases += agg.phases;
      global.escalations += agg.escalations;
      global.runCount += agg.runCount;
    }

    return { perStep, global, roadmapIds };
  }

  function computeProgress(roadmap, ctx) {
    const rows = filteredRoadmapRows(roadmap, ctx);
    const done = rows.filter(isStepDone).length;
    const total = rows.length;
    const pct = total ? Math.round((done / total) * 100) : 0;
    const currentStep = roadmap?.currentStep || '—';
    const currentPhase = phaseOf(currentStep);
    const phaseKey = ctx.phaseFilter === 'all' ? currentPhase : parseInt(ctx.phaseFilter, 10);
    const phaseDef = PHASE_DEFINITIONS[phaseKey] || {};
    const withMetrics = rows.filter(r => hasStepMetrics(r.id, ctx.index)).length;

    let scopeLabel = 'All phases';
    if (ctx.phaseFilter !== 'all') {
      scopeLabel = `Phase ${ctx.phaseFilter}: ${phaseDef.title || ''}`;
    }

    const metricFilter = effectiveMetricFilter(ctx);
    const metricLabel = METRIC_SUB_FILTERS[metricFilter]?.label || 'All runs';

    return {
      done,
      total,
      pct,
      currentStep,
      phaseKey,
      phaseDef,
      withMetrics,
      scopeLabel,
      metricLabel
    };
  }

  function collectUnassigned(index, sessionCache, roadmapIds) {
    const unassignedKeys = Object.keys(index?.steps || {}).filter(
      k => k === 'unknown' || !roadmapIds.has(k)
    );
    const items = [];
    for (const key of unassignedKeys) {
      const entry = index.steps[key];
      const sessions = sessionsForEntry(entry, sessionCache);
      if (!sessions.length) continue;
      items.push({
        key,
        title: entry.title || key,
        sessions,
        agg: aggregateStepSessions(sessions)
      });
    }
    items.sort((a, b) =>
      (b.sessions[0]?.startedAt || '').localeCompare(a.sessions[0]?.startedAt || '')
    );
    return items;
  }

  function renderBarChart(
    canvas,
    labels,
    values,
    titles,
    color = 'rgba(92, 107, 192, 0.75)',
    borderColor = '#5c6bc0'
  ) {
    if (!canvas || !window.Chart || !values.some(v => v > 0)) return null;
    const titleMap = titles || {};
    return trackChart(
      new window.Chart(canvas, {
        type: 'bar',
        data: {
          labels,
          datasets: [
            {
              label: 'Total tokens',
              data: values,
              backgroundColor: color,
              borderColor,
              borderWidth: 1
            }
          ]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                title(ctx) {
                  const id = ctx[0]?.label || '';
                  const name = titleMap[id];
                  return name ? `${id} — ${name}` : id;
                },
                label(ctx) {
                  return `${formatNumber(ctx.raw)} tokens`;
                }
              }
            }
          },
          scales: {
            x: { ticks: { maxRotation: 45, minRotation: 45, autoSkip: true, maxTicksLimit: 24 } },
            y: {
              beginAtZero: true,
              ticks: {
                callback(v) {
                  return formatCompactNumber(v);
                }
              }
            }
          }
        }
      })
    );
  }

  function renderProgressBar(roadmap, ctx) {
    const p = computeProgress(roadmap, ctx);
    const wrap = el('div', 'cm-progress-wrap');
    wrap.innerHTML = `
      <div class="cm-progress-meta">
        <span><strong>${escapeHtml(p.scopeLabel)}</strong> · ${p.done}/${p.total} done (${p.pct}%)</span>
        <span>Step <strong>${escapeHtml(p.currentStep)}</strong> · ${p.withMetrics} with metrics · ${escapeHtml(p.metricLabel)}</span>
      </div>
      <div class="cm-progress-bar" role="progressbar" aria-valuenow="${p.pct}" aria-valuemin="0" aria-valuemax="100" aria-label="Roadmap progress">
        <div class="cm-progress-fill" style="width:${p.pct}%"></div>
      </div>
      ${p.phaseDef.subtitle ? `<p class="cm-progress-phase-label cm-muted">${escapeHtml(p.phaseDef.title)} — ${escapeHtml(p.phaseDef.subtitle)}</p>` : ''}
    `;
    return wrap;
  }

  function renderPhaseFilterTabs(ctx, onChange) {
    const wrap = el('div', 'cm-filter-tabs cm-filter-tabs--phase');
    wrap.setAttribute('role', 'tablist');
    wrap.setAttribute('aria-label', 'Filter by phase');

    const phases = [{ id: 'all', label: 'All metrics' }];
    for (let n = 1; n <= 5; n += 1) {
      const def = PHASE_DEFINITIONS[n];
      phases.push({ id: String(n), label: def ? `Phase ${n}` : `Phase ${n}` });
    }

    phases.forEach(f => {
      const btn = el('button', 'cm-filter-tab' + (ctx.phaseFilter === f.id ? ' is-active' : ''));
      btn.type = 'button';
      btn.setAttribute('role', 'tab');
      btn.setAttribute('aria-selected', ctx.phaseFilter === f.id ? 'true' : 'false');
      btn.dataset.phaseFilter = f.id;
      btn.textContent = f.label;
      btn.addEventListener('click', () => onChange({ phaseFilter: f.id }));
      wrap.appendChild(btn);
    });

    return wrap;
  }

  function renderMetricSubFilterTabs(ctx, onChange) {
    const wrap = el('div', 'cm-filter-tabs cm-filter-tabs--metric');
    wrap.setAttribute('role', 'tablist');
    wrap.setAttribute('aria-label', 'Filter metrics by run type');
    wrap.hidden = ctx.phaseFilter !== 'all';

    Object.values(METRIC_SUB_FILTERS).forEach(f => {
      const btn = el(
        'button',
        'cm-filter-tab cm-filter-tab--sub' + (ctx.metricFilter === f.id ? ' is-active' : '')
      );
      btn.type = 'button';
      btn.setAttribute('role', 'tab');
      btn.setAttribute('aria-selected', ctx.metricFilter === f.id ? 'true' : 'false');
      btn.dataset.metricFilter = f.id;
      btn.textContent = f.label;
      btn.addEventListener('click', () => onChange({ metricFilter: f.id }));
      wrap.appendChild(btn);
    });

    return wrap;
  }

  function renderFilterControls(ctx, onChange) {
    const host = el('div', 'cm-filter-controls');
    host.appendChild(renderPhaseFilterTabs(ctx, onChange));
    host.appendChild(renderMetricSubFilterTabs(ctx, onChange));
    return host;
  }

  function refreshFilterControls(section, ctx, onChange) {
    const old = section.querySelector('.cm-filter-controls');
    if (!old) return;
    const next = renderFilterControls(ctx, onChange);
    old.replaceWith(next);
  }

  function overviewFilterLabel(ctx) {
    if (ctx.phaseFilter !== 'all') {
      const def = PHASE_DEFINITIONS[parseInt(ctx.phaseFilter, 10)];
      return def ? `Phase ${ctx.phaseFilter}: ${def.title}` : `Phase ${ctx.phaseFilter}`;
    }
    return METRIC_SUB_FILTERS[effectiveMetricFilter(ctx)]?.label || 'All metrics';
  }

  function renderOverviewContent(roadmap, index, sessionCache, Chart, ctx) {
    const rows = filteredRoadmapRows(roadmap, ctx);
    const metricFilter = effectiveMetricFilter(ctx);
    const filterMeta = METRIC_SUB_FILTERS[metricFilter] || METRIC_SUB_FILTERS.all;
    const { perStep, global } = aggregateAllSteps(rows, index, sessionCache, ctx);
    const host = el('div', 'cm-overview-dynamic');

    host.appendChild(renderProgressBar(roadmap, ctx));

    if (!global.runCount) {
      host.appendChild(
        el('p', 'cm-muted', `No metrics for “${escapeHtml(overviewFilterLabel(ctx))}”.`)
      );
      return host;
    }

    const cards = el('div', 'cm-overview-cards');
    cards.innerHTML = `
      <div class="cm-overview-card"><span>${escapeHtml(filterMeta.runLabel)}</span><strong>${formatNumber(global.runCount)}</strong></div>
      <div class="cm-overview-card"><span>Phases</span><strong>${formatNumber(global.phases)}</strong></div>
      <div class="cm-overview-card"><span>Escalations</span><strong>${formatNumber(global.escalations)}</strong></div>
      <div class="cm-overview-card"><span>Total tokens</span><strong title="${formatNumber(global.tokens.total)}">${formatCompactNumber(global.tokens.total)}</strong></div>
      <div class="cm-overview-card"><span>Total duration</span><strong>${formatDuration(global.durationMs)}</strong></div>
      <div class="cm-overview-card"><span>Steps with data</span><strong>${formatNumber(perStep.length)}</strong></div>
    `;
    host.appendChild(cards);

    const charts = el('div', 'cm-overview-charts');
    const label = escapeHtml(overviewFilterLabel(ctx));
    const barWrap = el('div', 'cm-overview-chart-box');
    barWrap.appendChild(el('h3', 'cm-chart-title', `Tokens per step (${label})`));
    const barCanvas = el('canvas');
    barWrap.appendChild(barCanvas);
    charts.appendChild(barWrap);

    const pieWrap = el('div', 'cm-overview-chart-box');
    pieWrap.appendChild(el('h3', 'cm-chart-title', `Token mix (${label})`));
    const pieCanvas = el('canvas');
    pieWrap.appendChild(pieCanvas);
    charts.appendChild(pieWrap);
    host.appendChild(charts);

    if (Chart) {
      const sorted = [...perStep].sort((a, b) => compareStepIds(a.id, b.id));
      const titleMap = Object.fromEntries(sorted.map(s => [s.id, s.title]));
      const barColor =
        metricFilter === 'pre'
          ? 'rgba(255, 152, 0, 0.75)'
          : metricFilter === 'conductor'
            ? 'rgba(92, 107, 192, 0.75)'
            : 'rgba(76, 175, 80, 0.75)';
      const barBorder =
        metricFilter === 'pre' ? '#ef6c00' : metricFilter === 'conductor' ? '#5c6bc0' : '#43a047';
      renderBarChart(
        barCanvas,
        sorted.map(s => s.id),
        sorted.map(s => s.tokens.total),
        titleMap,
        barColor,
        barBorder
      );
      trackChart(renderTokenChart(pieCanvas, global.tokens));
    }

    return host;
  }

  function renderOverviewSection(roadmap, index, sessionCache, Chart, ctx, onFilterChange) {
    const section = el('section', 'cm-overview-section');
    section.appendChild(el('h2', 'cm-section-title', 'Overview'));
    section.appendChild(renderFilterControls(ctx, onFilterChange));
    const body = el('div', 'cm-overview-body');
    body.appendChild(renderOverviewContent(roadmap, index, sessionCache, Chart, ctx));
    section.appendChild(body);
    return section;
  }

  function refreshOverviewSection(
    section,
    roadmap,
    index,
    sessionCache,
    Chart,
    ctx,
    onFilterChange
  ) {
    refreshFilterControls(section, ctx, onFilterChange);
    const body = section.querySelector('.cm-overview-body');
    if (!body) return;
    destroyCharts();
    body.innerHTML = '';
    body.appendChild(renderOverviewContent(roadmap, index, sessionCache, Chart, ctx));
  }

  function trackingRowHtml({
    id = '',
    name = '',
    status = '',
    audit = '',
    issueLabel = '',
    issueUrl = '',
    prLabel = '',
    prUrl = '',
    runs = '—',
    tokens = '—',
    runsTitle = '',
    tokensTitle = '',
    runsClass = '',
    isHeader = false
  }) {
    if (isHeader) {
      return `
        <span class="cm-col-id">ID</span>
        <span class="cm-col-name">Task</span>
        <span class="cm-badge-label">Status</span>
        <span class="cm-badge-label">Audit</span>
        <span class="cm-badge-label">Issue</span>
        <span class="cm-badge-label">PR</span>
        <span class="cm-badge-label cm-badge-label-runs">Runs</span>
        <span class="cm-badge-label">Tokens</span>
        <span class="cm-col-chevron" aria-hidden="true"></span>
      `;
    }

    return `
      <span class="cm-col-id">${escapeHtml(id)}</span>
      <span class="cm-col-name" title="${escapeHtml(name)}">${escapeHtml(name) || '—'}</span>
      <span class="cm-badge cm-badge-status" title="Status">${escapeHtml(status) || '—'}</span>
      <span class="cm-badge cm-badge-audit" title="Audit">${escapeHtml(audit) || '—'}</span>
      <span class="cm-badge cm-badge-issue" title="Issue">${linkRef(issueLabel || '—', issueUrl)}</span>
      <span class="cm-badge cm-badge-pr" title="Pull request">${linkRef(prLabel || '—', prUrl)}</span>
      <span class="cm-badge cm-badge-runs ${escapeHtml(runsClass)}" title="${escapeHtml(runsTitle)}">${escapeHtml(runs)}</span>
      <span class="cm-badge cm-badge-tokens" title="${escapeHtml(tokensTitle)}">${escapeHtml(tokens)}</span>
      <span class="cm-chevron" aria-hidden="true">▶</span>
    `;
  }

  function renderTrackingHeader() {
    const sticky = el('div', 'cm-tracking-sticky');
    const hscroll = el('div', 'cm-tracking-hscroll cm-tracking-hscroll--header');
    const row = el('div', 'cm-tracking-row cm-tracking-row--header');
    row.innerHTML = trackingRowHtml({ isHeader: true });
    hscroll.appendChild(row);
    sticky.appendChild(hscroll);
    return sticky;
  }

  function wireTrackingScrollSync(panel) {
    const headerScroll = panel.querySelector('.cm-tracking-sticky .cm-tracking-hscroll');
    const bodyScroll = panel.querySelector('.cm-tracking-hscroll--body');
    if (!headerScroll || !bodyScroll) return;

    let syncing = false;
    const sync = (from, to) => {
      if (syncing || from.scrollLeft === to.scrollLeft) return;
      syncing = true;
      to.scrollLeft = from.scrollLeft;
      syncing = false;
    };

    headerScroll.addEventListener('scroll', () => sync(headerScroll, bodyScroll), {
      passive: true
    });
    bodyScroll.addEventListener('scroll', () => sync(bodyScroll, headerScroll), { passive: true });
  }

  function renderTrackingLegend() {
    const legend = el('p', 'cm-tracking-legend cm-muted');
    legend.innerHTML =
      '<strong>Legend:</strong> ✅ done · 🛡️ audit passed · ⚠️ audit pending · ⏳ planned · ⏸️ paused · ' +
      '<span class="cm-legend-metric cm-badge-runs--pre" aria-hidden="true"></span> Pre-Conductor · ' +
      '<span class="cm-legend-metric cm-badge-runs--conductor" aria-hidden="true"></span> Conductor';
    return legend;
  }

  function renderTrackingToolbar(ctx) {
    const toolbar = el('div', 'cm-tracking-toolbar');
    toolbar.appendChild(renderTrackingLegend());

    const controls = el('div', 'cm-tracking-controls');

    const metricsLabel = el('label', 'cm-toggle-label');
    const metricsInput = el('input');
    metricsInput.type = 'checkbox';
    metricsInput.className = 'cm-metrics-only-toggle';
    metricsInput.checked = !!ctx.metricsOnly;
    metricsInput.addEventListener('change', () => {
      ctx.metricsOnly = metricsInput.checked;
      applyTrackingVisibility(ctx);
    });
    metricsLabel.appendChild(metricsInput);
    metricsLabel.appendChild(document.createTextNode(' Hide steps without metrics'));
    controls.appendChild(metricsLabel);
    ctx.metricsOnlyToggle = metricsInput;

    const expandBtn = el('button', 'cm-phase-action-btn');
    expandBtn.type = 'button';
    expandBtn.textContent = 'Expand all';
    expandBtn.addEventListener('click', () => setAllAccordionOpen(ctx.accordion, true));
    controls.appendChild(expandBtn);

    const collapseBtn = el('button', 'cm-phase-action-btn');
    collapseBtn.type = 'button';
    collapseBtn.textContent = 'Collapse all';
    collapseBtn.addEventListener('click', () => setAllAccordionOpen(ctx.accordion, false));
    controls.appendChild(collapseBtn);

    toolbar.appendChild(controls);
    return toolbar;
  }

  function setAllAccordionOpen(accordion, open) {
    if (!accordion) return;
    accordion.querySelectorAll('details.cm-accordion-item[data-step-id]').forEach(item => {
      if (!item.classList.contains('cm-is-hidden')) item.open = open;
    });
  }

  function setPhaseAccordionOpen(accordion, phase, open) {
    if (!accordion) return;
    accordion.querySelectorAll(`details.cm-accordion-item[data-phase="${phase}"]`).forEach(item => {
      if (!item.classList.contains('cm-is-hidden')) item.open = open;
    });
  }

  function bindPhaseDividerActions(container, phase, accordion) {
    if (!container || !accordion) return;
    container.querySelector('.cm-phase-expand')?.addEventListener('click', e => {
      e.stopPropagation();
      setPhaseAccordionOpen(accordion, phase, true);
    });
    container.querySelector('.cm-phase-collapse')?.addEventListener('click', e => {
      e.stopPropagation();
      setPhaseAccordionOpen(accordion, phase, false);
    });
  }

  function phaseStats(phase, rows, index) {
    const phaseRows = rows.filter(r => phaseOf(r.id) === phase);
    const done = phaseRows.filter(isStepDone).length;
    const withMetrics = phaseRows.filter(r => hasStepMetrics(r.id, index)).length;
    return { total: phaseRows.length, done, withMetrics };
  }

  function renderPhaseDivider(phase, rows, index, ctx, accordion) {
    const def = phaseDef(phase);
    const stats = phaseStats(phase, rows, index);
    const divider = el('div', 'cm-phase-divider');
    applyPhaseColor(divider, phase);
    if (ctx.phaseFilter !== 'all' && String(phase) !== String(ctx.phaseFilter)) {
      divider.classList.add('cm-phase-divider--inactive');
    }

    divider.innerHTML = `
      <div class="cm-phase-divider-inner">
        <span class="cm-phase-badge">Phase ${escapeHtml(phase)}</span>
        <div class="cm-phase-text">
          <span class="cm-phase-title">${escapeHtml(def.title)}</span>
          ${def.subtitle ? `<span class="cm-phase-subtitle">${escapeHtml(def.subtitle)}</span>` : ''}
        </div>
        <span class="cm-phase-stats">${stats.done}/${stats.total} done · ${stats.withMetrics} with metrics</span>
        <div class="cm-phase-actions">
          <button type="button" class="cm-phase-action-btn cm-phase-expand" title="Expand phase" aria-label="Expand phase">▼</button>
          <button type="button" class="cm-phase-action-btn cm-phase-collapse" title="Collapse phase" aria-label="Collapse phase">▲</button>
        </div>
      </div>
    `;

    bindPhaseDividerActions(divider, phase, accordion);

    return divider;
  }

  function renderUnassignedSection(unassigned, Chart) {
    if (!unassigned.length) return null;

    const section = el('section', 'cm-unassigned-section');
    section.appendChild(el('h2', 'cm-section-title', 'Unassigned runs'));
    section.appendChild(
      el(
        'p',
        'cm-muted cm-section-lead',
        'Conductor sessions without a matching ROADMAP step ID — e.g. audit runs or early attempts.'
      )
    );

    const panel = el('div', 'cm-tracking-panel');
    const accordion = el('div', 'cm-accordion cm-unassigned-accordion');

    for (const item of unassigned) {
      const details = el('details', 'cm-accordion-item cm-accordion-item--unassigned');
      const summary = el('summary', 'cm-accordion-summary cm-tracking-row');
      summary.innerHTML = trackingRowHtml({
        id: item.key === 'unknown' ? '—' : item.key,
        name: item.title,
        status: '—',
        audit: '—',
        runs: `${item.agg.runCount}×`,
        tokens: formatCompactNumber(item.agg.tokens.total),
        runsTitle: 'Conductor workflow dispatches',
        tokensTitle: `${formatNumber(item.agg.tokens.total)} tokens`,
        runsClass: 'cm-badge-runs--conductor'
      });
      details.appendChild(summary);

      const body = el('div', 'cm-accordion-body');
      body.appendChild(
        renderStepOverviewWithChart(item.agg.tokens, [
          { label: 'Runs', value: item.agg.runCount },
          { label: 'Phases', value: item.agg.phases },
          { label: 'Tokens', value: formatNumber(item.agg.tokens.total) },
          { label: 'Duration', value: formatDuration(item.agg.durationMs) }
        ])
      );
      body.appendChild(el('h3', 'cm-subtitle', 'Sessions'));
      item.sessions.forEach(s => body.appendChild(renderSessionBlock(s, Chart)));
      details.appendChild(body);
      wireAccordionCharts(details, Chart);
      accordion.appendChild(details);
    }

    const bodyScroll = el('div', 'cm-tracking-hscroll cm-tracking-hscroll--body');
    const bodyInner = el('div', 'cm-tracking-inner');
    bodyInner.appendChild(accordion);
    bodyScroll.appendChild(bodyInner);
    panel.appendChild(bodyScroll);
    section.appendChild(panel);
    return section;
  }

  /** Token/count field as a summable number; anything non-numeric counts as 0. */
  function tokenValue(n) {
    return numberOrNull(n) ?? 0;
  }

  function renderTokenChart(canvas, tokens) {
    if (!canvas || !window.Chart) return null;
    const data = [
      tokenValue(tokens.input),
      tokenValue(tokens.output),
      tokenValue(tokens.cacheRead),
      tokenValue(tokens.cacheWrite)
    ];
    if (data.every(v => v === 0)) return null;
    return trackChart(
      new window.Chart(canvas, {
        type: 'doughnut',
        data: {
          labels: ['Input', 'Output', 'Cache read', 'Cache write'],
          datasets: [{ data, backgroundColor: ['#5c6bc0', '#26a69a', '#ffb74d', '#ef5350'] }]
        },
        options: { plugins: { legend: { position: 'bottom' } }, maintainAspectRatio: true }
      })
    );
  }

  function renderAgentRunsBreakdown(runs) {
    const rows = (runs || [])
      .map(
        r => `
        <tr>
          <td>Run ${escapeHtml(r.index)}</td>
          <td><code title="${escapeHtml(r.runId || '')}">${escapeHtml((r.runId || '—').slice(0, 12))}…</code></td>
          <td>${formatDuration(r.durationMs)}</td>
          <td title="${formatNumber(r.tokens?.total)}">${formatCompactNumber(r.tokens?.total)}</td>
          <td class="cm-muted">${r.startedAt ? escapeHtml(formatDate(r.startedAt)) : '—'}</td>
        </tr>`
      )
      .join('');
    return `
      <table class="cm-table cm-agent-runs-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Run ID</th>
            <th>Duration</th>
            <th>Tokens</th>
            <th>Started</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>`;
  }

  function isV1UiSession(session) {
    if (!session) return false;
    if (session.source === 'v1-ui') return true;
    if (session.v1Continued) return true;
    return (session.phases || []).some(p => p.v1 === true || p.tokensSource === 'cursor-api-v1');
  }

  function sourceBadge(session) {
    if (isV1UiSession(session)) {
      return '<span class="cm-badge cm-badge-v1" title="cursor.com/agents UI (V1 orchestrator)">V1 UI</span>';
    }
    if (session?.manualImport) {
      return '<span class="cm-badge cm-badge-manual" title="Pre-Conductor manual import">Manual</span>';
    }
    if ((session?.phases || []).some(p => p.github?.runUrl || p.github?.runId)) {
      return '<span class="cm-badge cm-badge-conductor" title="Conductor (GHA)">Conductor</span>';
    }
    return '';
  }

  function tokensSourceMarker(tokensSource) {
    if (tokensSource === 'cursor-api') {
      return ' <span class="cm-muted" title="Backfilled via Cursor API">↻</span>';
    }
    if (tokensSource === 'cursor-api-v1') {
      return ' <span class="cm-muted" title="V1 UI import (import-manual-agents)">◇</span>';
    }
    if (tokensSource === 'cursor-api-manual' || tokensSource === 'cursor-dashboard-manual') {
      return ' <span class="cm-muted" title="Manual import">⤴</span>';
    }
    return '';
  }

  function renderPhaseTable(phases) {
    const scroll = el('div', 'cm-table-scroll');
    const table = el('table', 'cm-table');
    table.innerHTML = `
      <thead>
        <tr>
          <th>Phase</th>
          <th>Batch</th>
          <th>Duration</th>
          <th>Tokens</th>
          <th>Outcome</th>
          <th>Links</th>
        </tr>
      </thead>
      <tbody></tbody>
    `;
    const tbody = table.querySelector('tbody');
    (phases || []).forEach(p => {
      const tr = el('tr');
      const batch = p.batchSteps?.length ? p.batchSteps.join(', ') : '—';
      const links = [];
      const agentUrl = safeUrl(p.agent?.url);
      const jobUrl = safeUrl(p.github?.jobUrl);
      const runUrl = safeUrl(p.github?.runUrl);
      if (agentUrl) links.push(linkRef('Agent', agentUrl));
      if (jobUrl)
        links.push(linkRef(`Job${p.github.runAttempt ? ` a${p.github.runAttempt}` : ''}`, jobUrl));
      else if (runUrl) links.push(linkRef('GHA', runUrl));
      const runCount = p.agent?.runs?.length || 0;
      const runBadge =
        runCount > 1
          ? ` <span class="cm-muted" title="${runCount} follow-up runs in this agent chat">· ${runCount} runs</span>`
          : '';
      tr.innerHTML = `
        <td><strong>${escapeHtml(p.type)}</strong>${p.attempt ? ` <span class="cm-muted">retry ${escapeHtml(p.attempt)}</span>` : ''}${runBadge}${tokensSourceMarker(p.tokensSource)}</td>
        <td>${escapeHtml(batch)}</td>
        <td>${formatDuration(p.durationMs)}</td>
        <td title="${escapeHtml(p.notes || '')}">${formatNumber(p.tokens?.total)}</td>
        <td>${outcomeIcon(p.outcome)} ${escapeHtml(p.outcome) || '—'}</td>
        <td class="cm-links">${links.join(' · ') || '—'}</td>
      `;
      tbody.appendChild(tr);

      if (runCount > 1) {
        const detailTr = el('tr', 'cm-phase-runs-row');
        const detailTd = el('td');
        detailTd.colSpan = 6;
        const details = el('details', 'cm-agent-runs');
        details.innerHTML = `<summary>${runCount} agent runs (follow-up chat)</summary>${renderAgentRunsBreakdown(p.agent.runs)}`;
        detailTd.appendChild(details);
        detailTr.appendChild(detailTd);
        tbody.appendChild(detailTr);
      }
    });
    scroll.appendChild(table);
    return scroll;
  }

  function renderSessionBlock(session, Chart) {
    const block = el('article', 'cm-session');
    const t = session.totals?.tokens || {};
    block.appendChild(
      el(
        'header',
        'cm-session-header cm-session-header-compact',
        `<span><code>${escapeHtml(session.sessionId.slice(0, 8))}…</code></span>
         ${sourceBadge(session)}
         <span>${escapeHtml(statusBadge(session.status))}</span>
         <span title="${formatNumber(t.total)} tokens"><strong>${formatCompactNumber(t.total)}</strong> tokens</span>
         <span>${formatDuration(sessionDurationMs(session))}</span>
         <span class="cm-session-header-dates">${escapeHtml(formatDate(session.startedAt))}${session.completedAt ? ` → ${escapeHtml(formatDate(session.completedAt))}` : ''}</span>
         <span>Branch <code>${escapeHtml(session.branch) || '—'}</code></span>
         <span>GHA ${formatNumber(session.totals?.ghaJobs)}</span>
         <span>Esc ${formatNumber(tokenValue(session.totals?.escalations))}</span>`
      )
    );

    const grid = el('div', 'cm-session-grid');
    const stats = el('div', 'cm-session-stats');
    stats.innerHTML = `
      <ul>
        <li><span>Input</span><strong>${formatNumber(t.input)}</strong></li>
        <li><span>Output</span><strong>${formatNumber(t.output)}</strong></li>
        <li><span>Cache read</span><strong>${formatNumber(t.cacheRead)}</strong></li>
        <li><span>Cache write</span><strong>${formatNumber(t.cacheWrite)}</strong></li>
      </ul>
    `;
    grid.appendChild(stats);

    const chartWrap = el('div', 'cm-chart-wrap cm-chart-slot');
    chartWrap.dataset.tokens = JSON.stringify(t);
    grid.appendChild(chartWrap);
    block.appendChild(grid);

    block.appendChild(el('h4', 'cm-subtitle', 'Phases'));
    block.appendChild(renderPhaseTable(session.phases));
    if (session.issue) {
      const issueUrl = `https://github.com/Shadesman5/pagekit/issues/${encodeURIComponent(session.issue)}`;
      block.appendChild(el('p', 'cm-foot', `Issue: ${linkRef(`#${session.issue}`, issueUrl)}`));
    } else if (session.backfill?.workflowRunId) {
      block.appendChild(
        el(
          'p',
          'cm-foot',
          `Workflow run: ${linkRef(session.backfill.workflowRunId, session.phases[0]?.github?.runUrl)}`
        )
      );
    }
    return block;
  }

  function renderStepOverviewWithChart(tokens, labels) {
    const wrap = el('div', 'cm-step-overview-row');
    const overview = el('div', 'cm-step-overview');
    const grid = el('div', 'cm-overview-grid');
    const items = labels || [];
    grid.innerHTML = items
      .map(
        l => `<div><span>${escapeHtml(l.label)}</span><strong>${escapeHtml(l.value)}</strong></div>`
      )
      .join('');
    overview.appendChild(grid);
    wrap.appendChild(overview);

    if (tokens?.total > 0) {
      const chartRow = el('div', 'cm-step-chart-row cm-chart-slot');
      chartRow.dataset.tokens = JSON.stringify(tokens);
      wrap.appendChild(chartRow);
    }

    return wrap;
  }

  function wireAccordionCharts(item, Chart) {
    item.addEventListener('toggle', () => {
      if (item.open) mountLazyCharts(item, Chart);
    });
  }

  function accordionItemClasses(row, currentStep, hasMetrics) {
    const classes = ['cm-accordion-item'];
    if (isSubStep(row)) classes.push('cm-accordion-item--sub');
    if (isMilestone(row)) classes.push('cm-accordion-item--milestone');
    if (row.id === currentStep) classes.push('cm-accordion-item--current');
    if (!hasMetrics) classes.push('cm-accordion-item--no-metrics');
    return classes.join(' ');
  }

  function renderRoadmapAccordion(row, metricsEntry, sessionCache, Chart, ctx, currentStep) {
    const runCount = metricsEntry?.sessionIds?.length || 0;
    const item = el('details', accordionItemClasses(row, currentStep, runCount > 0));
    item.dataset.stepId = row.id;
    applyPhaseColor(item, phaseOf(row.id));

    const sessions = sessionsForEntry(metricsEntry, sessionCache);
    const agg = aggregateStepSessions(sessions);

    const summary = el('summary', 'cm-accordion-summary cm-tracking-row');
    summary.innerHTML = trackingRowHtml({
      id: row.id,
      name: row.name || '—',
      status: row.status || '—',
      audit: row.audit || '—',
      issueLabel: row.issueLabel,
      issueUrl: row.issueUrl,
      prLabel: row.prLabel,
      prUrl: row.prUrl,
      runs: runCount ? `${runCount}×` : '—',
      tokens: runCount ? formatCompactNumber(agg.tokens.total) : '—',
      runsTitle: runsBadgeTitle(row.id, ctx),
      tokensTitle: runCount ? `${formatNumber(agg.tokens.total)} tokens` : 'No metrics',
      runsClass: runsBadgeClass(row.id, ctx)
    });
    item.appendChild(summary);

    const body = el('div', 'cm-accordion-body');
    if (!runCount) {
      body.appendChild(
        el(
          'p',
          'cm-muted',
          isPreConductorStep(row.id)
            ? 'No agent sessions recorded for this step yet.'
            : 'No Conductor sessions recorded for this step yet.'
        )
      );
      item.appendChild(body);
      return item;
    }

    body.appendChild(
      renderStepOverviewWithChart(agg.tokens, [
        { label: runLabelForStep(row.id, ctx), value: runCount },
        { label: 'Phases (all runs)', value: agg.phases },
        { label: 'Escalations', value: agg.escalations },
        { label: 'Total tokens', value: formatNumber(agg.tokens.total) },
        { label: 'Total duration', value: formatDuration(agg.durationMs) }
      ])
    );

    body.appendChild(el('h3', 'cm-subtitle', sessionsLabelForStep(row.id, ctx)));
    sessions.forEach(s => body.appendChild(renderSessionBlock(s, Chart)));

    item.appendChild(body);
    wireAccordionCharts(item, Chart);
    return item;
  }

  function applyTrackingVisibility(ctx) {
    const { accordion, trackingSticky } = ctx;
    if (!accordion) return;

    accordion.querySelectorAll('.cm-accordion-item[data-step-id]').forEach(item => {
      const stepId = item.dataset.stepId;
      const hidden = !stepVisibleInTracking(stepId, ctx);
      item.classList.toggle('cm-is-hidden', hidden);
      if (hidden && item.open) item.open = false;
    });

    accordion.querySelectorAll('.cm-phase-divider').forEach(divider => {
      const phase = divider.dataset.phase;
      divider.hidden = ctx.phaseFilter !== 'all' && String(phase) !== String(ctx.phaseFilter);
      divider.classList.toggle(
        'cm-phase-divider--inactive',
        ctx.phaseFilter !== 'all' && String(phase) !== String(ctx.phaseFilter)
      );
    });

    const runsLabel = trackingSticky?.querySelector('.cm-badge-label-runs');
    if (runsLabel) {
      runsLabel.textContent = effectiveMetricFilter(ctx) === 'pre' ? 'Agents' : 'Runs';
    }

    if (ctx.metricsOnlyToggle) ctx.metricsOnlyToggle.checked = !!ctx.metricsOnly;
  }

  function applyFilters(partial, ctx) {
    if (partial.phaseFilter != null) {
      ctx.phaseFilter = partial.phaseFilter;
      if (ctx.phaseFilter !== 'all') ctx.metricFilter = 'all';
    }
    if (partial.metricFilter != null && ctx.phaseFilter === 'all') {
      ctx.metricFilter = partial.metricFilter;
    }

    const onFilterChange = next => applyFilters(next, ctx);

    refreshOverviewSection(
      ctx.overviewSection,
      ctx.roadmap,
      ctx.index,
      ctx.sessionCache,
      ctx.Chart,
      ctx,
      onFilterChange
    );
    applyTrackingVisibility(ctx);
  }

  async function preloadSessions(index) {
    const cache = new Map();
    const ids = new Set();
    for (const entry of Object.values(index?.steps || {})) {
      for (const id of entry.sessionIds || []) ids.add(id);
    }
    for (const id of ids) {
      const s = await loadSession(id);
      if (s) cache.set(id, s);
    }
    return cache;
  }

  async function init() {
    const root = document.getElementById(ROOT_ID);
    if (!root) return;

    root.innerHTML = '<p class="cm-loading">Loading roadmap &amp; conductor metrics…</p>';

    const [roadmap, index] = await Promise.all([
      fetchJson(metricsUrlCandidates('roadmap-snapshot.json')),
      fetchJson(metricsUrlCandidates('index.json'))
    ]);

    if (!roadmap?.rows?.length) {
      root.innerHTML =
        '<p class="cm-error">Could not load roadmap-snapshot.json. Run: node .github/conductor/sync-roadmap-snapshot.mjs</p>';
      return;
    }

    let Chart;
    try {
      Chart = await loadChartJs();
    } catch (e) {
      root.innerHTML = `<p class="cm-error">${escapeHtml(e.message)}</p>`;
      return;
    }

    const sessionCache = index ? await preloadSessions(index) : new Map();

    const ctx = {
      phaseFilter: 'all',
      metricFilter: 'all',
      metricsOnly: false,
      metricsOnlyToggle: null,
      roadmap,
      index,
      sessionCache,
      Chart,
      accordion: null,
      trackingSticky: null,
      overviewSection: null
    };

    const onFilterChange = partial => applyFilters(partial, ctx);

    root.innerHTML = '';
    root.appendChild(
      el(
        'p',
        'cm-meta',
        `<strong>Roadmap:</strong> v${escapeHtml(roadmap.version) || '—'} · step ${escapeHtml(roadmap.currentStep) || '—'} · ` +
          `<strong>Metrics:</strong> ${escapeHtml(formatDate(index?.updatedAt))} · ` +
          `<strong>Steps with data:</strong> ${Object.keys(index?.steps || {}).length}`
      )
    );

    ctx.overviewSection = renderOverviewSection(
      roadmap,
      index,
      sessionCache,
      Chart,
      ctx,
      onFilterChange
    );
    root.appendChild(ctx.overviewSection);

    root.appendChild(el('h2', 'cm-section-title cm-section-title-inline', 'Roadmap tracking'));
    root.appendChild(renderTrackingToolbar(ctx));

    const panel = el('div', 'cm-tracking-panel');
    ctx.trackingSticky = renderTrackingHeader();
    panel.appendChild(ctx.trackingSticky);

    const bodyScroll = el('div', 'cm-tracking-hscroll cm-tracking-hscroll--body');
    const bodyInner = el('div', 'cm-tracking-inner');

    ctx.accordion = el('div', 'cm-accordion');
    let lastPhase = null;
    for (const row of roadmap.rows) {
      const phase = phaseOf(row.id);
      if (phase !== lastPhase) {
        ctx.accordion.appendChild(
          renderPhaseDivider(phase, roadmap.rows, index, ctx, ctx.accordion)
        );
        lastPhase = phase;
      }
      const metricsEntry = index?.steps?.[row.id] || null;
      ctx.accordion.appendChild(
        renderRoadmapAccordion(row, metricsEntry, sessionCache, Chart, ctx, roadmap.currentStep)
      );
    }

    bodyInner.appendChild(ctx.accordion);
    bodyScroll.appendChild(bodyInner);
    panel.appendChild(bodyScroll);
    wireTrackingScrollSync(panel);
    root.appendChild(panel);

    applyTrackingVisibility(ctx);

    const { roadmapIds } = aggregateAllSteps(roadmap.rows, index, sessionCache, ctx);
    const unassigned = collectUnassigned(index, sessionCache, roadmapIds);
    const unassignedSection = renderUnassignedSection(unassigned, Chart);
    if (unassignedSection) root.appendChild(unassignedSection);

    root.appendChild(
      el(
        'p',
        'cm-foot',
        `Read-only mirror of <code>.cursor/ROADMAP.md</code> · metrics: conductor-metrics/ · agents write Git only`
      )
    );
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
