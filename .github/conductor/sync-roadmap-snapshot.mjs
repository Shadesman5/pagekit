#!/usr/bin/env node
// Sync `.cursor/ROADMAP.md` tracking table → JSON for GitHub Pages dashboard.
//
// Usage: node .github/conductor/sync-roadmap-snapshot.mjs [--dry-run]

import { readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "../..");
const ROADMAP = join(ROOT, ".cursor/ROADMAP.md");
const OUT = join(ROOT, ".github/conductor/metrics/roadmap-snapshot.json");
const DRY_RUN = process.argv.includes("--dry-run");
const REPO = process.env.GITHUB_REPOSITORY || "Shadesman5/pagekit";
const SERVER = (process.env.GITHUB_SERVER_URL || "https://github.com").replace(/\/$/, "");

export function parseRoadmapTable(md) {
  const version = md.match(/\*\*Current Version\*\*:\s*([\d.]+)/)?.[1] ?? null;
  const currentStep = md.match(/\*\*Current Step\*\*:\s*([\d.]+[a-z]?)/i)?.[1] ?? null;

  const tableStart = md.indexOf("## **📊 TRACKING TABLE**");
  if (tableStart < 0) return { version, currentStep, rows: [] };

  const section = md.slice(tableStart);
  const lines = section.split("\n");
  const rows = [];

  for (const line of lines) {
    if (!line.trimStart().startsWith("|")) continue;
    if (/^\|\s*:?-{2,}/.test(line)) continue;
    if (/^\|\s*ID\s*\|/i.test(line)) continue;

    const cells = line
      .split("|")
      .slice(1, -1)
      .map((c) => c.trim());
    if (cells.length < 6) continue;

    const id = cells[0].replace(/\*\*/g, "").trim();
    if (!/^[\d.]+[a-z]?$/i.test(id)) continue;

    const issue = parseRef(cells[4]);
    const pr = parsePr(cells[5]);

    rows.push({
      id,
      name: cells[1].replace(/\*\*/g, "").trim(),
      status: cells[2].trim(),
      audit: cells[3].trim(),
      issue: issue.num,
      issueLabel: issue.label,
      issueUrl: issue.num ? `${SERVER}/${REPO}/issues/${issue.num}` : null,
      pr: pr.nums,
      prLabel: pr.label,
      prUrl: pr.nums?.length === 1 ? `${SERVER}/${REPO}/pull/${pr.nums[0]}` : null,
    });
  }

  return { version, currentStep, rows };
}

function parseRef(cell) {
  const m = cell.match(/#(\d+)/);
  if (!m) return { num: null, label: cell === "-" ? "—" : cell };
  return { num: Number(m[1]), label: cell };
}

function parsePr(cell) {
  if (!cell || cell === "-") return { nums: null, label: "—" };
  const nums = [...cell.matchAll(/#(\d+)/g)].map((m) => Number(m[1]));
  return { nums: nums.length ? nums : null, label: cell };
}

function main() {
  const md = readFileSync(ROADMAP, "utf8");
  const parsed = parseRoadmapTable(md);
  const snapshot = {
    schemaVersion: 1,
    updatedAt: new Date().toISOString(),
    source: ".cursor/ROADMAP.md",
    version: parsed.version,
    currentStep: parsed.currentStep,
    rows: parsed.rows,
  };

  if (DRY_RUN) {
    console.log(JSON.stringify(snapshot, null, 2));
    return;
  }

  writeFileSync(OUT, `${JSON.stringify(snapshot, null, 2)}\n`);
  console.log(`Wrote ${parsed.rows.length} roadmap rows → ${OUT}`);
}

const isMain = process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url;
if (isMain) main();
