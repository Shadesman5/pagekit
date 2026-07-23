# 🔍 Audit Report — Step 2.1 Completion & Documentation Audit (Static Analysis & Code Quality)

## 1. Header

| Field | Value |
|---|---|
| **Audit date** | 2026-07-23 |
| **Auditor** | Cloud agent (read-only completion audit, conductor-mode: plan) |
| **Audited tree** | branch `feature/AGENT_PROMPT_AUDIT_STEP_2_1` @ `d2a57d4b` (= `develop` incl. merge of PR #238) |
| **PHP version** | PHP 8.5.8 (cli) NTS, Zend OPcache v8.5.8 |
| **App version** | 1.2.30 (`app/system/config.php:9` — matches ROADMAP header `Current Version: 1.2.30`) |
| **ROADMAP Current Step** | `2.1 (Static Analysis & Code Quality - Audit)` (`.cursor/ROADMAP.md:4`) |
| **Scope** | ROADMAP Step 2.1 + sub-steps 2.1.1 – 2.1.14, Issue #147 + 14 sub-issues, residual-debt routing, in-code flags |
| **Standards** | `.cursor/rules/pagekit-context.mdc`, `.cursor/rules/pagekit-standards.mdc`, `.cursor/ROADMAP.md` (5 aggressive rules), `migration-docs/TODO/MODERNISATION_STRATEGY.md` (Pagekit DNA) |
| **Source of truth** | `.cursor/ROADMAP.md` tracking table · `migration-docs/TODO/PHASE_2_MODERNISING.md` §2.1 · `migration-docs/branches/phase-2/step-2-1-*.md` · `CHANGELOG-NEW.md` · GitHub Issue #147 (+ sub-issues) · `phpstan.neon` / `phpstan-baseline.neon` · `.github/workflows/php-quality.yml` · `composer.json` |

**Read-only guarantee:** no application code, tracked doc, ROADMAP/PHASE file, or GitHub issue was modified. The only file created is this report. All corrections are recorded as ready-to-apply proposals in §6 and §9.

---

## 2. Current-State Baseline

Commands run on the audited tree (2026-07-23):

| Probe | Command | Result |
|---|---|---|
| PHP | `php -v` | **PHP 8.5.8** (cli) NTS |
| Baseline blocks | `grep -c "message:" phpstan-baseline.neon` | **351** |
| Suppressed errors | `grep -oP 'count:\s*\K[0-9]+' phpstan-baseline.neon \| awk '{s+=$1} END{print s+0}'` | **695** |
| PHPStan Level 8 | `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M` | **`[OK] No errors`**, exit 0 (~16 s) |
| PHPUnit | `./app/vendor/bin/phpunit --colors=never` | **Tests: 725, Assertions: 2057** — OK (2 PHPUnit deprecations, 5 skipped) |
| `strict_types` gaps | `rg -L -t php --files-without-match 'declare\(strict_types=1\)' app packages -g '!app/vendor/**' \| wc -l` | **0** (100 % in own code) |
| CI on `develop` | `gh run list --branch develop --workflow "PHP Quality" --limit 3` | 3 × **success** (newest 2026-07-23T20:17:53Z, post-#238 merge) |

Notes:

- The task prompt's raw probe `rg -L … app packages` matches ~3 379 vendor files when the ignore rules are bypassed; `app/vendor` is third-party (gitignored via `.gitignore:18`) and outside the migration scope. The meaningful own-code count is **0**.
- The 2 PHPUnit deprecations are the two known doc-comment-metadata sites already routed to Step 2.9 (`app/modules/config/src/Tests/ConfigManagerTest.php:13` `@doesNotPerformAssertions`; `tests/Unit/Migration/MigrationServiceTest.php`) — see §7 item 6.
- No full E2E run was performed (per audit rules).

---

## 3. Executive Summary

**Verdict: Step 2.1 is genuinely complete.** All 14 sub-steps verify ✅ against the code on the current PHP 8.5 tree. The quality claims hold: PHPStan **Level 8** enforced and green, `strict_types` at **100 %**, quality gates active on every PR, coverage floor + Codecov wired, Infection scope/threshold configured. The discrepancies found are **documentation/metadata drift only** — no material gap between claim and code.

**RAG per sub-step:** 2.1.1 ✅ · 2.1.2 ✅ · 2.1.3 ✅ · 2.1.4 ✅ · 2.1.5 ✅ · 2.1.6 ✅ · 2.1.7 ✅ · 2.1.8 ✅ · 2.1.9 ✅ · 2.1.10 ✅ · 2.1.11 ✅ · 2.1.12 ✅ · 2.1.13 ✅ · 2.1.14 ✅ — **14/14 verified**, 0 ⚠️, 0 ❌ at sub-step level.

**Counts:** 7 documentation discrepancies (§5), 26 residual-debt ledger items — 20 routed to existing future steps, 3 accepted-by-design, **1 stale** (already done), **1 orphan** (needs a home), 1 routed to a ROADMAP row without a PHASE file (§7). 24 in-code flags — **all live, zero stale, zero orphan**; **zero flags reference Step 2.1** (§8).

**Is #147 closeable? YES.** All 14 sub-issues (formally linked as GitHub sub-issues of #147) are CLOSED; all 14 PRs are MERGED — including PR #238 (merged 2026-07-23T20:17:50Z), which was the last blocker. The parent issue body must be corrected before closing (§6).

**Key takeaways:**

1. **Reality is ahead of the docs.** Code, CI, and configs all match or exceed the claimed outcomes; the stale artifacts are the parent Issue #147 body, one "Open sub-steps" line in `PHASE_2_MODERNISING.md:78`, and one already-completed §2.8.3 candidate.
2. **The 2.1.14 follow-ups all landed:** `InstallerIO` explicit nullables (0 `implicitlyNullable` baseline entries), `\Pdo\Mysql::ATTR_INIT_COMMAND`, `PropertyTrait` transient store, and the ORM eager-load fix (`getRelations()` via `getRepository()->query()`) with two regression tests + fixtures.
3. **One genuine unhomed debt found:** ~29 baseline-suppressed `variable.undefined` errors caused by `extract()` in **production logic** (`Query/QueryBuilder.php`, `ParamFetcher.php`, 3 API controllers) are covered neither by the `phpstan.neon` accepted-by-design note (views + `$app` bootstraps) nor by any §2.8.3 candidate → propose adding to Step 2.8.3 (§9-P4).
4. **One §2.8.3 candidate is already done:** the `InstallerIO` implicitly-nullable burndown landed with 2.1.14 → propose removing the candidate (§9-P3).
5. **The literal #147 acceptance box "Core modules at 80 %+ coverage" is not met** (repo line coverage ≈ 3.9 %, CI floor 3.8 %); breadth targets were formally re-scoped to Step 2.9 in 2.1.9. The corrected body re-words this box honestly (§6).
6. **Issue #147's "9 sub-steps" and "PHP 8.2+" are stale** — 14 sub-steps exist, and the runtime minimum is 8.5 everywhere (composer/index.php/requirements.php/CI/Dockerfile/README).
7. Phase-5 routed items (TinyMCE 6+ editor decision → 5.1; marketplace TODOs → 5.6) have ROADMAP rows but **no `PHASE_5_MODERNISING.md`** exists yet — acceptable for a far-future phase, flagged for Phase-5 planning (§9-P7).

---

## 4. Per-Sub-Step Verification Matrix

Legend — **A: Reality** (code/config on the current tree) · **B: Docs** (PHASE entry + branch doc + ROADMAP row consistent) · **C: Forward debt** (every deferred item routed to a real future-step section).

| Sub-step | Claimed outcome | A: Reality (evidence) | B: Docs OK? | C: Debt routed? | Notes |
|---|---|---|---|---|---|
| **2.1.1** Tooling Setup & Baseline | Quality tools installed, PSR-12 formatting, baseline documented | ✅ `composer.json:56-68` (phpunit `^11.0`, phpstan `^2.1` + doctrine/symfony/phpunit extensions, php-cs-fixer `^3.94`, infection `>=0.33 <0.35`); `.php-cs-fixer.php:23` `'@PSR12' => true`; `phpstan-baseline.neon` present; baseline provenance in `CHANGELOG-NEW.md:754-760` (§1.2.6: 883 errors baselined at L5) | ✅ No branch doc — explicitly documented at `PHASE_2_MODERNISING.md:104` ("No dedicated branch doc (early Workflow V1, PR #178)"); ticket plan archived at `migration-docs/tickets/done/PROMPT_2_1_1_Tooling-Setup-Baseline_plan.md`; ROADMAP row ✅/#148/PR #178 (MERGED) | ✅ (deferrals listed in 2.1.2 doc `:239-241`) | Only sub-step with Audit cell **⏳** (`ROADMAP.md:92`) — accurate (no No-Mercy audit had run); this audit closes it → §9-P1 |
| **2.1.2** CI/CD Integration & Quality Gates | Automatic quality checks on every PR | ✅ `.github/workflows/php-quality.yml:5-15` triggers push+PR on `main`/`develop`; 4 jobs: `phpunit` (`:25`, pcov coverage), `phpstan` (`:134`, L8+baseline), `cs-fixer` (`:162`, dry-run `--allow-risky`), `security-audit` (`:193`, `composer audit --locked`); latest 3 `develop` runs **success** (newest 2026-07-23T20:17:53Z) | ✅ `step-2-1-2-cicd-quality-gates.md`; ROADMAP ✅/🛡️/#149/PR #199 (MERGED) | ✅ Deferred table `:218-229` → 2.1.3/2.1.4-6/2.1.8/2.1.9/2.2 — all exist; branch-protection = documented manual user action | — |
| **2.1.3** `strict_types` Migration | `declare(strict_types=1)` in all PHP files | ✅ 0 files without the declaration in `app`+`packages` (excl. vendor); enforced forever via `.php-cs-fixer.php:44` `'declare_strict_types' => true` + `cs-fixer` CI gate | ✅ `step-2-1-3-strict-types-migration.md`; ROADMAP ✅/🛡️/#150/PR #201 (MERGED); CHANGELOG §1.2.16 | ✅ `:266-276` → 2.1.4-2.1.9 (all done); baseline-regeneration ban carried into `phpstan.neon:11-12` | — |
| **2.1.4** PHPStan L5→6 (Return Types) | Level 6 clean | ✅ superseded by current `level: 8` green (`phpstan.neon:20`); regression fixes documented (CHANGELOG §1.2.19: `Relation::initRelation()` null-sentinel, `$view->script()` array deps) | ✅ `step-2-1-4-phpstan-level-6.md`; ROADMAP ✅/🛡️/#151/PR #203 (MERGED); CHANGELOG §1.2.17-1.2.19 | ✅ `:291-311` → 2.1.5/2.1.6/2.1.7/2.1.8/2.1.9 (all done); PSR-11 `mixed` = permanent contract | — |
| **2.1.5** PHPStan L6→7 (Null Safety) | Level 7 clean | ✅ superseded by L8 green | ✅ `step-2-1-5-phpstan-level-7.md`; ROADMAP ✅/🛡️/#152/PR #210 (MERGED); CHANGELOG §1.2.20 | ✅ no Deferred section — nothing promised, nothing dropped | Doc records the `MetaHelper` E2E lesson (referenced from 2.1.6 `:289`) |
| **2.1.6** PHPStan L7→8 (Strict Typing) | Level 8 (full type safety) | ✅ `phpstan.neon:20` `level: 8`; `analyse` = `[OK] No errors`; audit-finding deletions verified: `app/modules/database/src/Logging/DebugStack.php` deleted (0 class refs), `AliasListener` dead inline-query parser gone (`rg "strtok" …/AliasListener.php` = 0) | ✅ `step-2-1-6-phpstan-level-8.md`; ROADMAP ✅/🛡️/#153/PR #212 (MERGED); CHANGELOG §1.2.21 | ✅ Deferred table `:97-104` → 2.1.10 (#204), 2.1.11 (#205), 2.5, 2.1.7 — all done or live in PHASE_2 §2.5 | — |
| **2.1.7** QueryBuilder API Standardization | Doctrine-style DB API, no ad-hoc patterns | ✅ `execute()` deleted (`rg "public function execute\(" app/modules/database/src` = 0); `executeQuery()`/`executeStatement()` split with write-type guard (`app/modules/database/src/Query/QueryBuilder.php:667-670` — `match` throws `LogicException` for SELECT); `json_array` fully gone (0 hits); `MySqlPlatform` casing bug gone (0 hits) | ✅ `step-2-1-7-querybuilder-api.md`; ROADMAP ✅/🛡️/#154/PR #215 (MERGED); CHANGELOG §1.2.22 | ✅ inline deferral (`:39` property type → 2.1.12, done); ORM `cache()` hotfixes explicitly scope-noted to 1.11 | Post-review hardening (delete-guard, cache-key) documented in doc `:60-78` |
| **2.1.8** Infection Mutation Testing | 80 %+ MSI/Covered-MSI on auth+user security core | ✅ `infection.json.dist` scopes `app/modules/auth/src` + user `Model`/`Auth`/`Event`; `"minMsi": 80, "minCoveredMsi": 80`; composer plugin allowed (`composer.json:110`); the doc-promised `User::parse*` mutator-ignore cleanup landed (current ignores only reference `DatabaseHandler`/`LoginAttemptListener`/`AccessListener`) | ✅ `step-2-1-8-infection-mutation-testing.md`; ROADMAP ✅/🛡️/#155/PR #216 (MERGED); CHANGELOG §1.2.23 | ✅ CI wiring → 2.2 (`PHASE_2:222` Workflow 1b); DB/kernel gaps → 2.1.9 → re-routed 2.9 (`PHASE_2:347`); injectable clock → 2.9 (`PHASE_2:344`) | MSI values recorded as local (PCOV) runs — CI-published numbers land with 2.2 by design |
| **2.1.9** Test Coverage Expansion | CI coverage floor, Codecov, security/ORM edge cases | ✅ ratcheting gate `MIN_LINE_COVERAGE: '3.8'` (`php-quality.yml:101-132`); Codecov OIDC tokenless, non-blocking (`:85-92`); `codecov.yml` present; `phpunit.xml.dist:37-42` `failOnWarning`/`failOnPhpunitWarning`/`failOnRisky` = `"true"` (flips promised by 2.1.8 landed) | ✅ `step-2-1-9-test-coverage-expansion.md`; ROADMAP ✅/🛡️/#156/PR #218 (MERGED); CHANGELOG §1.2.24 | ✅ `:135-141` breadth targets/packages/DB-kernel/Infection-ratchet → 2.9 (`PHASE_2:338-352`); full E2E rework → 3.6.1 (`PHASE_3:323`) | 80 %+ breadth explicitly re-scoped to 2.9 — see §6 acceptance box 5 |
| **2.1.10** Entity Presentation Layer (DTO) | `ModelServiceLocator` gone; presenters carry URL/access/comment presentation | ✅ `rg "ModelServiceLocator" app packages` = **0 hits**; `packages/pagekit/blog/src/PostPresenter.php`, `app/system/modules/site/src/NodePresenter.php` + `NodePresenterTest.php` exist | ✅ `step-2-1-10-entity-presentation-layer.md`; ROADMAP ✅/🛡️/#204/PR #219 (MERGED); CHANGELOG §1.2.25 | ✅ `:96-101` EM singleton → 2.1.11 (done); `IntlServiceLocator` = accepted permanent locator; UrlResolver → 2.5; API-v2 consumption → PHASE_4 §4.4 (`:100`) | Branch doc uses pre-renumbering ID "Step 4.2 (REST API v2)" — historical, ROADMAP SSoT note covers it (§5-D4) |
| **2.1.11** EntityManager DI (remove singleton) | No static Active-Record API, no EM singleton, repositories via DI | ✅ `rg "public static function (query\|find\|where\|create)\b"` = 0 on entities; no `getInstance`/static instance in `app/modules/database/src/ORM/EntityManager.php`; `NodeRepository:46` uses `parent::find()` (repository inheritance, not static AR); `UrlResolver` repo access via tagged 2.5 bridge (`packages/pagekit/blog/src/UrlResolver.php:26-43`) | ✅ `step-2-1-11-entitymanager-di.md`; ROADMAP ✅/🛡️/#205/PR #222 (MERGED); CHANGELOG §1.2.26 | ✅ `:477-492` → 2.5 (UrlResolver/RouteListener/routing DI — `PHASE_2:274-276` incl. the `setPostRepository` setter), 2.1.12 (done), API-v2 → PHASE_4 §4.4, cache invalidation → PHASE_4 §4.5 (`:113-114`); ORM swap = non-goal | Branch doc IDs "4.2/4.3" are pre-renumbering (now 4.4/4.5) — PHASE files carry the correct current homes (§5-D4) |
| **2.1.12** Residual `mixed` narrowing | Avoidable `mixed` narrowed; rest justified | ✅ spot checks: `app/system/modules/site/src/Controller/NodeController.php:28` `private readonly SiteModule $site`; `app/system/src/Model/DataModelTrait.php:14` `public ?array $data = null` | ✅ `step-2-1-12-residual-mixed-narrowing.md`; ROADMAP ✅/🛡️/#217/PR #227 (MERGED); CHANGELOG §1.2.27 | ✅ `:144-146` — Deferred explicitly **empty**; remaining `mixed` documented permanent non-goal; property-hooks path → 2.8.1 (`PHASE_2:314`) | — |
| **2.1.13** TinyMCE Security Patch | tinymce `~5.10.9` (XSS/mXSS close-out, same-major) | ✅ `package.json:40` `"tinymce": "~5.10.9"`; `yarn.lock` resolves **5.10.9** | ✅ `step-2-1-13-tinymce-security-patch.md` exists; ROADMAP ✅/🛡️/#230/PR #234 (MERGED); CHANGELOG §1.2.29 | ✅ `:109-116` CSP `frame-src`/`object-src` → 3.2.1 (`PHASE_3:84-92` names TinyMCE iframe XSS explicitly); webpack transitives → 2.4 (`PHASE_2:256`); TinyMCE 6+ major → 5.1 (ROADMAP row exists; **no PHASE_5 file** — §7 item 10, §9-P7) | — |
| **2.1.14** PHP Version Upgrade (8.2→8.5) | Runtime minimum 8.5 across Composer/CI/Docker/guards; no own-code runtime deprecation on 8.5 | ✅ `composer.json:18` `"php": "^8.5"` + `:107` `platform.php: 8.5.0`; `index.php:5` guard `'8.5'`; `app/installer/requirements.php:364` `REQUIRED_PHP_VERSION = '8.5.0'`; CI matrix `['8.5']` (`php-quality.yml:37`) + phpstan/cs-fixer/security jobs on 8.5; `Dockerfile:1` `FROM php:8.5-apache`; README badges/requirements 8.5+. **Follow-ups:** `InstallerIO.php:21` explicit `?InputInterface/?OutputInterface/?HelperSet` (baseline `implicitlyNullable` count = **0**); `app/modules/database/index.php:152-154` `\Pdo\Mysql::ATTR_INIT_COMMAND` (guarded by `defined()`); `PropertyTrait.php:20` `private array $_transient` store; **ORM eager-load fix:** `ORM/QueryBuilder::getRelations()` builds relation queries via `$this->manager->getRepository($targetEntity)->query()` with a `LogicException` guard; regression tests `QueryBuilderEagerLoadTest.php` + `QueryBuilderInvalidRelationTest.php` + fixtures present (commit `b5de545e`). PHPUnit 725 OK on 8.5.8 — the only 2 deprecations are PHPUnit-internal metadata (routed → 2.9), no own-code runtime deprecation | ✅ `step-2-1-14-php-version-upgrade.md`; ROADMAP ✅/🛡️/#231/PR #238 (**MERGED** 2026-07-23T20:17:50Z); CHANGELOG §1.2.30 | ✅ `:194-205` dashboard 8.2/8.3 legs → 2.2 (`PHASE_2:230`; verified live at `docs-site/content/javascripts/quality-dashboard.js:79-100`, `docs-site/data/quality-snapshot.demo.json:13-14`); PHPUnit metadata + `failOn*` + PHPUnit-12/13 eval → 2.9 (`PHASE_2:345-346`; both sites verified to exist); Dockerfile redesign → 2.3; non-goals 4.2/4.3/2.8.x/2.9 all have PHASE sections | — |

**Cross-cutting current-state checks (Issue #147 acceptance criteria, verified today):**

| Criterion | Holds? | Evidence |
|---|---|---|
| PHPStan **Level 8** enforced + green | ✅ | `phpstan.neon:20`; CI job `php-quality.yml:159-160`; local run `[OK] No errors` |
| `declare(strict_types=1)` ≈ 100 % | ✅ | 0 own-code files without it; CS-Fixer rule + CI gate block regressions |
| Quality gates active on every PR | ✅ | `php-quality.yml:12-13` `pull_request: branches: [main, develop]`; 4 jobs; latest runs green |
| Core modules at the coverage floor | ✅ (floor) / ❌ (literal 80 %) | Ratchet gate `MIN_LINE_COVERAGE: 3.8` enforced per PR; the literal "80 %+ core coverage" box in #147 was re-scoped to Step 2.9 (see §6) |
| Infection scope/threshold active | ✅ | `infection.json.dist` auth+user scope, `minMsi`/`minCoveredMsi` = 80 (local gate; CI wiring by design in 2.2) |

---

## 5. Documentation Accuracy Findings

| # | Finding | Evidence | Severity | Proposal |
|---|---|---|---|---|
| D1 | **`PHASE_2` §2.1 intro contradicts its own table**: "Open sub-steps: 2.1.13 (TinyMCE), 2.1.14 (PHP 8.5)" while the table below and ROADMAP mark both ✅ | `migration-docs/TODO/PHASE_2_MODERNISING.md:78` vs `:96-97` and `ROADMAP.md:104-105` | Minor | §9-P2 |
| D2 | **ROADMAP parent row 2.1 still ⏳/⏳** although all 14 sub-steps are ✅ and merged; header pointer still on 2.1 | `.cursor/ROADMAP.md:4,91` | Minor (expected — parked on this audit) | §9-P1 |
| D3 | **ROADMAP 2.1.1 Audit cell ⏳** — the only sub-step without 🛡️; accurate until now (no No-Mercy audit had covered it), this audit provides the missing verification | `.cursor/ROADMAP.md:92` | Minor | §9-P1 |
| D4 | **Branch docs 2.1.10/2.1.11 use pre-renumbering Phase-4 IDs** ("Step 4.2 (REST API v2)", "Step 4.3 (Performance Optimization)" — today: 4.4/4.5). Covered by the ROADMAP SSoT note ("historical branch docs may still mention old IDs; the living ROADMAP wins", `ROADMAP.md:45`); the PHASE entries (`PHASE_2:170,179`) carry the correct current IDs | `step-2-1-10-entity-presentation-layer.md:101`, `step-2-1-11-entitymanager-di.md:477-489` | Info (accepted by stated policy) | None (optionally add a one-line ID-mapping note at the top of the two docs) |
| D5 | **`PHASE_2` §2.8.3 candidate already completed**: "Clear the 3 PHP 8.4 `parameter.implicitlyNullable` baseline entries in `app/installer/src/Helper/InstallerIO.php`" — landed with 2.1.14 (explicit `?Type` at `InstallerIO.php:21`, baseline `implicitlyNullable` count = 0) | `PHASE_2_MODERNISING.md:331` vs `app/installer/src/Helper/InstallerIO.php:21` | Minor | §9-P3 |
| D6 | **`phpstan.neon` header-note wording narrower than reality**: note covers "view templates under `*/views/`" + `$app` bootstraps, but 16 accepted suppressions live in `app/system/modules/user/mails/*.php` (same PhpEngine-rendered template mechanism, different directory) and ~29 live in **production logic** using `extract()` (not covered at all — genuine debt, see §7 item 19) | `phpstan.neon:1-12` vs baseline paths `…/mails/welcome.php` (+3 more), `app/modules/database/src/Query/QueryBuilder.php`, `app/modules/routing/src/Request/ParamFetcher.php`, `PostApiController.php`, `CommentApiController.php`, `UserApiController.php` | Medium | §9-P4 + §9-P5 |
| D7 | **CHANGELOG**: complete — every sub-step has a released entry (2.1.1→1.2.6, 2.1.2→1.2.15, 2.1.3→1.2.16, 2.1.4→1.2.17-1.2.19, 2.1.5→1.2.20, 2.1.6→1.2.21, 2.1.7→1.2.22, 2.1.8→1.2.23, 2.1.9→1.2.24, 2.1.10→1.2.25, 2.1.11→1.2.26, 2.1.12→1.2.27, 2.1.13→1.2.29, 2.1.14→1.2.30). No gaps found | `CHANGELOG-NEW.md:3-797` | ✅ None | — |

Branch-doc inventory: 13 files `step-2-1-{2..14}-*.md` present — matches expectations exactly (2.1.1 has no branch doc **by documented design**, `PHASE_2:104`; its plan is archived at `migration-docs/tickets/done/PROMPT_2_1_1_Tooling-Setup-Baseline_plan.md`). Every ✅ row that promises a doc has one. ROADMAP Status/Issue/PR columns verified 14/14 (all issues exist, all PRs MERGED — checked via `gh`).

---

## 6. Issue #147 Reconciliation

### 6.1 Stale items (verified against the live issue body)

1. **"All 9 sub-steps"** → there are **14** (2.1.1 – 2.1.14); 5 sub-steps (2.1.10 – 2.1.14) were added after the body was written.
2. **"PHP 8.2+ standards"** → runtime minimum is **PHP 8.5** since Step 2.1.14 (PR #238).
3. **"Current State (Feb 2026)" table** entirely stale: "~113 (~15 %) strict_types" → now 100 %; "PHPStan Not installed" → Level 8 green; "Infection Not installed" → configured with 80 % gates; "Test files 39 (~200 methods)" → 725 tests / 2 057 assertions; "CI/CD for tests: None" → 4-job PHP Quality workflow on every PR.
4. **All 5 acceptance boxes unchecked** although the work is done (see 6.3 for which can be ticked).
5. **State OPEN** although all 14 sub-issues are CLOSED and all 14 PRs MERGED.
6. *(Cosmetic)* Milestone description "ROADMAP Steps 2.0 to 2.6" predates steps 2.7 – 2.9.

### 6.2 Sub-issue / PR states (all verified 2026-07-23)

GitHub lists **14/14 sub-issues formally linked** to #147 (GraphQL `subIssues.totalCount = 14`):

| Sub-issue | Step | State | PR | PR state |
|---|---|---|---|---|
| #148 | 2.1.1 | CLOSED | #178 | MERGED |
| #149 | 2.1.2 | CLOSED | #199 | MERGED |
| #150 | 2.1.3 | CLOSED | #201 | MERGED |
| #151 | 2.1.4 | CLOSED | #203 | MERGED |
| #152 | 2.1.5 | CLOSED | #210 | MERGED |
| #153 | 2.1.6 | CLOSED | #212 | MERGED |
| #154 | 2.1.7 | CLOSED | #215 | MERGED |
| #155 | 2.1.8 | CLOSED | #216 | MERGED |
| #156 | 2.1.9 | CLOSED | #218 | MERGED |
| #204 | 2.1.10 | CLOSED | #219 | MERGED |
| #205 | 2.1.11 | CLOSED | #222 | MERGED |
| #217 | 2.1.12 | CLOSED | #227 | MERGED |
| #230 | 2.1.13 | CLOSED | #234 | MERGED |
| #231 | 2.1.14 | CLOSED | #238 | **MERGED 2026-07-23T20:17:50Z** |

**No unmerged PR blocks closure.** (PR #238 was the last one; it merged today and the post-merge `develop` CI run is green.)

### 6.3 Acceptance boxes — tick decision

- ✅ **Tick** "All sub-steps completed" — after correcting 9 → **14** (evidence: table above + ROADMAP rows `92-105`).
- ✅ **Tick** "PHPStan Level 8 enforced" (`phpstan.neon:20`, CI `php-quality.yml:159-160`, `[OK] No errors`).
- ✅ **Tick** "`declare(strict_types=1)` in all PHP files" (0 gaps; CS-Fixer + CI gate).
- ✅ **Tick** "Quality gates active on every PR" (`php-quality.yml:12-13`; 4 jobs; latest runs green).
- ❌ **Do NOT tick as written** "Core modules at 80 %+ coverage" — repo line coverage is ≈ 3.9 % against a ratcheting 3.8 % floor; the 80 %+ breadth target was formally re-scoped to **Step 2.9** in Step 2.1.9 (branch doc `:135-141`, `PHASE_2:338-352`). What *is* achieved: coverage floor gate per PR, Codecov wiring, per-branch coverage growth policy, and ≥ 80 % MSI / Covered-MSI mutation gates on the auth+user security core. → **Re-word the box** (see corrected body) and tick the re-worded version.

### 6.4 Closeability decision

**Closeable: YES.** Criteria: all sub-steps ✅ in ROADMAP (14/14, `ROADMAP.md:92-105`) **and** all their PRs merged (14/14, §6.2). Recommended sequence: apply the corrected body below (fixes 9→14, 8.2→8.5, table, boxes) → close #147 as *completed*. The re-scoped coverage-breadth work continues under Step 2.9's tracking (no successor issue exists yet for 2.9; creating one is a Phase-2 planning action, not a 2.1 blocker).

### 6.5 Copy-paste-ready corrected body for #147

```markdown
## Goal

Establish comprehensive static analysis and code quality tooling for Pagekit CMS to enforce PHP 8.5+ standards, typed code, and test quality across the codebase.

## Context

- **ROADMAP Step**: 2.1
- **Phase**: Phase 2 - Developer Experience
- **Depends on**: Step 1.14 (Doctrine Attributes) completed
- **Branch**: Multiple feature branches per sub-step (one PR per sub-step, see below)

## Final State (July 2026 — verified by completion audit)

| Metric | Feb 2026 (historical) | Final (verified 2026-07-23) |
|--------|----------------------|------------------------------|
| PHP minimum | 8.2 | **8.5** (`composer.json` `^8.5`, `index.php` + installer guards, CI matrix, Dockerfile) |
| Files with `strict_types` | ~113 (~15%) | **100 %** (0 gaps; enforced by PHP-CS-Fixer + CI) |
| PHPStan | Not installed | **Level 8, green** (`[OK] No errors`; 351-block curated baseline, never regenerated) |
| Test suite | 39 files (~200 methods) | **725 tests / 2 057 assertions**, green on PHP 8.5.8 |
| CI/CD for tests | None | **PHP Quality workflow on every PR**: PHPUnit + coverage floor (ratchet, 3.8 %), PHPStan L8, CS-Fixer dry-run, composer security audit; Codecov (OIDC, non-blocking) |
| Infection | Not installed | **Configured**: auth + user security core, `minMsi`/`minCoveredMsi` = 80 (CI wiring → Step 2.2) |
| DB layer API | ad-hoc `execute()` | Doctrine-style `executeQuery()`/`executeStatement()` (+ write-type guard) |
| Model layer | static Active-Record + EM singleton + `ModelServiceLocator` | DI repositories / EntityManager; presenters (`NodePresenter`, `PostPresenter`) |
| TinyMCE | 5.5.1 (EOL) | 5.10.9 (same-major security patch) |

Audit report: `migration-docs/audits/2026/07/AUDIT_REPORT_STEP_2.1_2026-07-23.md`

## Acceptance Criteria

- [x] All **14** sub-steps completed (2.1.1 – 2.1.14)
- [x] PHPStan Level 8 enforced
- [x] `declare(strict_types=1)` in all PHP files
- [x] Quality gates active on every PR
- [x] Coverage & mutation infrastructure active: ratcheting CI line-coverage floor + Codecov + per-branch coverage growth policy; ≥ 80 % MSI / Covered-MSI on the auth+user security core. *(The original "core modules at 80 %+ line coverage" breadth target is re-scoped to Step 2.9 — Phase 2 Closeout, together with Infection scope widening.)*

## Sub-steps

| Step | Issue | PR |
|---|---|---|
| 2.1.1 Tooling-Setup & Baseline | #148 | #178 |
| 2.1.2 CI/CD Integration & Quality Gates | #149 | #199 |
| 2.1.3 `strict_types` Migration | #150 | #201 |
| 2.1.4 PHPStan Level 5→6 (Return Types) | #151 | #203 |
| 2.1.5 PHPStan Level 6→7 (Null Safety) | #152 | #210 |
| 2.1.6 PHPStan Level 7→8 (Strict Typing) | #153 | #212 |
| 2.1.7 QueryBuilder API Standardization | #154 | #215 |
| 2.1.8 Infection Mutation Testing | #155 | #216 |
| 2.1.9 Test Coverage Expansion | #156 | #218 |
| 2.1.10 Entity Presentation Layer (DTO) | #204 | #219 |
| 2.1.11 EntityManager DI (remove singleton) | #205 | #222 |
| 2.1.12 Residual `mixed` narrowing | #217 | #227 |
| 2.1.13 TinyMCE Security Patch (~5.10.9) | #230 | #234 |
| 2.1.14 PHP Version Upgrade (8.2 → 8.5) | #231 | #238 |

## Related

<!-- metadata
labels: phase-2, migration, backend
milestone: Phase 2: Developer Experience
-->
```

*(Applying this body, ticking the boxes, and closing the issue is an explicit follow-up — not done in this read-only run.)*

---

## 7. Residual Debt & Deferred-Work Ledger

Every deferred / forward / out-of-scope item originating in Step 2.1, routed to its target. "Section exists?" = the target step has a real section in a `PHASE_*` file.

| # | Ledger item | Origin | Evidence | Target step | Section exists? | Severity | Disposition |
|---|---|---|---|---|---|---|---|
| 1 | Infection CI wiring (PR diff-scoped + daily full run) | 2.1.8 | `step-2-1-8…md:18`; `PHASE_2:222` (Workflow 1b) | 2.2 | ✅ | Medium | Existing step |
| 2 | MSI ratchet from measured values + widen `infection.json.dist` past auth+user | 2.1.8 / 2.1.9 | `PHASE_2:154,162,350` | 2.9 | ✅ | Medium | Existing step |
| 3 | Breadth coverage targets (core 80 %+ / system 75 %+ / packages 60 %+) + `packages/` into measured coverage | 2.1.9 | `step-2-1-9…md:137,139`; `PHASE_2:349` | 2.9 | ✅ | Medium | Existing step |
| 4 | DB/kernel-bound unit gaps (`UserProvider` happy paths, `UserListener`, uncached `hasPermission`) | 2.1.8 → 2.1.9 | `step-2-1-8…md:94-97`, `step-2-1-9…md:140`; `PHASE_2:347` | 2.9 | ✅ | Medium | Existing step |
| 5 | Injectable clock (`Psr\Clock\ClockInterface`) for the 2 time-boundary Infection ignores (`DatabaseHandler::read`, `LoginAttemptListener`) | 2.1.8 | `step-2-1-8…md:98`; `PHASE_2:344`; ignores live in `infection.json.dist` | 2.9 | ✅ | Low | Existing step |
| 6 | PHPUnit doc-comment metadata (`ConfigManagerTest.php:13`, `tests/Unit/Migration/MigrationServiceTest.php`) + `failOnDeprecation`/`failOnPhpunitDeprecation`/`failOnNotice` flips + PHPUnit 12/13 evaluation | 2.1.14 (residual of 2.0.6/2.1.9) | `phpunit.xml.dist:38-41`; `PHASE_2:345-346`; both sites verified present | 2.9 | ✅ | Low | Existing step |
| 7 | Full E2E test-suite rework (Phase 1 audit 1.10.5 remnant) | 2.1.9 | `step-2-1-9…md:139` | 3.6.1 | ✅ (`PHASE_3:323`) | Medium | Existing step |
| 8 | TinyMCE 5.x iframe XSS mitigation via CSP `frame-src`/`object-src` | 2.1.13 | `step-2-1-13…md:109-111` | 3.2.1 | ✅ (`PHASE_3:84-92`, names TinyMCE explicitly) | **High (security)** | Existing step |
| 9 | Webpack-4-locked vulnerable transitives (picomatch, braces, micromatch, serialize-javascript, elliptic) | 2.1.13 | `step-2-1-13…md:112-113`; `PHASE_2:256` | 2.4 | ✅ | Medium | Existing step |
| 10 | TinyMCE 6+/7+ major (editor decision) | 2.1.13 | `step-2-1-13…md:114` ("Step 5.1 editor decision"); referenced from `PHASE_3:92` | 5.1 | ⚠️ ROADMAP row `5.1` exists (`ROADMAP.md:143`), but **no `PHASE_5_MODERNISING.md` exists** | Low | Existing ROADMAP slot; propose PHASE_5 stub at Phase-5 planning (§9-P7) |
| 11 | Raw-entity `jsonSerialize()` API responses → presenters/DTOs (`UserApiController`, `RoleApiController`, `WidgetApiController`, `CommentApiController`) | 2.1.10 / 2.1.11 | `PHASE_2:170,179`; carried in `PHASE_4:100` | 4.4 | ✅ | Medium | Existing step |
| 12 | ORM cache invalidation (`EntityManager::invalidateCache()` clears whole pool) + `NodeRepository` request-cache invalidation on save | 2.1.11 | in-code flag `EntityManager.php:322`; `PHASE_4:113-114` | 4.5 | ✅ | Medium | Existing step |
| 13 | `UrlResolver` static bridge removal (incl. `setPostRepository()` added in 2.1.11) + `RouteListener` permalink static + routing-factory DI + `theme-one` static `UrlProvider` + `UniqueValidator::setDb()` | 2.1.10 / 2.1.11 (roots in 1.8/2.0) | `packages/pagekit/blog/src/UrlResolver.php:26-43` (4 bridge tags); `packages/pagekit/theme-one/functions.php:8`; `PHASE_2:273-276` | 2.5 | ✅ | High | Existing step |
| 14 | Property Hooks vs `PropertyTrait` decision (spike, Go/No-Go) | 2.1.12 / 2.1.14 | `PHASE_2:187,205,314-319` | 2.8.1 | ✅ | Medium | Existing step |
| 15 | `MenuHelper` synthetic-root sentinel (magic `$nodes[0]`, `parent_id` 0/null mix) | 2.8.3 candidate (from 2.1.x reviews) | `app/system/modules/site/src/MenuHelper.php:102-104`; `PHASE_2:330` | 2.8.3 | ✅ | Low | Existing step — **verified still live** |
| 16 | PHPStan logic-hygiene baseline burndown (~33 suppressions: `deadCode.unreachable` 3, `*.alwaysTrue/False` family 21, `*.alreadyNarrowedType` 9, `new.static` in `Query/QueryBuilder` 1, `array.duplicateKey` in `StringTest` 1 — counts verified from baseline) | 2.1.4 – 2.1.6 baseline | identifier histogram of `phpstan-baseline.neon`; `PHASE_2:332` | 2.8.3 | ✅ | Medium | Existing step — **count claim "~30" verified (33)** |
| 17 | `InstallerIO` implicitly-nullable burndown | 2.8.3 candidate | **Already done in 2.1.14**: `InstallerIO.php:21` explicit `?Type`; `grep -c implicitlyNullable phpstan-baseline.neon` = 0 | 2.8.3 | ✅ (but obsolete) | — | **Stale — remove candidate** (§9-P3) |
| 18 | `#[\Override]` adoption across `app/` | 2.8.3 candidate | `rg '#\[\\?Override\]' app packages` = 0 hits (nothing adopted yet); `PHASE_2:333` | 2.8.3 | ✅ | Low | Existing step — verified still open |
| 19 | **`extract()` in production logic** driving ~29 baseline `variable.undefined`/`varTag.variableNotFound` suppressions: `Query/QueryBuilder.php:681,713,735` (`extract($this->parts)` — 10 errors), `ParamFetcher.php:80` (`extract($this->params[$index])` — 4), `PostApiController.php:61` (5), `CommentApiController.php:66` (3), `UserApiController.php:55,116` (5+2) | 2.1.4 – 2.1.6 baseline (never explicitly homed) | baseline entries + `rg "extract\(" …` | **none** | ❌ | Medium | **Orphan → propose adding to Step 2.8.3 scope** (§9-P4): replace `extract()` with explicit destructuring/typed locals; then drop the matching baseline entries |
| 20 | Mail-template suppressions (16 `variable.undefined` in `app/system/modules/user/mails/{welcome,verification,reset,approve}.php`) — same PhpEngine `extract()` render mechanism as views, but the `phpstan.neon` note only says "under `*/views/`" | baseline | baseline paths; `phpstan.neon:3-5` | — (accepted-by-design class) | — | Low | **Accept**; propose note-wording fix to include `mails/` templates (§9-P5) |
| 21 | Docs-site quality dashboard renders dead 8.2/8.3 PHPUnit matrix legs | 2.1.14 | `docs-site/content/javascripts/quality-dashboard.js:79-100`; `docs-site/data/quality-snapshot.demo.json:13-14`; `PHASE_2:230` | 2.2 | ✅ | Low | Existing step |
| 22 | Root `Dockerfile` multi-stage/Alpine redesign (2.1.14 only aligned `FROM php:8.5-apache`) | 2.1.14 | `Dockerfile:1`; `PHASE_2:245` | 2.3 | ✅ | Low | Existing step |
| 23 | Version SSoT guard (`composer.json` `require.php` → CI matrix / `requirements.php` / Dockerfile / README) | 2.1.14 | `PHASE_2:232` | 2.2 | ✅ | Low | Existing step |
| 24 | `IntlServiceLocator` — narrow permanent locator for global `__()` | 2.1.10 / 2.1.11 | `step-2-1-10…md:99`, `step-2-1-11…md:489-490` | — | — | — | **Accept** (Pagekit DNA: one minimal locator per concern; documented permanent) |
| 25 | Legitimate `mixed` (docblock array shapes, `__get`/`__set`, filter/loader/PSR-11 `get()`, polymorphic returns, callable properties) | 2.1.12 | `step-2-1-12…md:146`; `PHASE_2:187` | — | — | — | **Accept** (documented permanent non-goal) |
| 26 | Non-goals confirmed with homes: Symfony 7 → 4.2, DBAL 4 → 4.3, coverage-ratchet raise → 2.9 | 2.1.14 | `PHASE_2:205`; `PHASE_4:59,75` | 4.2 / 4.3 / 2.9 | ✅ | — | Existing steps |

**Orphan count: 1** (item 19 — proposal ready in §9-P4). The **view-template `variable.undefined` false positives** (110 suppressions under `*/views/` + 2-per-module `$app` bootstrap entries) are **excluded from this ledger** as accepted-by-design per the `phpstan.neon:1-12` header note — they are not debt.

---

## 8. Flag / TODO Reconciliation

Query: `rg -t php -n "TODO|AUDIT FIX|TEMPORARY BRIDGE|Must be refactored" app packages -g '!app/vendor/**'` → **24 flag lines**, classified below. **Flags referencing Step 2.1.\*: 0** — nothing to remove, nothing orphaned.

| Flag (tag → target) | Locations (`path:line`) | Classification |
|---|---|---|
| `TODO: Must be refactored in Step 2.5 (Extension Safety & Fault Isolation)` | `app/modules/routing/src/Matcher/Dumper/PhpMatcherDumper.php:18` | **Live** (2.5 ⏳; `PHASE_2:273` routing dumper) |
| `TODO: TEMPORARY BRIDGE - To be removed in Step 2.5` | `packages/pagekit/blog/src/UrlResolver.php:26,31,37,43`; `packages/pagekit/theme-one/functions.php:8` | **Live** (2.5 ⏳; `PHASE_2:274-275` names both bridges) |
| `TODO: Must be refactored in Step 3.4.6 (Translation System Modernization)` | `app/console/src/Commands/ExtensionTranslateCommand.php:149`; `app/console/src/NodeVisitor/PhpNodeVisitor.php:46`; `app/system/modules/intl/functions-pagekit-namespace.php:25`; `app/system/modules/intl/functions.php:26,51`; `app/system/modules/intl/src/Loader/PoFileLoader.php:108`; `app/system/modules/user/views/admin/user-index.php:1`; `app/system/modules/view/index.php:19`; `app/system/modules/widget/views/index.php:1`; `packages/pagekit/blog/views/admin/comment-index.php:1`; `packages/pagekit/blog/views/admin/post-index.php:1` | **Live** (3.4.6 ⏳; `PHASE_3:247`) |
| `TODO: AUDIT FIX Step 4.4` (dashboard weather API key → secrets) | `app/system/modules/dashboard/index.php:48` | **Live** (4.4 ⏳; consistent with `PHASE_2:299`) |
| `TODO: Must be refactored in Step 4.5 (Performance Optimization)` (cache-pool clear) | `app/modules/database/src/ORM/EntityManager.php:322` | **Live** (4.5 ⏳; `PHASE_4:113` references this exact flag) |
| `TODO: Step 5.6 (Marketplace & Extensions) — …` (feature notes, non-canonical prefix) | `app/installer/src/SelfUpdater.php:238`; `app/console/src/Commands/UpdateCommand.php:40`; `app/console/src/Commands/SelfupdateCommand.php:39`; `app/console/src/Commands/InstallCommand.php:40`; `app/console/src/Commands/BuildCommand.php:58` | **Live** (5.6 exists as ROADMAP row `ROADMAP.md:148`; no PHASE_5 file — see §7 item 10). Format deviates from the Rule-5 canon (`Must be refactored in Step X.Y`) — cosmetic, optional retag (§9-P6) |

**Result: 24/24 live · 0 stale · 0 orphan.** Every flag targets an open ROADMAP step; no flag narrates completed work.

---

## 9. Proposed ROADMAP / PHASE / Issue Changes (do not apply in this run)

- **P1 — `.cursor/ROADMAP.md` (on audit acceptance):**
  1. Row `2.1`: Status ⏳ → **✅**, Audit ⏳ → **🛡️** (keep PR `-`; the parent shipped through the 14 sub-step PRs).
  2. Row `2.1.1`: Audit ⏳ → **🛡️** (this audit verified 2.1.1's deliverables on the current tree: tooling in `composer.json`, `@PSR12` config, curated baseline).
  3. Header `Current Step`: `2.1 (Static Analysis & Code Quality - Audit)` → `2.2 (CI/CD Pipeline)`.
- **P2 — `migration-docs/TODO/PHASE_2_MODERNISING.md:78`:** replace the stale line `- **Open sub-steps**: 2.1.13 (TinyMCE), 2.1.14 (PHP 8.5). Completed work: see Docs under each ✅ row.` with `- **Status**: All 14 sub-steps complete. Completed work: see Docs under each ✅ row. Completion audit: migration-docs/audits/2026/07/AUDIT_REPORT_STEP_2.1_2026-07-23.md`.
- **P3 — `PHASE_2_MODERNISING.md` §2.8.3 (`:331`):** delete the `InstallerIO` implicitly-nullable candidate bullet — the work is done (explicit `?Type` at `InstallerIO.php:21`; 0 matching baseline entries). Enabling `nullable_type_declaration_for_default_null_value` in CS-Fixer can stay mentioned only if it is still desired repo-wide; otherwise drop the whole bullet.
- **P4 — `PHASE_2_MODERNISING.md` §2.8.3 (new candidate):** add an `extract()`-elimination candidate: replace `extract()` in production logic with explicit typed locals/destructuring — `app/modules/database/src/Query/QueryBuilder.php:681,713,735` (`extract($this->parts)` in SQL assembly), `app/modules/routing/src/Request/ParamFetcher.php:80`, `packages/pagekit/blog/src/Controller/PostApiController.php:61`, `packages/pagekit/blog/src/Controller/CommentApiController.php:66`, `app/system/modules/user/src/Controller/UserApiController.php:55,116` — then surgically drop the ~29 matching `variable.undefined`/`varTag.variableNotFound` baseline entries. Rationale: hidden variable creation defeats static analysis in non-template code; the controllers extract user-supplied filter arrays (mitigated by `EXTR_SKIP`, but explicit is safer and typed).
- **P5 — `phpstan.neon:1-12` header note:** widen the accepted-by-design wording from "view templates under `*/views/`" to "PhpEngine-rendered templates (`*/views/`, `*/mails/`)" so the 16 mail-template suppressions are explicitly covered by the documented decision.
- **P6 — optional flag retag:** normalize the 5 `// TODO: Step 5.6 (Marketplace & Extensions) — …` comments to the Rule-5 canonical form (`// TODO: Must be refactored in Step 5.6 (Marketplace & Extensions) — …`) for grep-ability. Cosmetic only.
- **P7 — Phase-5 planning (when it starts):** create `PHASE_5_MODERNISING.md` with at least §5.1 (Modern Block Editor — must absorb the TinyMCE ≥ 6.8.1-or-replace decision from `PHASE_3:92`) and §5.6 (Marketplace — absorbs the 5 in-code TODOs). Until then the ROADMAP rows are the accepted homes.
- **P8 — GitHub Issue #147:** apply the corrected body from §6.5, tick the 5 (re-worded) acceptance boxes, then **close as completed**. Optionally refresh the milestone description ("ROADMAP Steps 2.0 to 2.6" → "… 2.0 to 2.9").

No new sub-step IDs are required: every proposal lands in an existing step (2.8.3) or an existing artifact. No application-code change is proposed by this audit beyond what P4 routes into Step 2.8.3.

---

## 10. Appendix — Methodology & Queries

**Method:** every claim in `PHASE_2_MODERNISING.md` §2.1, the 13 branch docs, the ROADMAP rows, and Issue #147 was treated as a hypothesis and re-verified against the working tree (branch `feature/AGENT_PROMPT_AUDIT_STEP_2_1` @ `d2a57d4b`, which contains the merged PR #238) plus live GitHub state via `gh`. Order: baseline commands → docs cross-read → per-sub-step code probes → GitHub reconciliation → debt-ledger routing checks → flag sweep.

**Exact queries used (reproducible):**

```bash
# §2 baseline
php -v
grep -c "message:" phpstan-baseline.neon
grep -oP 'count:\s*\K[0-9]+' phpstan-baseline.neon | awk '{s+=$1} END{print s+0}'
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M 2>&1 | tail -5
./app/vendor/bin/phpunit --colors=never 2>&1 | tail -6
rg -L -t php --files-without-match 'declare\(strict_types=1\)' app packages -g '!app/vendor/**' | wc -l

# per-sub-step probes
grep -n '"tinymce"' package.json && grep -A1 '^tinymce@' yarn.lock          # 2.1.13
grep -n "8\.5" index.php | head -3                                          # 2.1.14 runtime guard
rg -n "REQUIRED_PHP_VERSION" app/installer/requirements.php                 # 2.1.14 installer guard
rg -n "MYSQL_ATTR_INIT_COMMAND|Pdo\\\\Mysql" app packages -g '!app/vendor/**'
rg -n "transient" app/modules/database/src/ORM/PropertyTrait.php
sed -n '/function getRelations/,/^    }/p' app/modules/database/src/ORM/QueryBuilder.php
rg -n "InputInterface|OutputInterface|HelperSet" app/installer/src/Helper/InstallerIO.php
grep -c "implicitlyNullable" phpstan-baseline.neon
rg -n "ModelServiceLocator" app packages -g '!app/vendor/**'                # 2.1.10 (0 hits)
rg -n "public static function (query|find|where|create)\b" app packages -g '!app/vendor/**'  # 2.1.11 (0 hits)
rg -n "public function execute\(" app/modules/database/src                  # 2.1.7 (0 hits)
rg -n "json_array|MySqlPlatform" app packages -g '!app/vendor/**'           # 2.1.7 (0 hits)
rg -l "DebugStack" app packages -g '!app/vendor/**'                         # 2.1.6 (class file deleted)
grep -n "failOn" phpunit.xml.dist                                           # 2.1.8→2.1.9 flips + 2.9 residue
grep -n "FROM" Dockerfile                                                    # 2.1.14 (php:8.5-apache)

# baseline composition (§7 items 16/19/20)
grep -oP 'identifier:\s*\K\S+' phpstan-baseline.neon | sort | uniq -c | sort -rn
awk '/identifier: variable.undefined/{getline; while($0 !~ /path:/) getline; print}' phpstan-baseline.neon | grep -vc "views"
rg -n "extract\(" app/modules/database/src/Query/QueryBuilder.php app/modules/routing/src/Request/ParamFetcher.php \
  packages/pagekit/blog/src/Controller/{Post,Comment}ApiController.php app/system/modules/user/src/Controller/UserApiController.php

# flags (§8)
rg -t php -n "TODO|AUDIT FIX|TEMPORARY BRIDGE|Must be refactored" app packages -g '!app/vendor/**'
rg -t php -n "Step 2\.1" app packages -g '!app/vendor/**'                    # 0 hits

# GitHub (§6)
gh issue view 147 --repo Shadesman5/pagekit --json number,title,state,body,labels,milestone
for n in 148 149 150 151 152 153 154 155 156 204 205 217 230 231; do gh issue view $n --repo Shadesman5/pagekit --json number,title,state; done
for n in 178 199 201 203 210 212 215 216 218 219 222 227 234 238; do gh pr view $n --repo Shadesman5/pagekit --json number,state; done
gh api graphql -f query='query { repository(owner: "Shadesman5", name: "pagekit") { issue(number: 147) { subIssues(first: 20) { totalCount nodes { number state title } } } } }'
gh run list --repo Shadesman5/pagekit --branch develop --workflow "PHP Quality" --limit 3 --json conclusion,createdAt
```

**Success-criteria self-check:** 14/14 sub-steps verified with `path:line` evidence + verdicts (§4) · all discrepancies flagged (§5) · #147 stale items + corrected body + closeability decision delivered (§6) · residual debt fully inventoried incl. 2.8.3 candidates and genuine baseline debt, false-positive suppressions excluded (§7) · all in-code flags reconciled, zero Step-2.1 flags (§8) · no code/doc/issue modified — report only · proposals stay DNA-lean (no new tooling, no new layers; the only new work item routes into the existing 2.8.3 hygiene sweep).
