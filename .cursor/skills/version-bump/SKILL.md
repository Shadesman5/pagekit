---
name: version-bump
description: Determines and applies semantic version bumps based on Conventional Commits using Pagekit's custom versioning. Updates composer.json and app/system/config.php. Use after completing a task (Orchestrator workflow finished), before pushing to remote (called by push.mdc), or when the user explicitly requests a version bump.
---

# Version Bump Skill

## Custom Versioning (Modernization Phase)

```
SYSTEM-STATE . MAJOR-MINOR . PATCH
     1       .      x       .   x    ← During modernization (ROADMAP Phase 1–5)
     2       .      0       .   0    ← After modernization complete (manual decision)
```

SYSTEM-STATE stays **1** until the user decides modernization is complete.

## Bump Rules

| Commit types | Bump | Example |
|---|---|---|
| `feat:`, `refactor:`, `perf:`, `feat!:`, `fix:` (non-trivial) | MAJOR-MINOR (2nd digit, reset 3rd) | 1.1.3 → 1.2.0 |
| `docs:`, `style:`, `test:`, `build:`, `ci:`, `chore:`, `fix:` (trivial) | PATCH (3rd digit) | 1.2.0 → 1.2.1 |
| Cosmetic only (typos in comments, punctuation) | NO BUMP | 1.2.0 → 1.2.0 |

**Priority:** Highest bump wins across all commits. When in doubt, treat `fix:` as non-trivial.

## Steps

1. **Analyze** – commits since last `chore(release):` commit
2. **Determine** – bump type from table above (highest priority wins)
3. **Update** both files with new version:
   - `composer.json` → `"version": "X.Y.Z"`
   - `app/system/config.php` → `'version' => 'X.Y.Z'`
4. **Return** new version for CHANGELOG-NEW.md

## Commit types reference

See `.cursor/rules/conventional-commits.mdc` for full format specification.
