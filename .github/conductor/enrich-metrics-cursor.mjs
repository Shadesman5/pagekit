#!/usr/bin/env node
// Fill missing phase token counts via Cursor Cloud Agents API (cancelled GHA jobs, backfill gaps).
//
// Usage:
//   CURSOR_API_KEY=… BRANCH=conductor-metrics node .github/conductor/enrich-metrics-cursor.mjs [--dry-run] [--session ID] [--refresh-timing] [--push] [--copy-local]
//
// With --push, set BRANCH to conductor-metrics (never the feature branch or protected develop).
// Safe to re-run — only phases with tokens.total == null and a known agent.id are updated.

import { execSync, execFileSync } from "node:child_process";
import { readFileSync, writeFileSync, existsSync, readdirSync, mkdirSync, cpSync } from "node:fs";
import { join } from "node:path";
import {
  INDEX_PATH,
  SESSIONS_DIR,
  METRICS_DIR,
  SCHEMA_VERSION,
  createCursorClient,
  enrichSessionTokensFromCursor,
  phaseNeedsMetricsRefresh,
} from "./metrics.mjs";

const DRY_RUN = process.argv.includes("--dry-run");
const DO_PUSH = process.argv.includes("--push");
const COPY_LOCAL = process.argv.includes("--copy-local");
const REFRESH_TIMING = process.argv.includes("--refresh-timing");
const SESSION_FILTER = getArg("--session");

function getArg(name) {
  const i = process.argv.indexOf(name);
  return i >= 0 ? process.argv[i + 1] : null;
}

function readJson(path, fallback = null) {
  if (!existsSync(path)) return fallback;
  return JSON.parse(readFileSync(path, "utf8"));
}

function writeJson(path, data) {
  writeFileSync(path, `${JSON.stringify(data, null, 2)}\n`, "utf8");
}

function sh(cmd) {
  return execSync(cmd, { stdio: ["ignore", "pipe", "pipe"], encoding: "utf8" }).trim();
}

function listSessionIds(index) {
  if (SESSION_FILTER) return [SESSION_FILTER];
  const ids = new Set();
  for (const entry of Object.values(index?.steps || {})) {
    for (const id of entry.sessionIds || []) ids.add(id);
  }
  if (!ids.size && existsSync(SESSIONS_DIR)) {
    for (const name of readdirSync(SESSIONS_DIR)) {
      if (name.endsWith(".json")) ids.add(name.replace(/\.json$/, ""));
    }
  }
  return [...ids];
}

function countMissing(session) {
  return (session.phases || []).filter((p) => p.agent?.id && p.tokens?.total == null).length;
}

function countMissingTiming(session) {
  return (session.phases || []).filter((p) => phaseNeedsMetricsRefresh(p, { refreshTiming: REFRESH_TIMING })).length;
}

function copyToLocalPreview() {
  const dest = "docs-site/data/conductor-metrics";
  mkdirSync(dest, { recursive: true });
  cpSync(METRICS_DIR, dest, { recursive: true, force: true });
  console.log(`Copied ${METRICS_DIR}/ → ${dest}/ (local mkdocs preview)`);
}

function pushMetrics(branch) {
  if (!branch) {
    console.log("  push: skipped — no BRANCH env");
    return;
  }
  if (!/^[A-Za-z0-9._/-]+$/.test(branch)) {
    console.log("  push: skipped — invalid BRANCH");
    return;
  }
  try {
    sh('git config user.email "41898282+github-actions[bot]@users.noreply.github.com"');
    sh('git config user.name "github-actions[bot]"');
  } catch {
    /* identity may already exist */
  }
  sh(`git add ${METRICS_DIR}/`);
  try {
    sh("git diff --cached --quiet");
    console.log("  push: no metric changes to commit");
    return;
  } catch {
    sh('git commit -m "chore(metrics): enrich tokens via Cursor API"');
    // Concurrent conductor jobs may push metrics first — rebase before push.
    sh(`git fetch origin ${branch}`);
    try {
      sh(`git rebase origin/${branch}`);
    } catch (e) {
      try { sh("git rebase --abort"); } catch { /* ignore */ }
      throw new Error(`metrics enrich rebase onto origin/${branch} failed: ${e.message}`);
    }
    execFileSync("git", ["push", "origin", branch], { stdio: "inherit" });
    console.log(`  push: committed and pushed to ${branch}`);
    // GITHUB_TOKEN pushes do not trigger workflows — rebuild Pages from develop
    // (pages-deploy overlays metrics from conductor-metrics).
    try {
      execFileSync("gh", ["workflow", "run", "pages-deploy.yml", "--ref", "develop"], { stdio: "inherit" });
      console.log("  push: dispatched pages-deploy.yml (ref=develop)");
    } catch (e) {
      console.log(`  push: pages-deploy dispatch skipped (${e.message})`);
    }
  }
}

async function main() {
  const apiKey = process.env.CURSOR_API_KEY;
  if (!apiKey) {
    console.error("CURSOR_API_KEY is required (GitHub Actions secret or local env).");
    process.exit(1);
  }

  const client = createCursorClient(apiKey);
  const index = readJson(INDEX_PATH, { schemaVersion: SCHEMA_VERSION, updatedAt: null, steps: {} });
  const sessionIds = listSessionIds(index);

  console.log(
    `Cursor enrich: ${sessionIds.length} session(s)${DRY_RUN ? " (dry-run)" : ""}${REFRESH_TIMING ? " (refresh-timing)" : ""}`,
  );

  let totalEnriched = 0;
  let sessionsTouched = 0;

  for (const sessionId of sessionIds) {
    const path = join(SESSIONS_DIR, `${sessionId}.json`);
    const session = readJson(path);
    if (!session) {
      console.log(`  skip ${sessionId.slice(0, 8)}… — file missing`);
      continue;
    }

    const missingTokens = countMissing(session);
    const missingTiming = countMissingTiming(session);
    if (!missingTokens && !missingTiming) continue;

    console.log(
      `  ${sessionId.slice(0, 8)}… step=${session.roadmapStepId || "?"} — ${missingTokens} token gap(s), ${missingTiming} timing gap(s)`,
    );
    const { session: updated, enriched, timingEnriched } = await enrichSessionTokensFromCursor(session, client, {
      log: (msg) => console.log(msg),
      refreshTiming: REFRESH_TIMING,
    });

    if (!enriched && !timingEnriched) continue;

    totalEnriched += enriched + timingEnriched;
    sessionsTouched += 1;

    if (!DRY_RUN) {
      writeJson(path, updated);
    }
  }

  if (!DRY_RUN && sessionsTouched) {
    index.updatedAt = new Date().toISOString();
    index.cursorEnrich = {
      at: index.updatedAt,
      phasesEnriched: totalEnriched,
      sessionsTouched,
    };
    writeJson(INDEX_PATH, index);
  }

  console.log(
    `\nDone: ${totalEnriched} phase(s) enriched across ${sessionsTouched} session(s)${DRY_RUN ? " (dry-run, no files written)" : ""}.`,
  );

  if (!DRY_RUN && sessionsTouched) {
    if (COPY_LOCAL) copyToLocalPreview();
    if (DO_PUSH) pushMetrics((process.env.BRANCH || "").trim());
  } else if (COPY_LOCAL && !DRY_RUN) {
    copyToLocalPreview();
  }
}

main().catch((e) => {
  console.error(e.stack || e.message);
  process.exit(1);
});
