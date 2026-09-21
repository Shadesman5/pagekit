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

export function applyCsvUsageToSession(session, events, { log = () => {} } = {}) {
  const byAgent = groupEventsByAgent(events);
  let updated = 0;
  for (const phase of session.phases || []) {
    const id = String(phase.agent?.id || '').toLowerCase();
    const rows = byAgent.get(id);
    if (!rows?.length) continue;
    if (applyCsvUsageToPhase(phase, rows)) {
      updated += 1;
      log(
        `  ${id.slice(0, 12)}… ${phase.type} → ${phase.tokens.total} tokens` +
          ` (${phase.subagents.length} model row(s))`
      );
    }
  }
  return updated;
}
