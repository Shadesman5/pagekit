---
name: push
description: Commit, optionally bump the Pagekit product version, push, and open a PR with metadata. Use when the user asks to commit and create a PR, finish a feature branch, or when Orchestrator Finalize needs release docs. Do not use for Conductor/Cursor-rules/tooling-only changes that should not bump the CMS version.
---

# Push

Procedure for landing work on GitHub. **Do not merge.**

Version numbers and CHANGELOG belong to the **CMS product**, not to Cursor/Conductor tooling. Run `.cursor/skills/version-bump/SKILL.md` — it is the bump gate. If it reports `NO BUMP`, skip version, CHANGELOG, and ROADMAP `Current Version`.

## 1. Branch

New product work: `feature/<name>` from `develop`. Conductor already owns `feature/<slug>` for V2 tickets — do not rename it.

## 2. Commits

Conventional Commits (`.cursor/rules/conventional-commits.mdc`). Do not commit secrets or `tmp/` inspect dumps.

## 3. Version + CHANGELOG

Follow the version-bump skill.

- **Bump** → `app/system/config.php`, then a `CHANGELOG-NEW.md` section (headings: `.cursor/agents/doc-writer.md`). Commit `chore(release): bump version to X.Y.Z`.
- **NO BUMP** → do not touch `config.php`, CHANGELOG, or ROADMAP `Current Version`.

## 4. ROADMAP

Only when this PR **closes an executed Roadmap Step** (Orchestrator Finalize):

- Header: `Current Version` (if bumped) and `Current Step` → next step
- That row: Status `⏳` → `✅`, Audit `⏳` → `🛡️`, PR `-` → `#YYY`
- Phase 1 audit closures from `Closes Phase 1 audit:` (skip `(partial)` / unmet `requires also Step Y`)
- Commit `docs(roadmap): close Step X.Y.Z …`

A product hotfix that is not a Roadmap Step: bump `Current Version` only if the skill bumped. Do **not** move `Current Step` or flip a tracking row.

Tooling / docs / planning: do not edit ROADMAP.

## 5. README

Update `README.md` when **product** surfaces changed: PHP/Node constraint, Composer/pnpm runtime deps, Docker/compose/Dockerfile, installer commands, badges. Commit `docs(readme): …`. Skip for `.cursor/` / Conductor-only diffs.

## 6. Push

- On `develop` (protected) → this feature branch, then PR
- Already on a feature branch → `git push -u origin HEAD`

## 7. PR

Title is a Conventional Commits subject (`.cursor/rules/conventional-commits.mdc`). Pass it
explicitly:

`gh pr create --base develop --title "<title>"` (or the given base).

Do **not** omit `--title` (GitHub would use the latest commit, often a docs/chore line). Do
**not** copy the GitHub issue title — `Step X.Y: Name` is for issues only.

When this PR **closes a ROADMAP step**, append ` (Step X.Y)` so `import-v1-metrics.yml` can
read the id from the title (title first, then body). The suffix is extra; it does not count
against the 50-character description:

`feat(extension): extension safety and fault isolation (Step 2.7)`

Tooling / Conductor / Cursor-rules PRs that do **not** close a ROADMAP row: conventional
title only — no ` (Step …)` and no `v1-metrics`.

Body ends with:

```markdown
<!-- metadata
labels: phase-2, bug, migration
milestone: Phase 2: Developer Experience
closes: #42
-->
```

Labels: 1 phase + 1 type + 1–2 area (`.cursor/rules/github-labels.mdc`). Visible `Closes #X` when closing issues.

**`v1-metrics` only** for cursor.com/agents Orchestrator PRs (full-ticket V1). Conductor V2
Finalize still uses the ` (Step X.Y)` suffix when it closes a real ROADMAP row, but not the
label — XL tokens import on the tick, not at merge. Never invent a Step id for tooling.

## 8. Do not merge
