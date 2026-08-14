---
name: version-bump
description: Bumps the Pagekit CMS version in app/system/config.php. Use after an executed Roadmap Step (Orchestrator Finalize), when product runtime/tests change, or when the user explicitly requests a bump. Do not bump for Cursor rules, Conductor, workflow docs, tickets, or other tooling that is not the CMS product.
---

# Version Bump

Single source of truth: `app/system/config.php` → `'version' => 'X.Y.Z'`. Do **not** add `version` to `composer.json`.

## Scheme (modernization)

```
SYSTEM-STATE . MAJOR . MINOR-PATCH
     1       .   x   .     x       ← Phases 1–5
     2       .   0   .     0       ← after modernization (then normal MAJOR.MINOR.PATCH)
```

SYSTEM-STATE stays **1** until the user says modernization is done. **MAJOR** (2nd digit) is a manual/Architect decision. Regular product delivery bumps the **3rd** digit.

## Product vs tooling

| | Examples | Bump? |
|---|---|---|
| **Product** | `app/`, `packages/`, themes, installer, shipped `public/` sources, tests of that code, Docker image/entrypoint that runs the CMS | **Yes** (or Finalize after an executed Roadmap Step that shipped product) |
| **Tooling** | `.cursor/` rules, skills, agents, `AGENTS.md`, Conductor (`.github/conductor/`, `conductor.yml`), agent prompts, tickets, ROADMAP/PHASE planning, metrics | **No** |

`ci:` / `chore:` / `fix(conductor):` on tooling is still **NO BUMP**. Commit types do not override this table.

## NO BUMP (stop; do not edit version or CHANGELOG)

- The change set is **only** tooling or docs/planning (table above)
- ROADMAP / `PHASE_*` reorder or wording, tickets/plans, analysis notes under `migration-docs/`
- TODO / Step-ID comment retargets with no behaviour change
- Cosmetic only (comment typos, punctuation)
- User said no bump

A `docs:` commit is not a release.

## Bump (3rd digit)

Any product `feat:` / `fix:` / `refactor:` / `perf:` / `test:` / `build:` that changes runtime, product tests, or the shipped image — or Finalize after an **executed** Roadmap Step that delivered product. User explicit request always wins.

## Steps

1. **Gate** — tooling/docs/planning → report `NO BUMP` and stop.
2. **Analyze** — commits since last `chore(release):`.
3. **Determine** — 3rd digit unless the user/Architect asked for MAJOR.
4. **Write** `app/system/config.php` only.
5. **Return** the new version for CHANGELOG (doc-writer / push skill).
