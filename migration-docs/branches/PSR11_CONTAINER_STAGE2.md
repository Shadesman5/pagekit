# PSR-11 Container Stage 2: Core Modules Call Sites

**ROADMAP Step:** 2.0.1 (Stage 2)
**Branch:** `cursor/psr-11-container-core-71d2`
**Status:** In Progress

---

## Objective

Migrate all READ access call sites in `app/modules/` from ArrayAccess (`$app['x']`) to PSR-11 (`$app->get('x')`). Service registration (WRITE via `$app['x'] = ...`) stays as ArrayAccess for now (Stage 4).

## Discovery Summary

- 17 index.php files in `app/modules/`
- ~140 `$app['x']` occurrences total
- ~18 `isset($app['x'])` occurrences → `$app->has('x')`
- 0 static shortcut calls (`App::db()`, etc.) in `app/modules/` (only in `app/system/` = Stage 3)
- 3 modules with only WRITEs (no migration needed): feed, filter, markdown

## Migration Rules

| Pattern | Before | After |
|---------|--------|-------|
| READ | `$app['x']` | `$app->get('x')` |
| EXISTS | `isset($app['x'])` | `$app->has('x')` |
| WRITE | `$app['x'] = ...` | Keep as-is (Stage 4) |

## Changes

*Updated as migration progresses...*
