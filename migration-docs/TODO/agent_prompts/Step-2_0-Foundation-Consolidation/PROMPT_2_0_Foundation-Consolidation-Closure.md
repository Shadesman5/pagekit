# Step 2.0: Foundation Consolidation — Parent-Step Closure & Gap Audit

**ROADMAP:** 2.0 — Foundation Consolidation (parent step closure).
**GitHub Issue:** #181.
**Prerequisite:** Steps **2.0.0 → 2.0.8** all merged (✅) on `develop`.
**Priority:** HIGH — gates the start of Roadmap Step 2.1 (Static Analysis & Code Quality).

**Closes Phase 1 audit:** none directly. This step verifies that the Phase 1 audit closures already claimed by 2.0.0–2.0.8 are accurate (cross-check) and identifies any remaining cross-cutting Foundation-Consolidation debt that warrants new sub-steps before Phase 2.1 starts.

**Also read:**
- `.cursor/ROADMAP.md` (5 aggressive rules + tracking table)
- `migration-docs/TODO/PHASE_2_MODERNISING.md` (Step 2.0 + sub-step sections)
- `migration-docs/audits/2026/03/AUDIT_REPORT_STEP_2.0-2.0.2_2026-03-27.md` (prior partial audit — **only covered 2.0–2.0.2**, NOT 2.0.3–2.0.8)
- `migration-docs/audits/2026/02/phase1/AUDIT_REPORT_2026-02-06.md` (Phase 1 audit findings — source of truth for the "Closes Phase 1 audit:" claims in each sub-step)

---

## 0. NATURE OF THIS STEP — READ FIRST

**This is NOT a feature step.** Step 2.0 in the ROADMAP is a *parent / umbrella step*; the actual modernization work lives in 2.0.0–2.0.8 (already merged). This task is the **closure-and-gap audit** for that umbrella:

1. **Verify** every claim made by 2.0.0–2.0.8 about scope, deletions, audit closures, and "no-mercy" compliance is still true on `develop`.
2. **Detect** any Foundation-Consolidation work that was promised, deferred, missed, or surfaced *during* 2.0.x execution but never landed and never got its own ticket.
3. **Decide** per gap whether it belongs in:
   - a **new Step 2.0.X sub-step** (where X = next free integer ≥ 9), OR
   - a different existing step (2.1.x / 2.2 / 2.5 / etc.) — in which case cross-link, do NOT duplicate, OR
   - is genuinely complete and the audit just records it.
4. **Update** `.cursor/ROADMAP.md` and `migration-docs/TODO/PHASE_2_MODERNISING.md` accordingly.
5. **Open** GitHub issues for every new sub-step (one issue per new sub-step) using the `.cursor/skills/github-issue-creator/SKILL.md` skill.
6. **Write** an audit report in `migration-docs/audits/2026/{MM}/AUDIT_REPORT_STEP_2.0_FOUNDATION_CLOSURE_{YYYY-MM-DD}.md`.
7. **Close** Step 2.0 in the ROADMAP if and only if **no `must-fix-before-2.1`** gaps remain (deferred work → new sub-steps; closure may still happen). If gaps are blocking, leave 2.0 ⏳ until those new sub-steps land — but the audit report itself, the new sub-step ROADMAP rows, the new PHASE_2 sections, and the new agent-prompt skeletons are still produced **in this PR**.

> Code refactors are **out of scope**. Code-level fixes for any newly identified gaps belong in their *own* new sub-steps (2.0.9 / 2.0.10 / …), not in this PR.
> The only code touched in this PR is what `php pagekit setup` / cache regenerates and the audit machinery (markdown + agent-prompt skeletons + ROADMAP + PHASE_2 doc). No PHP source files, no JS, no LESS.

---

## 1. CONTEXT

### 1.1 Why this step exists

Step 2.0 has eight merged sub-steps (2.0.0 through 2.0.8). Each one self-reported its own "Closes Phase 1 audit: …" claims and "No-Mercy compliance" matrix. Before opening Step 2.1 (PHPStan / strict_types / CI/CD), we need a **single, holistic audit** that:

