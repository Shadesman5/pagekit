// Cursor dashboard usage-events CSV → phase totals + per-model subagent rows.
// Parent /v1/agents/{id}/usage is orchestrator-only; the CSV still bills
// Task children on the parent Cloud Agent ID, split by model.

import { emptyTokens } from './metrics.mjs';

const CSV_NOTE = 'Tokens from Cursor dashboard CSV (orchestrator + subagents)';

const MODEL_LABELS = {
  'cursor-grok-4.6-high-fast': 'Grok 4.6 Fast',
  'cursor-grok-4.6-xhigh': 'Grok 4.6',
  'cursor-grok-4.6-xhigh-fast': 'Grok 4.6 Fast',
  'claude-opus-5-thinking-max': 'Claude Opus 5',
  'claude-fable-5-thinking-max': 'Claude Fable 5',
  'claude-fable-5-1-thinking-max': 'Claude Fable 5.1',
  'grok-4.6-medium': 'Grok 4.6',
  'cursor-grok-4.6-medium': 'Grok 4.6',
  github_bugbot: 'Bugbot'
};

export function prettyModelName(model) {
  const id = String(model || '').trim();
  if (!id) return 'Unknown model';
  if (MODEL_LABELS[id]) return MODEL_LABELS[id];
  return id.replace(/^cursor-/, '').replace(/-/g, ' ');
}

function splitCsvLine(line) {
  const cols = [];
  let cur = '';
  let quoted = false;
  for (const ch of line) {
    if (ch === '"') {
      quoted = !quoted;
      continue;
    }
    if (ch === ',' && !quoted) {
      cols.push(cur);
      cur = '';
      continue;
    }
    cur += ch;
  }
  cols.push(cur);
  return cols;
}

function num(value) {
  const n = Number(String(value || '').replace(/,/g, ''));
  return Number.isFinite(n) ? n : 0;
}

export function parseDashboardUsageCsv(text) {
  const raw = String(text || '')
    .replace(/^\uFEFF/, '')
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .trim();
  if (!raw) return [];
  const lines = raw.split('\n').filter(Boolean);
  if (lines.length < 2) return [];
  const header = splitCsvLine(lines[0]).map(h => h.trim().toLowerCase());
  const idx = name => header.indexOf(name.toLowerCase());
  const col = {
    date: idx('date'),
    agent: idx('cloud agent id'),
    model: idx('model'),
    cacheWrite: idx('input (w/ cache write)'),
    input: idx('input (w/o cache write)'),
    cacheRead: idx('cache read'),
    output: idx('output tokens'),
    total: idx('total tokens')
  };
  if (col.agent < 0 || col.total < 0) {
    throw new Error('usage CSV: missing Cloud Agent ID or Total Tokens column');
  }
  const events = [];
  for (const line of lines.slice(1)) {
    const cols = splitCsvLine(line);
    const agent = String(cols[col.agent] || '')
      .trim()
      .toLowerCase();
    if (!agent.startsWith('bc-')) continue;
    const tokens = emptyTokens();
    tokens.input = num(cols[col.input]);
    tokens.output = num(cols[col.output]);
    tokens.cacheRead = num(cols[col.cacheRead]);
    tokens.cacheWrite = num(cols[col.cacheWrite]);
    tokens.total =
      num(cols[col.total]) || tokens.input + tokens.output + tokens.cacheRead + tokens.cacheWrite;
    events.push({
      at: cols[col.date] || null,
      agentId: agent,
      model: String(cols[col.model] || '').trim() || 'unknown',
      tokens
    });
  }
  return events;
}

export function groupEventsByAgent(events) {
  const map = new Map();
  for (const event of events || []) {
    if (!map.has(event.agentId)) map.set(event.agentId, []);
    map.get(event.agentId).push(event);
  }
  return map;
}

function addTokens(a, b) {
  return {
    input: (a.input || 0) + (b.input || 0),
    output: (a.output || 0) + (b.output || 0),
    cacheRead: (a.cacheRead || 0) + (b.cacheRead || 0),
    cacheWrite: (a.cacheWrite || 0) + (b.cacheWrite || 0),
    total: (a.total || 0) + (b.total || 0)
  };
}

export function groupEventsByModel(events) {
  const map = new Map();
  for (const event of events || []) {
    const key = event.model || 'unknown';
    const cur = map.get(key) || { model: key, events: 0, tokens: emptyTokens() };
    cur.events += 1;
    cur.tokens = addTokens(cur.tokens, event.tokens);
    map.set(key, cur);
  }
  return [...map.values()];
}

export function matchOrchestratorModel(groups, apiTokens) {
  const want = Number(apiTokens?.total);
  if (want > 0) {
    const hit = groups.find(g => g.tokens.total === want);
    if (hit) return hit.model;
  }
  const fast = groups.filter(g => /high-fast$/i.test(g.model));
  return fast.length === 1 ? fast[0].model : null;
}

