---
name: version-bump
description: Determines and applies semantic version bumps based on Conventional Commits using Pagekit's custom versioning. Updates app/system/config.php (single source of truth — composer.json no longer carries the version). Use after completing an executed Roadmap Step (Orchestrator Finalize / push.mdc), or when the user explicitly requests a version bump. Do NOT bump for docs/planning-only PRs (see NO BUMP rules).
---

# Version Bump Skill

## Custom Versioning (Modernization Phase)

```
SYSTEM-STATE . MAJOR . MINOR-PATCH
     1       .   x   .     x       ← During modernization (ROADMAP Phase 1–5)
     2       .   0   .     0       ← After modernization complete (standard MAJOR.MINOR.PATCH from here)
```

SYSTEM-STATE stays **1** until the user decides modernization is complete.

## Bump Rules

| Trigger | Bump | Example |
|---|---|---|
| ROADMAP milestone complete (Phase done, major step like PSR-11 finished) | MAJOR (2nd digit, reset 3rd) | 1.1.5 → 1.2.0 |
| Any `feat:`, `fix:`, `refactor:`, `perf:`, `test:`, `build:`, `ci:`, `chore:` that changes runtime, tests, or runnable tooling | MINOR-PATCH (3rd digit) | 1.1.2 → 1.1.3 |
| Cosmetic only (typos in comments, punctuation) | **NO BUMP** | 1.1.2 → 1.1.2 |
| **Docs / planning only** (see below) | **NO BUMP** | 1.1.2 → 1.1.2 |

**MAJOR bump is a manual decision** — the user or Architect decides when a milestone warrants it. All regular *delivery* work bumps the 3rd digit.

### NO BUMP + NO CHANGELOG (docs / planning)

Skip this skill entirely (leave `app/system/config.php` and `CHANGELOG-NEW.md` unchanged) when the PR is **only** planning or documentation, for example:

- ROADMAP / `PHASE_*_MODERNISING.md` reorder, wording, or open-step redefinition
- Agent prompts, tickets/plans, analysis notes under `migration-docs/`
- Cursor rules, skills, workflow docs
- Retargeting `TODO` / Step-ID comments without behaviour change

A conventional `docs:` commit type alone does **not** justify a release. Bump + CHANGELOG belong to **executed** Roadmap Steps and real product/tooling changes (see `push.mdc` step 2 exception).

## Steps

1. **Gate** – If the change set matches **NO BUMP (docs / planning)** above → stop; report `NO BUMP` and do not edit version or CHANGELOG.
2. **Analyze** – commits since last `chore(release):` commit
3. **Determine** – bump type from table above (highest priority wins)
4. **Update** the single source of truth:
   - `app/system/config.php` → `'version' => 'X.Y.Z'`
   - **Do NOT** add a `"version"` field to `composer.json` — it was removed intentionally; Pagekit reads the version from `app/system/config.php` only.
5. **Return** new version for CHANGELOG-NEW.md

## Commit types reference

See `.cursor/rules/conventional-commits.mdc` for full format specification.