- Cross-checks each sub-step's scope on the actual `develop` HEAD (no trust in PR descriptions).
- Confirms that the Phase 1 audit cells flipped to 🛡️ (and the ones still ⚠️ — like 1.11) reflect ground truth.
- Surfaces "Out of scope" / "Deferred" items that were tagged inside sub-step prompts (`Step 2.X.Y (Name)`, `TEMPORARY BRIDGE`, `AUDIT FIX`, `BACKWARD COMPATIBILITY`) but never got a ticket.
- Catches drift between PHASE_2_MODERNISING.md and the actual code.
- Leaves the foundation in a known-clean state so 2.1.x can land cleanly on top.

The earlier audit report (`AUDIT_REPORT_STEP_2.0-2.0.2_2026-03-27.md`) only covers **2.0 → 2.0.2**. Six sub-steps (2.0.3 → 2.0.8) have no consolidated audit yet.

### 1.2 Goal

A short, evidence-based audit report plus zero-or-more new ROADMAP rows / PHASE_2 sections / `PROMPT_2_0_X_*.md` skeletons capturing every Foundation-Consolidation gap, so that Step 2.0 can be marked ✅ / 🛡️ in the ROADMAP without leaving silent debt behind.

### 1.3 Scope (what counts as "Foundation Consolidation")

Foundation Consolidation = applying the 5 Aggressive Rules **retroactively** to Phase 1 deliverables. In particular:

- **Rule 1** No compatibility layers. — search for `Bridge`, `Adapter`, `Compat`, `Shim`, `Legacy`, `Wrapper`.
- **Rule 2** No adapters. — same as above + facades and trait wrappers added to "smooth migration".
- **Rule 3** Breaking changes allowed internally. — confirm no sub-step kept old + new in parallel "for safety".
- **Rule 4** Delete over wrap. — search for commented-out legacy blocks, `@deprecated`, `*.legacy.php`, `class_alias` migration shims.
- **Rule 5** Mandatory flagging & audit debt. — every remaining TODO using ROADMAP IDs (`Step X.Y`, `TEMPORARY BRIDGE`, `AUDIT FIX`, `BACKWARD COMPATIBILITY`, `Must be refactored later`) must point at an *existing* (or newly-created-by-this-step) ROADMAP entry.

Anything that is **NOT** Foundation Consolidation belongs to other phases and is out of scope for *new* sub-steps under 2.0.X:

- Static analysis / typing → **2.1.x**
- CI/CD pipelines → **2.2**
- Docker prod → **2.3**
- Build tools (Webpack/Vite/Vue3) → **2.4 / 3.x**
- Extension safety / sandbox → **2.5**
- Security hardening / API v2 → **4.x**

