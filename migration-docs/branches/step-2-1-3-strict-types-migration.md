# Step 2.1.3 — `strict_types` Migration

**Branch:** `cursor/step-2-1-3-strict-types-migration-adaa`
**ROADMAP Step:** 2.1.3 (Static Analysis & Code Quality — `strict_types` Migration)
**GitHub Issue:** [#150](https://github.com/Shadesman5/pagekit/issues/150)
**Pull Request:** [#201](https://github.com/Shadesman5/pagekit/pull/201)
**Status:** ✅ Complete — Ready for Review
**Date:** 2026-04-30

---

## 🎯 Overview

Step 2.1.3 enforces **PHP 8 strict typing** across the entire Pagekit codebase. Every PHP
file under `app/modules/`, `app/system/`, `app/installer/`, `app/console/`, `packages/`,
plus the workspace root files (`index.php`, `autoload.php`, `app/config/migrations.php`)
now declares `declare(strict_types=1);`. The PHP-CS-Fixer rule `declare_strict_types` is
flipped to `true`, so any future PHP file added without the declaration is blocked at the
`cs-fixer` CI gate.

This is a **forward-only** change. Per the No-Mercy / Aggressive rules
(`.cursor/ROADMAP.md`), no compatibility layer was added: every PHP-8-strict `TypeError`
surfaced by the migration is fixed at the **call site** (cast, signature widening, or
guard against `false`/`null`/`int` where a `string` was expected). No shims, no
`@deprecated` markers, no in-code TODOs introduced.

The `phpstan-baseline.neon` was *not* regenerated. Instead, every entry that became
"unmatched" because the underlying error was eliminated by a strict-mode fix was
**surgically removed** (eight entries total). Wholesale baseline regeneration is
explicitly forbidden for this step — it would mask new strict-mode errors.

The migration was executed in **12 sequential checklist steps** (one commit each), grouped
by module risk profile. Steps 1–10 add `strict_types` to 651 in-scope files; Step 11
sweeps up 94 lower-priority files (composer autoloader, language files, root scripts);
Step 12 enables the CS-Fixer rule and reformats 34 view templates that had the `declare`
on the same line as a following statement. After all 12 checklist commits a final-test
mini-loop produced a 13th commit (`fix(types): resolve runtime TypeErrors surfaced by
strict_types`) folding in the runtime regressions only `php pagekit setup` + Playwright
E2E could surface (PHPUnit and PHPStan are not enough — neither exercises the full HTTP
request lifecycle).

---

## ✅ What Changed

### `declare(strict_types=1);` added (~745 files)

| Group (Checklist Step) | Modules / Locations | Files |
|---|---|---|
| 1 | `app/modules/{filter,filesystem,cookie}/` | 50 |
| 2 | `app/modules/auth/` | 16 |
| 3 | `app/modules/database/` | 10 |
| 4 | `app/modules/{routing,session,config,markdown,migration,log}/` | 45 |
| 5 | `app/modules/{kernel,application,view,debug,feed}/` | 122 |
| 6 | `app/system/modules/{user,site,widget}/` | 46 |
| 7 | `app/system/modules/{cache,captcha,comment,content,dashboard,editor,finder,info,intl,settings,theme,view}/` + `app/system/src/` | 45 |
| 8 | `app/installer/`, `app/console/` | 33 |
| 9 | `packages/pagekit/blog/` | 92 |
| 10 | `packages/pagekit/theme-one/` | 94 |
| 11 | Sweep: root files + composer autoloader + language files | 94 |
| 12 | CS-Fixer rule enabled + 34 view-template formatting fixes | 38 |

### Strict-mode `TypeError` fixes (no shims — every fix is at the call site)

- **`app/modules/filesystem/src/Path.php`** — `strrpos()` returns `int|false`; guard the result before passing it to `substr()` / `strtr()`.
- **`app/modules/filesystem/src/Filesystem.php`** — cast `parse_url()` result before `strlen()`; add `is_string` guards in `exists()` for `getPathInfo` return values.
- **`app/modules/filesystem/src/StreamWrapper.php`** — cast `$options & STREAM_MKDIR_RECURSIVE` (an `int`) to `bool` for `mkdir()`. Follow-up fix (user-catch mini-loop): guard the `string|false` return of `Filesystem::getPath()` in all seven stream-wrapper callbacks (`dir_opendir`, `mkdir` first arg, `rename` both args, `rmdir`, `unlink`, `url_stat`, `stream_open`) — return the wrapper-spec failure value (`false`) instead of letting `false` reach `opendir`/`mkdir`/`rename`/`rmdir`/`unlink`/`fopen`/`file_exists`/`stat`. Also corrected `url_stat()`'s `@return` PHPDoc from `array` to `array|false` to match the wrapper contract.
- **`app/modules/routing/src/Router.php`** — replace `strstr()`/`substr()` chains with `strpos()`-guarded `substr()` to avoid `string|false` propagating into strict signatures.
- **`app/modules/routing/src/Event/AliasListener.php`** — same `strstr()`/`substr()` pattern as `Router`; replaced with `strpos()` + explicit `substr()` and `false` check.
- **`app/system/modules/cache/src/CacheModule.php`** — `opcache_invalidate()` expects `string`, not `Symfony\Component\Finder\SplFileInfo`; use `$file->getPathname()`.
- **`app/console/src/Commands/ExtensionTranslateCommand.php`** — same `SplFileInfo`-to-`string` issue; pass `$file->getPathname()` to `extractStrings()`.
- **`app/console/src/Commands/ArchiveCommand.php`** — Symfony Console `addOption()` `$shortcut` is now `array|string|null`; replace the legacy `false` sentinel with `null`.
- **`app/modules/application/src/Application/UrlProvider.php`** — `Filesystem::getUrl()` returns `string|false`, guard before `substr()`; replace `strstr()`/`substr()` with `strpos()` + explicit `substr()`; remove redundant `??` chains where the variable is already non-nullable; tighten `Router::generate()` reference-type to int (`UrlGenerator::ABSOLUTE_PATH`).
- **`app/system/modules/site/src/Model/Node.php`** — `Node::getUrl()` default for `$referenceType` changed from `false` to `UrlGenerator::ABSOLUTE_PATH` (Symfony's `Router::generate()` is now strict on its `int` parameter).
- **`app/modules/session/src/Csrf/Provider/{Default,Session}CsrfProvider.php`** — `uniqid()` `$prefix` requires `string`; cast `rand()` result to `(string)`.
- **`app/system/modules/site/src/Event/NodesListener.php`** — `strcmp(int, int)` is invalid under strict types; replace with the spaceship operator (`<=>`) and reverse the operands for descending sort.
- **`app/system/modules/view/src/Asset/FileLocatorAsset.php`** + **`app/modules/view/src/Asset/FileAsset.php`** — `getPath()` declared `: string` but returned `false` on miss; return `''` (truthiness still triggers the failure path at every call site).
- **`packages/pagekit/theme-one/functions.php`** — `isImage()` declared return type `: bool` but the body actually returned `string|false`; corrected the return type to match the body.

### `phpstan-baseline.neon` (surgical removal only)

Seven entries removed because the underlying error was eliminated by a code fix:

- `StreamWrapper.php` — `mkdir() expects bool, int given` (resolved by `(bool)` cast)
- `ArchiveCommand.php` — `addOption() $shortcut expects array|string|null, false given` (resolved by `false` → `null`)
- `UrlProvider.php` — `nullCoalesce.variable` (resolved by removing the `?? ''` patterns)
- `DefaultCsrfProvider.php` — `uniqid() expects string, int given` (resolved by `(string)` cast)
- `SessionCsrfProvider.php` — `uniqid() expects string, int given` (resolved by `(string)` cast)
- `NodesListener.php` — two `strcmp() expects string, int given` (resolved by `<=>`)
- `Asset.php` — `Method getPath() should return string but returns false` (resolved by returning `''` instead of `false`)

Diff shape: **7 entries removed, 0 entries added.** No regeneration.

### `.php-cs-fixer.php`

The placeholder `// TODO: Must be refactored in Step 2.1.3 (strict_types Migration)` is
replaced by `'declare_strict_types' => true,`. CS-Fixer dry-run on the entire branch
(`./app/vendor/bin/php-cs-fixer fix --dry-run --diff --no-interaction --show-progress=none --allow-risky=yes`)
exits 0 — every PHP file in the project now satisfies the rule.

---

## 🧱 Commits (Conventional Commits)

| SHA        | Subject | Checklist |
|---|---|---|
| `4627a6c4` | `refactor(types): add strict_types to filter, filesystem, cookie` | #1 |
| `838e6d3d` | `refactor(types): add strict_types to auth` | #2 |
| `086f5f1e` | `refactor(types): add strict_types to database` | #3 |
| `2e061185` | `refactor(types): add strict_types to routing, session, config, markdown, migration, log` | #4 |
| `0236d4bf` | `refactor(types): add strict_types to kernel, application, view, debug, feed` | #5 |
| `507dd352` | `refactor(types): add strict_types to system user, site, widget` | #6 |
| `cdc01279` | `refactor(types): add strict_types to remaining system modules` | #7 |
| `eeceb7b6` | `refactor(types): add strict_types to installer and console` | #8 |
| `290022a4` | `refactor(types): add strict_types to blog package` | #9 |
| `c345bd28` | `refactor(types): add strict_types to theme-one package` | #10 |
| `7deeb69d` | `refactor(types): sweep up missed strict_types files` | #11 |
| `e7ba5711` | `chore(cs-fixer): enable declare_strict_types rule` | #12 |
| `f8b7b1c6` | `fix(types): resolve runtime TypeErrors surfaced by strict_types` | final-test mini-loop |
| `5e81f74d` | `fix(types): resolve Bugbot-flagged strict_types regressions` | Bugbot stale-SHA mini-loop (1) |
| `aad6c2df` | `fix(types): null-guard FileAsset/FileLocatorAsset under strict_types` | Bugbot stale-SHA mini-loop (2) |
| `cc7a2f79` | `fix(types): null-coalesce $path at parseQuery boundary` | Bugbot stale-SHA mini-loop (3) |

Commits `4627a6c4` … `e7ba5711` map 1:1 to Checklist Steps 1–12 in
`.cursor/tickets/PROMPT_2_1_3_Strict-Types-Migration_plan.md`.

`f8b7b1c6` consolidates eight runtime fixes that PHPUnit + PHPStan could not catch — only
`php pagekit setup` and Playwright E2E exercise the strict-mode HTTP request lifecycle
(node sorting, URL building, asset path resolution, CSRF token generation, theme
function return paths, console option declarations).

`5e81f74d` resolves three additional `strict_types` regressions surfaced by Cursor
Bugbot's static review: a missed `strpos()`/`substr()` guard in `Filesystem::getUrl()`
NETWORK_PATH branch (parity with the ABSOLUTE_PATH branch fixed earlier), `Asset::getPath()`
still returning `false` despite the `FileAsset` subclass having been updated, and
`Asset::getContent()` / `FileAsset::getContent()` returning the nullable `$this->content`
property under a `: string` return type. The findings reached HEAD via the workflow's
new **stale-Bugbot Verifier check** (`.cursor/agents/verifier.md` § Stale-Bugbot Check) —
when Bugbot's review SHA is older than HEAD, the Verifier statically diffs each
inline-comment finding against current state instead of letting the Orchestrator
silently proceed.

`aad6c2df` resolves a follow-up Bugbot finding: `FileAsset::getPath()` was passing the
nullable `$this->source` (declared `?string` in the parent `Asset` class) directly to
`file_exists()`, which under `strict_types=1` rejects `null` and throws a `TypeError`.
The fix adds a `$this->source !== null &&` guard. Two adjacent null-safety improvements
were folded in: `FileAsset::hash()` now null-coalesces `$this->source` before string
concatenation, and `FileLocatorAsset::getSource()` null-coalesces the parent return
value to satisfy its `: string` return type.

`cc7a2f79` resolves a Bugbot finding from review `5e81f74d` that the previous quick-peek
procedure had silently dropped: `UrlProvider::parseQuery()` (rewritten earlier in this PR
to use `strpos()`/`substr()` instead of `strstr()`) had lost the `$url ?? ''` null guard
that the original `strstr()` chain implicitly tolerated. Caller `UrlProvider::get()`
passes `$path` which is genuinely nullable (per the existing `?? ''` guards in the same
method), so under `strict_types=1` `strpos(null, '?')` would throw a `TypeError` at
runtime. The fix coerces `$path ??= ''` once at the top of `get()` (replacing the two
scattered `?? ''` expressions on its callsites) so `parseQuery()` can keep its strict
`string $url` signature without any in-body null guard. One obsolete
`nullCoalesce.variable` baseline entry surgically removed.

The reason this finding survived the earlier mini-loops is itself worth documenting:
the previous quick-peek procedure (`.cursor/rules/orchestrator-subagent-workflow.mdc`)
read only the latest Bugbot review's REST inline-comments. Bugbot, however, posts each
finding **once**, in the review where it was first detected; subsequent reviews carry
only newly discovered findings. A finding first reported in review N and never resolved
is invisible when only review N+1's comments are read. The procedure has been replaced
with a single GraphQL `reviewThreads { isResolved }` query that aggregates correctly
across all reviews on the PR — see the **Workflow** entry below for details.

---

## 🛡️ No-Mercy Compliance

| Rule | Outcome |
|---|---|
| Rule 1 — No Compatibility Layers | ✅ `strict_types` is forward-only. No "shim mode" or "legacy compat" path exists. |
| Rule 2 — No Adapters | ✅ Every `TypeError` fix is at the call site. Zero new wrapper classes, traits, or static helpers introduced. |
| Rule 3 — Breaking Changes Allowed Internally | ✅ The system stayed functional after every checklist step (PHPUnit + PHPStan green). The `Router::generate()` reference-type tightening and `Node::getUrl()` default change are call-site updates that propagate consistently. |
| Rule 4 — Delete Over Wrap | ✅ The only baseline change is the **removal** of obsolete entries — never the addition of a suppression to mask a regression. |
| Rule 5 — Mandatory Flagging | ✅ Zero new in-code TODOs, BRIDGE labels, or "must be refactored later" markers. The CS-Fixer placeholder TODO that *did* exist (added in Step 2.1.2) is now resolved by the rule flip in Checklist Step 12. |
| PHP 8.2+ hygiene | ✅ Every PHP file now declares `strict_types=1`. PHP-CS-Fixer rule enforced. |
| No WP / Laravel artifacts | ✅ |

---

## 🧪 Test Results

### Per-step gates (Tester subagent — after every checklist step)

- ✅ `./app/vendor/bin/phpunit` — **326 tests, 0 failures** on every commit.
- ✅ `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M` — no errors on every commit.
- ✅ `git diff --quiet phpstan-baseline.neon` — exit 0 except where surgical removals were intended (the only allowed deltas are removals matching specific resolved errors).
- ✅ Step 11 sweep gate — `find ... -exec grep -L 'declare(strict_types' {} \; | wc -l` → `0`.
- ✅ Step 12 dry-run gate — `php-cs-fixer fix --dry-run` exits 0 (790 files scanned, 0 violations).

### Final gate (Tester subagent — full closure run)

- ✅ `./app/vendor/bin/phpunit` — 326 tests, 0 errors, 0 failures.
- ✅ `./app/vendor/bin/phpstan analyse` — no errors.
- ✅ `php pagekit setup` — exit 0, "Done".
- ✅ `php pagekit list` — exit 0, full command list.
- ✅ Playwright E2E (chromium-only per `AGENTS.md`):
  - `tests/e2e/specs/01-setup/installation.spec.js` — **1/1 passed**.
  - `tests/e2e/specs/02-core/authentication.spec.js` — **14/14 passed** (login, logout, CSRF, rate limiting, remember-me, sessions).
  - `tests/e2e/specs/02-core/dashboard.spec.js` — **10/10 passed** (load, widgets, navigation, responsive).

### Remote CI

- **Run `25197422178` (latest SHA `cc7a2f79`)** — `phpunit (8.2)` ✅ (27 s), `phpunit (8.3)` ✅ (23 s), `phpstan` ✅ (26 s), `cs-fixer` ✅ (18 s), `security-audit` ✅ (12 s).
- Run `25196756827` (SHA `aad6c2df`) — same matrix, all 5 jobs green.
- Run `25195918220` (SHA `5e81f74d`) — same matrix, all 5 jobs green.
- Run `25195012112` (SHA `f8b7b1c6`) — same matrix, all 5 jobs green.

### Bugbot quick-peek

The Bugbot quick-peek went through **three mini-loop iterations** under the
decision matrix at `.cursor/rules/orchestrator-subagent-workflow.mdc` § Bugbot Quick-Peek,
which now uses a single GraphQL `reviewThreads { isResolved }` query and routes any
stale-`original_commit` open thread to the **Verifier Stale-Bugbot Check**
(`.cursor/agents/verifier.md` § Stale-Bugbot Check) instead of silently proceeding.

- **Iteration 1** — three findings on stale SHAs `e7ba5711` and `38f5a04c`
  (`Filesystem.php` NETWORK_PATH `strpos`/`substr`, `Asset::getPath()` returns `false`,
  `Asset::getContent()` + `FileAsset::getContent()` return nullable `$content`).
  Verifier `OVERALL: FAIL` → mini-loop → fix commit `5e81f74d` → re-verify
  `OVERALL: PASS` against new HEAD.
- **Iteration 2** — one follow-up finding on stale SHA `1cafe817`
  (`FileAsset::getPath()` calls `file_exists($this->source)` without a null guard).
  Verifier `OVERALL: FAIL` → mini-loop → fix commit `aad6c2df` → re-verify
  `OVERALL: PASS` against new HEAD.
- **Iteration 3** — one finding from review `5e81f74d` that had been silently dropped
  by the previous quick-peek procedure (which read only the latest review's REST
  comments and missed cross-review unresolved findings). User-flagged on the next
  manual peek. Verifier `OVERALL: FAIL` → mini-loop → fix commit `cc7a2f79` (plus the
  workflow procedure repair commit `74f8e1b5` that replaced the per-review REST lookup
  with a cumulative GraphQL `reviewThreads { isResolved }` query) → re-verify via the
  fixed procedure: `OPEN_COUNT == 0` (all 5 historical Bugbot threads on this PR are
  marked resolved by the GraphQL state) → proceed.
- **Iteration 4 (user-catch)** — user-reported `string|false` regression in
  `StreamWrapper.php`: the original `mkdir()`-cast fix (commit `f8b7b1c6`) addressed
  only the `$recursive` argument and missed that `Filesystem::getPath()` returns
  `string|false` and reaches every other strict-typed PHP function in the wrapper
  (`opendir`, `mkdir` first arg, `rename` both args, `rmdir`, `unlink`, `file_exists`,
  `stat`, `fopen`). Pre-`strict_types`, `false` was silently coerced to `''`; under
  strict mode it throws `TypeError`. Fix: guard each call site to return the wrapper-
  spec failure value (`false`) before invoking the strict-typed function. Also fixed
  `url_stat()`'s `@return array` PHPDoc to `array|false` (the wrapper has always been
  allowed to return `false` per the PHP stream-wrapper contract; the PHPDoc was wrong
  even before strict mode and surfaced now via PHPStan). PHPUnit (326/326) and
  PHPStan (StreamWrapper-clean; the 9 unrelated `MySQLPlatform`/`MySqlPlatform`
  case-sensitivity errors are pre-existing and tracked under Step 2.1.7) green.
  Same iteration also reformatted the pre-existing predecessor comment
  `// TODO: is this still needed?` in `app/modules/routing/src/Event/AliasListener.php`
  to the canonical Rule 5 Out-of-scope tag
  `// TODO: Must be refactored in Step 2.1.6 (PHPStan Level 7→8 / Strict Typing)`,
  with a precise description of the dead inline-query-string parser to be deleted
  (lines 50–57 + the dependent `strtok` clause in the `array_filter` on line 39).
  The cleanup itself is routed to Step 2.1.6 — see the new "Audit findings (Step 2.1.3
  review)" bullet in `migration-docs/TODO/PHASE_2_MODERNISING.md`.

The user reviews any post-peek findings manually before merging.

---

## 📚 Out-of-Scope (Deferred — flagged with ROADMAP IDs)

| Concern | Tracked in |
|---|---|
| Return-type sweep (PHPStan Level 5 → 6) | Step 2.1.4 |
| Null-safety sweep (PHPStan Level 6 → 7) | Step 2.1.5 |
| Strict-typing sweep (PHPStan Level 7 → 8) — `DebugStack` removal among others | Step 2.1.6 |
| QueryBuilder API standardization | Step 2.1.7 |
| Infection mutation testing | Step 2.1.8 |
| Coverage threshold enforcement | Step 2.1.9 |
| PHPStan baseline regeneration / wholesale rewrite | Forbidden in this step — explicitly. Routed to whichever future PHPStan-level step also bumps the level. |

---

## 📎 Related Documents

- Plan / TODO-Spec: `.cursor/tickets/PROMPT_2_1_3_Strict-Types-Migration_plan.md`
- Task Prompt: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_3_Strict-Types-Migration.md`
- Phase plan: `.cursor/ROADMAP.md` → Phase 2.1 → Step 2.1.3
- Predecessor: `migration-docs/branches/step-2-1-2-cicd-quality-gates.md` (the CI gates that now enforce `declare_strict_types` on every PR)