export function buildSubagents(events, apiTokens) {
  const groups = groupEventsByModel(events);
  const orchModel = matchOrchestratorModel(groups, apiTokens);
  return groups
    .map(g => {
      const orchestrator = g.model === orchModel;
      return {
        role: orchestrator ? 'orchestrator' : 'subagent',
        label: orchestrator ? 'Orchestrator' : prettyModelName(g.model),
        model: g.model,
        events: g.events,
        tokens: g.tokens
      };
    })
    .sort((a, b) => {
      if (a.role !== b.role) return a.role === 'orchestrator' ? -1 : 1;
      return (b.tokens.total || 0) - (a.tokens.total || 0);
    });
}

function apiTokensForMatch(phase) {
  if (phase.orchestratorTokens?.total != null) return phase.orchestratorTokens;
  if (phase.tokensSource === 'cursor-dashboard-csv') return null;
  return phase.tokens;
}

export function applyCsvUsageToPhase(phase, events) {
  if (!phase || !events?.length) return false;
  const apiTokens = apiTokensForMatch(phase);
  const subagents = buildSubagents(events, apiTokens);
  const tokens = events.reduce((acc, e) => addTokens(acc, e.tokens), emptyTokens());
  if (phase.orchestratorTokens == null && phase.tokens?.total != null) {
    phase.orchestratorTokens = { ...phase.tokens };
  }
  phase.tokens = tokens;
  phase.subagents = subagents;
  phase.tokensSource = 'cursor-dashboard-csv';
  const notes = phase.notes || '';
  phase.notes = notes.includes(CSV_NOTE) ? notes : notes ? `${notes} · ${CSV_NOTE}` : CSV_NOTE;
  return true;
}

export function sumEventTokens(events) {
  return (events || []).reduce((acc, event) => addTokens(acc, event.tokens), emptyTokens());
}

function phaseWindow(phase) {
  const start = Date.parse(phase?.startedAt || '');
  const end = Date.parse(phase?.completedAt || '');
  if (!Number.isFinite(start) || !Number.isFinite(end) || end < start) return null;
  return { start, end };
}

function eventWindow(events) {
  const times = (events || []).map(event => Date.parse(event.at || '')).filter(Number.isFinite);
  if (!times.length) return null;
  return { start: Math.min(...times), end: Math.max(...times) };
}

/**
 * A CSV export often starts mid-history. Replacing a phase with that slice
 * would throw away tokens already stored from an earlier, fuller export.
 */
export function csvWouldShrinkPhase(phase, events) {
  const have = Number(phase?.tokens?.total);
  if (!Number.isFinite(have) || have <= 0) return false;
  return sumEventTokens(events).total < have;
}

export function applyCsvUsageToSession(session, events, { log = () => {} } = {}) {
  const byAgent = groupEventsByAgent(events);
  let updated = 0;
  for (const phase of session.phases || []) {
    const id = String(phase.agent?.id || '').toLowerCase();
    const rows = byAgent.get(id);
    if (!rows?.length) continue;
    if (csvWouldShrinkPhase(phase, rows)) {
      log(
        `  skip ${id.slice(0, 12)}… ${phase.type}: csv ${sumEventTokens(rows).total} < stored ${phase.tokens.total}`
      );
      continue;
    }
    if (applyCsvUsageToPhase(phase, rows)) {
      updated += 1;
      log(
        `  ${id.slice(0, 12)}… ${phase.type} → ${phase.tokens.total} tokens` +
          ` (${phase.subagents.length} model row(s))`
      );
    }
  }
  updated += foldAgentsInsidePhases(session, events, { log });
  return updated;
}

/**
 * Some billed calls show up under their own bc-… instead of the parent.
 * When that whole span sits inside exactly one phase, add the rows there.
 */
export function foldAgentsInsidePhases(session, events, { log = () => {} } = {}) {
  const phaseIds = new Set(
    (session?.phases || [])
      .map(phase => String(phase.agent?.id || '').toLowerCase())
      .filter(Boolean)
  );
  let folded = 0;
  for (const [agentId, rows] of groupEventsByAgent(events)) {
    if (phaseIds.has(agentId)) continue;
    const span = eventWindow(rows);
    if (!span) continue;
    const hosts = (session.phases || []).filter(phase => {
      const window = phaseWindow(phase);
      return window && span.start >= window.start && span.end <= window.end;
    });
    if (hosts.length !== 1) {
      log(`  unmatched ${agentId.slice(0, 12)}… (${hosts.length} host phases)`);
      continue;
    }
    const phase = hosts[0];
    const extra = buildSubagents(rows, null).map(row => ({
      ...row,
      role: 'subagent',
      agentId
    }));
    phase.subagents = [...(phase.subagents || []), ...extra];
    phase.tokens = addTokens(phase.tokens || emptyTokens(), sumEventTokens(rows));
    folded += 1;
    log(
      `  folded ${agentId.slice(0, 12)}… into ${phase.type} (+${sumEventTokens(rows).total} tokens)`
    );
  }
  return folded;
}