If the audit finds typing / null-safety / `mixed` debt, route it to the existing 2.1.x rows (and add it under "Audit findings (Phase 1 review)" inside that step's PHASE_2 section), do NOT mint a new 2.0.X sub-step.

---

## 2. SAFETY & VERIFICATION

**Workspace root.** Standard commands:

```bash
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
php pagekit list
php pagekit setup
```

All four MUST stay green throughout this audit. If any baseline command is red on a fresh clone of `develop`, **stop and escalate** — that is itself a 2.0 closure blocker and must be resolved (likely as a new sub-step) before Step 2.0 closes.

---

## 3. AUDIT METHODOLOGY (per sub-step)

For each merged sub-step **2.0.0 → 2.0.8**, the audit subagent (via the Architect's checklist) must produce a short evidence block in the audit report covering exactly the items in §3.1–§3.6.

### 3.1 Scope verification

- Read the sub-step's row in `.cursor/ROADMAP.md`, its section in `PHASE_2_MODERNISING.md`, its agent prompt under `migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/`, and its branch doc under `migration-docs/branches/`.
- Cross-check the *claimed* deletions / modifications against `develop` HEAD with ripgrep.

### 3.2 Phase 1 audit closure verification

For every `Closes Phase 1 audit: Step 1.X` claim:
- Look up the corresponding row in `.cursor/ROADMAP.md` — is the Audit column actually `🛡️`?
- Look up the original 1.X audit findings in `migration-docs/audits/2026/02/phase1/AUDIT_REPORT_2026-02-06.md` — are *all* findings closed, or only some? Partial closures must be flagged.
- 1.11 (ORM Modernization) is currently **partial** (2.0.8 closed the User-model portion; 2.1.6 still pending). Confirm this is reflected as ⚠️ in the ROADMAP and explicitly noted in the audit report.

### 3.3 No-Mercy compliance spot-check

Run these searches across the live codebase (excluding `app/vendor/`):

```bash
rg -n "Bridge|Adapter|Compat|Shim|Legacy|Wrapper" app/ packages/ --glob "*.php" \
  --glob "!*Test.php"
rg -n "@deprecated" app/ packages/ --glob "*.php"
rg -n "class_alias" app/ packages/ --glob "*.php"
rg -n "TEMPORARY BRIDGE|AUDIT FIX|BACKWARD COMPATIBILITY|Must be refactored later" \
  app/ packages/ --glob "*.php" --glob "*.js" --glob "*.vue" --glob "*.less"
```

For every hit, classify:
- **OK / by design** (e.g. Pagekit's own `EventDispatcher`, `PrefixEventDispatcher`, `RouterAdapter` if it's a stable platform API — not a compat layer).
- **OK / tagged with valid future ROADMAP ID** (e.g. `// TODO: Must be refactored in Step 2.1.6`).
- **GAP** — needs a new sub-step or routing into an existing future step.

### 3.4 Deferred-item audit (per sub-step "Out of scope" sections)

Each sub-step prompt has an "Out of scope" / "Deferred" section. For example:
- **2.0.7** deferred `GetResponseEvent` rename → tagged `2.1.x`. Verify the rename is actually planned somewhere (currently only mentioned in 2.1.6 PHASE_2 prose). If not, flag it.
- **2.0.8** deferred extracting `evaluateBooleanExpression()` into `PermissionExpressionEvaluator` → tagged `2.5 (Extension Safety System)`. Confirm 2.5's section mentions this or accept the deferral as informational only.
- **2.0.4** PHASE_2 audit-findings list — any unchecked items?
- **2.0.6** PHASE_2 audit-findings list — any unchecked items?

For each "deferred item" found:
- If it has a clear future home (existing 2.1.x / 2.5 / etc.) → add it to that step's PHASE_2 "Audit findings (Phase 1 review)" / "Audit findings (Step 2.0.X review)" subsection (one of those headers must exist).
- If it has *no* clear future home → mint a new 2.0.X sub-step.

### 3.5 Cross-cutting Foundation-Consolidation gap search

Items the per-sub-step audit will not catch:

- **`@deprecated` markers across modules** — Rule 4 says "delete, don't deprecate". Each one is a potential gap.
- **Compatibility wrappers around modern APIs** that survived 2.0.1 (PSR-11), 2.0.3 (PSR-6 cache), 2.0.4 (Migrations), 2.0.7 (Event dispatcher).
- **Module-level config drift**: e.g. `phpunit.xml.dist` per module (resolved in 2.0.6 — verify), `composer.json` autoload entries per module (resolved in 2.0.5 — verify).
- **Doctrine ORM legacy patterns** still alive in `app/system/modules/orm/` and `app/modules/database/`: `EntityManager` singleton, `ModelServiceLocator` static service locator, `IntlServiceLocator` static service locator. These are **routed to 2.1.6**; confirm they appear in 2.1.6's PHASE_2 section under "Audit findings (Phase 1 review)" and not lost.
- **Validator / translator integration (2.0.2)**: any remaining manual-validation controllers? `MenuApiController` was flagged as 2.1.9 carryover — confirm.
- **Cache (2.0.3)**: any `CacheInterface` / `Psr6Adapter` references remain? Should be zero.
- **Migrations (2.0.4)**: `DatabaseHandler::createTable()` removed? `Version001_*` blog migration renamed to timestamp format? `MigrationServiceTest` un-skipped?
- **Event dispatcher (2.0.7)**: zero `SymfonyEventDispatcherBridge` / `symfony.event_dispatcher` references on `develop`? PHPStan baseline clean?
- **`User::hasAccess()` (2.0.8)**: zero `create_function` references in `app/` / `packages/`? PHPStan baseline entry for `function.notFound` removed?
- **Frontend (Vue 2 / UIkit)**: any `// TODO: Refactor in Phase 3 (Vue Migration)` markers added during 2.0.x that are now stale because the code path was deleted? Stale markers count as Rule-5 violations (debt without owner).

### 3.6 Documentation drift

- **`.cursor/ROADMAP.md`**: every 2.0.x row has Status = ✅, Audit ∈ {🛡️, ⚠️ (only 1.11-cross-cut is allowed at this layer)}, PR linked.
- **`PHASE_2_MODERNISING.md`**: every 2.0.x section has its "Closes Phase 1 audit:" line, its Agent Prompt path, and (if applicable) its "Audit findings" subsection.
- **`migration-docs/branches/`**: a branch doc exists per sub-step (file naming convention: `step-2-0-X-*.md` or `*_MIGRATION.md` for older sub-steps). Missing branch docs are a documentation-debt gap.
- **`README.md`**: any references to features / commands / file paths that 2.0.x changed and that README still describes the old way? (E.g. cache, migrations, event dispatcher.)
- **`AGENTS.md`**: same check — any pitfalls or service mappings that 2.0.x invalidated?
- **`CHANGELOG-NEW.md`**: every 2.0.x entry has a coherent section, no missing versions in the chain `1.2.5 → 1.2.13`.

---

## 4. AUDIT OUTPUT FORMAT

The audit subagent produces ONE markdown file at:

```
migration-docs/audits/2026/{MM}/AUDIT_REPORT_STEP_2.0_FOUNDATION_CLOSURE_{YYYY-MM-DD}.md
```

Where `{YYYY-MM-DD}` is the audit date (today) and `{MM}` is the two-digit month. Use the format of `migration-docs/audits/2026/03/AUDIT_REPORT_STEP_2.0-2.0.2_2026-03-27.md` as a reference (Executive Summary → Scope table → Detailed Findings Per Step → Cross-Cutting Verification → Dependency Chain → PR Timeline → Recommendations → Final Test Summary → Conclusion).

Mandatory sections:

1. **Executive Summary** — one paragraph, plus a "Closure Verdict" table:

   | ID    | Sub-step                              | Audit ground-truth | New sub-step needed? |
   |-------|---------------------------------------|--------------------|----------------------|
   | 2.0.0 | Controller Attributes                 | 🛡️ / ⚠️ / ❌       | none / 2.0.X / 2.1.x |
   | 2.0.1 | PSR-11 Container Modernization        | …                  | …                    |
   | …     | …                                     | …                  | …                    |
   | 2.0.8 | User::hasAccess() Hotfix              | …                  | …                    |

2. **Per-sub-step evidence blocks** (2.0.0 → 2.0.8) — each ≤ 20 lines:
   - Scope verified (✅ / ❌ + ripgrep evidence)
   - Phase 1 closure claims verified (✅ / ⚠️ partial / ❌)
   - No-Mercy spot-check (✅ / list of suspect hits)
   - Deferred items still tracked (✅ / list of orphaned items)

3. **Cross-Cutting Verification** — global ripgrep tables (one row per pattern, plus classification).

4. **Gap List** — one row per gap, with the proposed disposition:

   | # | Gap (one line)                           | Disposition                | Target               |
   |---|------------------------------------------|----------------------------|----------------------|
   | 1 | E.g. `DatabaseHandler::createTable()` still present | new sub-step             | **2.0.9 (DDL Hardening)** |
   | 2 | E.g. `MenuApiController` manual validation | route to existing step    | 2.1.9 (already listed) |
   | 3 | …                                        | …                          | …                    |

5. **New Sub-Step Proposals** — full skeleton for each new 2.0.X (see §5).

6. **PHASE_2 / ROADMAP / Issue Updates** — exact diffs proposed (in this PR).

7. **Closure Verdict** — one of:
   - ✅ Step 2.0 can be closed (`✅` Status + `🛡️` Audit) in this PR. New sub-steps (if any) are non-blocking and may land later.
   - ⚠️ Step 2.0 stays `⏳` until new sub-steps 2.0.9 / 2.0.10 / … land. Document the dependency in `PHASE_2_MODERNISING.md`.

---

## 5. NEW SUB-STEP CREATION PROCEDURE

For every gap that warrants a new sub-step, the Architect produces (in this PR):

### 5.1 Numbering rule

Next free integer ≥ 9. So the first new sub-step is **2.0.9**, next **2.0.10**, etc. Never reuse a number; never re-letter (no `2.0.8a`).

### 5.2 ROADMAP row

Insert immediately after the last existing 2.0.X row (currently 2.0.8) in `.cursor/ROADMAP.md`:

```markdown
| 2.0.9  | ↳ {Concise Task Name}                 | ⏳     | ⏳    | #{ISSUE} | -       |
```

The Audit column is `⏳` (not `⚠️`) because the work has not started yet.

### 5.3 PHASE_2 section

Insert in `migration-docs/TODO/PHASE_2_MODERNISING.md` immediately after the existing **Step 2.0.8** subsection, before the **Step 2.1** header. Required fields (verbatim labels):

```markdown
### Step 2.0.9: {Concise Task Name}

- **Goal**: {one sentence}
- **Prerequisite**: Step 2.0.8 (or whichever earlier step is the real prerequisite — usually 2.0.{N-1})
- **Priority**: {LOW / MEDIUM / HIGH}
- **Context**: {why this is foundation-consolidation; why it was missed by the original 2.0.X plan; reference the 2026-04 closure audit}
- **Closes Phase 1 audit**: {Step 1.X (Name) ⚠️ → 🛡️} — or `none` if the gap is purely a 2.0.x leftover.
- **Tasks**:
  - {bulleted, atomic, 5–15 items}
- **Result**: {one sentence}
- **Risk**: {Low / Medium / High}
- **Affected Files**: {ripgrep-derived list}
- **Agent Prompt**: `migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/PROMPT_2_0_9_{Slug}.md`

---
```

### 5.4 Agent prompt skeleton

Create `migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/PROMPT_2_0_9_{Slug}.md` (and analogously for 2.0.10, 2.0.11, …) using the structure of an existing prompt — `PROMPT_2_0_7_Event-Bridge-Removal.md` or `PROMPT_2_0_8_User-hasAccess-Hotfix.md` are good templates.

Mandatory sections in each new prompt:
1. Title (`# Step 2.0.X: {Name}`)
2. ROADMAP / GitHub Issue / Prerequisite / Priority header block
3. `Closes Phase 1 audit:` line (or "none" with rationale)
4. `Also read:` line (`.cursor/ROADMAP.md`, relevant PHASE_2 section)
5. **§1 CONTEXT** — bug / debt description, current code excerpt, why this is needed
6. **§2 SAFETY & VERIFICATION** — standard test commands
7. **§3 IMPLEMENTATION** — concrete steps, file paths, before/after diffs where applicable
8. **§4 TESTS** — new + existing tests that must pass
9. **§5 SUCCESS CRITERIA** — actionable, ripgrep-verifiable bullet list
10. **§6 OUT OF SCOPE** — what NOT to touch; defer to which step
11. `**End of prompt.**`

Skeletons may leave `§3` and `§4` thin (≤ 10 bullets) — the Architect for that future step will flesh them out. The skeleton must be detailed enough that a downstream Architect can produce a usable ticket plan from it.

### 5.5 GitHub issue

Use the `.cursor/skills/github-issue-creator/SKILL.md` skill to open one issue per new sub-step on `Shadesman5/pagekit`. Required fields:

- **Title**: `Step 2.0.X: {Concise Task Name}`
- **Labels**: `phase-2`, plus `migration` *and / or* the relevant area label (`backend`, `database`, `module`, `theme`, `frontend`, `migration` per `.cursor/rules/github-labels.mdc`)
- **Milestone**: `Phase 2: Developer Experience`
- **Body**: short markdown summary linking to the new PHASE_2 section + new agent prompt path; reference the 2.0 closure audit report; mention the parent issue (#181)
- **Parent issue**: link to **#181** as parent (sub-issue relationship)

After issue creation, write the issue number into the new ROADMAP row and into the new PHASE_2 section's `Agent Prompt` line preamble (replace placeholder `#{ISSUE}`).

### 5.6 No code in this PR

The new sub-steps are **paper only** in this PR (ROADMAP row + PHASE_2 section + prompt skeleton + GitHub issue). Their actual implementation happens in their own future PRs. This keeps the closure PR small and reviewable.

---

## 6. EXISTING-STEP UPDATES (when a gap routes elsewhere)

If a gap belongs to an existing future step (most likely 2.1.6 or 2.1.9), update *that step's* PHASE_2 subsection only:

1. Append the gap to that step's `**Audit findings (Phase 1 review):**` (or create the subsection if missing) under `migration-docs/TODO/PHASE_2_MODERNISING.md`.
2. Add a one-liner pointer in the audit report's "Gap List" table referencing the change.
3. Do **NOT** create a new agent prompt — the existing step's prompt covers it.
4. Do **NOT** open a new GitHub issue — the existing step's issue covers it; if needed, add a comment on that issue referencing the closure audit.

---

## 7. ROADMAP TABLE FINAL STATE (post-this-PR)

After this step lands, the ROADMAP tracking table for Step 2.0 must look approximately like:

```markdown
| 2.0    | **Foundation Consolidation**          | ✅ or ⏳ | 🛡️ or ⏳ | #181  | #{THIS_PR} |
| 2.0.0  | ↳ Controller Attributes               | ✅      | 🛡️      | #142  | #111       |
| 2.0.1  | ↳ PSR-11 Container Modernization      | ✅      | 🛡️      | #145  | #174       |
| 2.0.1a–e | ↳ (sub-stages, unchanged)           | ✅      | 🛡️      | …     | …          |
| 2.0.2  | ↳ Validator-Translator Integration    | ✅      | 🛡️      | #146  | #175       |
| 2.0.3  | ↳ Cache API Full Modernization        | ✅      | 🛡️      | #179  | #187       |
| 2.0.4  | ↳ Package/Migration System Redesign   | ✅      | 🛡️      | #180  | #189       |
| 2.0.5  | ↳ Composer & Autoload Hygiene         | ✅      | 🛡️      | #182  | #192       |
| 2.0.6  | ↳ Test Infrastructure Cleanup         | ✅      | 🛡️      | #183  | #193       |
| 2.0.7  | ↳ Event Dispatcher Bridge Removal     | ✅      | 🛡️      | #184  | #195       |
| 2.0.8  | ↳ Hotfix: `create_function()` in User | ✅      | 🛡️      | #185  | #197       |
| 2.0.9  | ↳ {New sub-step from gap audit}       | ⏳      | ⏳      | #?    | -          |
| 2.0.10 | ↳ {Next new sub-step, if any}         | ⏳      | ⏳      | #?    | -          |
| …      | …                                     | …       | …       | …     | …          |
```

Closure rules:
- If **zero** new sub-steps: 2.0 row → `✅` / `🛡️`.
- If **one or more** new sub-steps and any of them is **must-fix-before-2.1**: 2.0 row stays `⏳` / `⏳`, and the new sub-steps are listed as prerequisites for Step 2.1 in the relevant PHASE_2 entries.
- If **one or more** new sub-steps but all are non-blocking: 2.0 row → `✅` / `🛡️`, and the new sub-steps land later in their own PRs.

The "must-fix-before-2.1" decision is the Architect's call, documented in the audit report's Conclusion.

---

## 8. DEFINITION OF DONE (for this PR)

- [ ] Audit report exists at `migration-docs/audits/2026/{MM}/AUDIT_REPORT_STEP_2.0_FOUNDATION_CLOSURE_{YYYY-MM-DD}.md` and follows the §4 format.
- [ ] Per-sub-step evidence blocks for **all** of 2.0.0, 2.0.1 (incl. 2.0.1a–e), 2.0.2, 2.0.3, 2.0.4, 2.0.5, 2.0.6, 2.0.7, 2.0.8.
- [ ] Cross-cutting ripgrep tables present and classified.
- [ ] Gap List present (may be empty if zero gaps, but the section header must exist with the explicit phrase "no gaps detected").
- [ ] For every "new sub-step" gap:
  - [ ] ROADMAP row inserted with correct Issue number.
  - [ ] PHASE_2 section inserted with all mandatory fields.
  - [ ] Agent-prompt skeleton file exists at `agent_prompts/Step-2_0-Foundation-Consolidation/PROMPT_2_0_X_*.md`.
  - [ ] GitHub issue opened with parent-issue link to #181.
- [ ] For every "route to existing step" gap: target step's PHASE_2 section updated.
- [ ] If closure verdict is ✅ on 2.0:
  - [ ] ROADMAP row 2.0 updated to `✅` / `🛡️` with this PR linked.
  - [ ] ROADMAP header `Current Step` advanced to `2.1.2` (since 2.1.1 is already done).
  - [ ] If closure verdict is ⚠️: ROADMAP row 2.0 stays `⏳` / `⏳` and Current Step pointer advances to the **first new sub-step** (e.g. `2.0.9`).
- [ ] `./app/vendor/bin/phpunit` green.
- [ ] `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M` green.
- [ ] `php pagekit list` green.
- [ ] `php pagekit setup` green.
- [ ] CHANGELOG-NEW.md has a `## Pagekit X.Y.Z - Foundation Consolidation Closure ({DATE})` section.
- [ ] Branch doc at `migration-docs/branches/step-2-0-foundation-closure.md` exists, summarising the audit verdict and any new sub-steps.
- [ ] Version bumped via `.cursor/skills/version-bump/SKILL.md` (third-digit patch — this PR is `docs`/`chore` heavy).

---

## 9. ORCHESTRATOR / ARCHITECT GUIDANCE (Roadmap Step → Checklist)

This task is unusually **discovery-heavy**. The Architect's checklist should reflect that. Suggested structure (the Architect MAY adjust):

1. Pre-flight baseline (PHPUnit / PHPStan / `pagekit list` / `pagekit setup` all green on `develop`).
2. Sub-step audit loop — one Checklist Step per audited sub-step (eight steps total: 2.0.0, 2.0.1, 2.0.2, 2.0.3, 2.0.4, 2.0.5, 2.0.6, 2.0.7, 2.0.8). Each produces an evidence block in the audit report.
3. Cross-cutting ripgrep sweep — one Checklist Step.
4. Gap list assembly + disposition decisions — one Checklist Step.
5. For each new sub-step (zero or more): one Checklist Step that creates ROADMAP row + PHASE_2 section + prompt skeleton + GitHub issue.
6. For each "route to existing step" gap: one Checklist Step updating the target PHASE_2 subsection.
7. Audit-report finalisation (Executive Summary + Conclusion + Closure Verdict).
8. Branch doc + CHANGELOG-NEW + version bump + ROADMAP closure (or non-closure) update — one Checklist Step each.
9. Final test gate (PHPUnit + PHPStan + `pagekit setup` + `pagekit list` + Playwright E2E `installation` + `authentication` + `dashboard`).

Per-step gate is **PHPUnit + PHPStan** as usual. Final gate adds `pagekit setup` + `pagekit list` + Playwright (chromium-only per `AGENTS.md`).

The Refactorer MUST NOT touch PHP / JS / LESS source files in this PR. If the audit report concludes that a code fix is genuinely "must-fix-now" (e.g. another fatal-on-PHP-8 bug like 2.0.8 was), abort this closure PR and open a hotfix sub-step PR first; this audit can then resume.

---

## 10. NON-GOALS (out of scope for this step)

- ❌ Running PHPStan at level 6/7/8 — that is 2.1.4 / 2.1.5 / 2.1.6.
- ❌ Adding `declare(strict_types=1)` anywhere — that is 2.1.3.
- ❌ Refactoring `EntityManager` singleton, `ModelServiceLocator`, `IntlServiceLocator` — those are 2.1.6.
- ❌ Implementing CI/CD workflows — that is 2.1.2 / 2.2.
- ❌ Vue 2 / UIkit / frontend modernization — that is Phase 3.
- ❌ Re-running 2.0.x sub-steps — they are merged. If a sub-step delivered something incorrect, the fix is a *new* sub-step, not an amend.

---

## 11. SUCCESS CRITERIA SUMMARY

- [ ] Single, evidence-based audit report covering all 2.0.x sub-steps.
- [ ] All Foundation-Consolidation gaps either closed (Phase 1 audit cells correct), routed (existing future-step PHASE_2 section updated), or scheduled (new 2.0.X sub-step created with ROADMAP + PHASE_2 + prompt + issue).
- [ ] ROADMAP table in a deterministic, post-2.0 state per §7.
- [ ] No code in `app/`, `packages/`, or frontend assets touched by this PR (audit machinery and docs only).
- [ ] All test gates green.
- [ ] `Closes #181` in PR body and `<!-- metadata -->` block.

---

**End of prompt.**
