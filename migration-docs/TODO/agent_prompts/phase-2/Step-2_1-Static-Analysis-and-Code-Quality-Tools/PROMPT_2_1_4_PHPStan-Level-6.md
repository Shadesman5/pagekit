# Step 2.1.4: PHPStan Level 5→6 (Return Types)

**ROADMAP:** 2.1.4. GitHub Issue: #151. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.3 (`strict_types` Migration)
- **Risk:** Low — mechanical work, high volume but simple patterns
- **What Level 6 checks:** Missing return type declarations. Every method must declare its return type.
- **Scope:** 1 PR for the entire step. Granularity (number of Checklist Steps) is determined by the Architect during ticket planning, based on the discovery output from §1.2 — the migration order in §1.3 is a recommendation, not a contract.

**Goal:** Add missing return types to all methods so PHPStan passes at Level 6 with no new baseline entries.

---

## 0. SAFETY CHECKS (CRITICAL)

**AFTER EVERY CHECKLIST STEP (Tester subagent):**
```bash
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```
**IF ANY FAILS → STOP AND FIX before proceeding to the next Checklist Step!**

**Before starting:** Verify the branch is up-to-date with `develop` and Step 2.1.3 (`strict_types` Migration) is closed (PR ready for review or merged).

---

## 1. PREPARATION

### 1.1. Bump PHPStan level

Update `phpstan.neon`:

```neon
parameters:
    level: 6
```

### 1.2. Discover errors

```bash
./app/vendor/bin/phpstan analyse --no-progress 2>&1 | head -100
./app/vendor/bin/phpstan analyse --no-progress 2>&1 | wc -l
```

Count total errors and group by file/directory. These are overwhelmingly **"Missing return type"** errors at Level 6.

### 1.3. Plan Checklist Steps

The Architect determines the number of Checklist Steps based on the §1.2 error volume per directory — many small errors can collapse into one Checklist Step per directory; very large modules may warrant a split. Recommended migration order (one or more Checklist Steps per group):

1. `app/modules/` (core infrastructure)
2. `app/system/` (system modules)
3. `app/installer/` + `app/console/`
4. `packages/`

The Architect is free to add extra Checklist Steps for the cross-cutting Audit Findings (§3), Known Issues (§4), and the Bugbot constructor-`mixed` sweep (§5 — large scope, ~155 occurrences across 40+ files, often warrants its own Checklist Steps grouped by directory) if they don't fit naturally into one of the directory groups above.

---

## 2. ADD RETURN TYPES

### 2.1. Common patterns

```php
// BEFORE:
public function getName() { return $this->name; }

// AFTER:
public function getName(): string { return $this->name; }
```

### 2.2. Decision rules

| Return pattern | Type declaration |
|---------------|-----------------|
| Always returns string | `: string` |
| Returns string or null | `: ?string` |
| Returns array | `: array` |
| Returns void (no return) | `: void` |
| Returns self/static | `: static` or `: self` |
| Returns multiple types | `: string\|int` (union type) |
| Returns mixed (truly unknown) | `: mixed` |
| Interface method — keep compatible | Match interface signature |

### 2.3. Avoid over-using `mixed`

`mixed` is a fallback. Before using it, check:
- Can the type be narrowed? (e.g. `mixed` → `string|int|null`)
- Is this a container/registry pattern where `mixed` is genuinely correct?
- **PSR-11 `Container::get()`** — keep as-is (`mixed` per PSR-11 contract from Step 2.0.1; do not narrow).

### 2.4. Clean up union types

If a method returns `string|int`, consider: is this intentional or a legacy artifact? If the method can be simplified to return one type, do so. If not, the union type is fine.

---

## 3. AUDIT FINDINGS (Phase 1 Review)

The following typing issues were identified during the Phase 1 codebase audit and **must** be resolved at this level. The Architect is free to add further findings discovered during the §1.2 discovery sweep.

- **`mixed` mailer type in 3 controllers** (`MailController`, `ResetPasswordController`, `RegistrationController`) — should be `Pagekit\Mail\Mailer`. Verified locations:
  - `app/system/modules/mail/src/Controller/MailController.php`
  - `app/system/modules/user/src/Controller/ResetPasswordController.php`
  - `app/system/modules/user/src/Controller/RegistrationController.php`
- **`Mailer::send()`** missing `: bool` return type (in `app/modules/mail/src/`)
- **`Message::send(&$errors)`** untyped out-parameter — type as `?array &$errors = null` or replace with return value (in `app/modules/mail/src/`)
- **`Post` model** — relation properties `$user`, `$comments` typed as `mixed` instead of `?User`, `?array`. Verified location: `packages/pagekit/blog/src/Model/Post.php`
- **Console `execute()` methods** — missing `: int` return types, some use `exit` instead of `return Command::SUCCESS` (`SetupCommand`, `TranslationFetchCommand`, `ExtensionTranslateCommand` — all under `app/console/src/Commands/`)
- **`Logger::__invoke()`** without parameter/return types (search `app/modules/log/` or wherever the Logger lives)
- **`TwigLoader::findTemplate()`** / **`TwigCache::__construct()`** missing parent-compatible types (search `app/modules/view/src/Twig/` or equivalent)
- **`mail/index.php`** — unused `auth_mode` config key. Remove dead config (not a typing fix, but caught in the same audit pass).

> **Note:** Specific paths above are audit-verified anchors. The Architect is expected to do a brief discovery sweep (`grep`/`Grep` for the symbol names) to confirm current locations and pick up any related findings the original audit missed — do not treat the list as exhaustive.

---

## 4. KNOWN ISSUES (from 2.1.1 review)

### 4.1. AuthDataCollector — UserInterface mismatch

**Problem:** `Auth::getUser()` returns `UserInterface` (only `getId()`, `getUsername()`, `getPassword()`), but `AuthDataCollector` calls `isAuthenticated()` and `User::findRoles($user)` which only exist on the concrete `User` class. PHPStan Level 6 will flag this as calling undefined methods on `UserInterface`.

**Recommended fix — `instanceof User` type-narrow** in the DataCollector (no Interface change). Rationale:

- **Pagekit philosophy** — Phase 2.1 principle states *"Tools for developers, core stays lightweight!"* Extending `UserInterface` with a `isAuthenticated()` method just to satisfy a debug-bar collector pollutes the core auth contract for a developer-tools concern.
- **Single Responsibility** — the DataCollector is the only consumer that needs the concrete-`User` API surface (`isAuthenticated()`, static `findRoles()`); narrowing locally keeps the concern localized.
- **Backward compatibility for extensions** — extensions that ship custom `UserInterface` implementations (LDAP, OAuth, headless auth) would otherwise be forced to implement `isAuthenticated()` for Pagekit's own debug bar. With `instanceof`, they see no impact.
- **Modern PHP / static analysis** — PHPStan narrows the type after `instanceof` and the rest of the method type-checks cleanly without further annotations.

**Sketch:**

```php
$user = $this->auth->getUser();
if (!$user instanceof User) {
    return; // or set empty data — debug bar shows no user
}
// PHPStan now narrows $user to User; isAuthenticated() + findRoles() are valid
$authenticated = $user->isAuthenticated();
$roles = User::findRoles($user);
```

**Files:** `app/modules/debug/src/DataCollector/AuthDataCollector.php`. (`UserInterface` is not modified.)

---

## 5. BUGBOT FINDINGS (from Step 2.0.1c, PR #169)

### 5.1. `mixed` constructor properties across 40+ controllers

During the PSR-11 Stage 3 migration (Step 2.0.1c, PR #169), Bugbot flagged that controllers, listeners, helpers, and event subscribers use `mixed` typing for constructor-injected service properties instead of their actual container service types. This was pragmatic at the time — the `ControllerResolver` resolves services by parameter **name**, not type — and was deferred to Step 2.1.4 once the final container service types stabilized after Steps 2.0.1d / 2.0.1e.

Now, with PHPStan Level 6 enforced, every `private readonly mixed $foo` constructor parameter must be replaced with the concrete container-resolved type.

**Scope:** ~155 occurrences across 40+ source files in `app/system/`, `app/installer/`, `app/console/`, `packages/pagekit/blog/`, plus 1 test file (`app/modules/kernel/src/Tests/ControllerResolverTest.php`). Numbers are indicative — the Architect re-runs `grep -rn "readonly mixed \$" app/ packages/ | wc -l` during ticket planning for the current count.

**Common replacements (from the original Bugbot example in `DashboardController`):**

| `mixed` parameter name | Concrete type                                |
|------------------------|----------------------------------------------|
| `$module`              | `Pagekit\Module\ModuleManager`               |
| `$request`             | `Symfony\Component\HttpFoundation\Request`   |
| `$response`            | `Symfony\Component\HttpFoundation\Response`  |
| `$session`             | `Pagekit\Session\Session`                    |
| `$auth`                | `Pagekit\Auth\Auth`                          |
| `$translator`          | `Symfony\Contracts\Translation\TranslatorInterface` |
| `$validator`           | `Symfony\Component\Validator\Validator\ValidatorInterface` |
| `$version` / `$key`    | `string`                                     |

The exact type for each parameter is derived from the container service registration in the corresponding `index.php` or `ServiceProvider`. **Do not** type as the interface if the consumer calls concrete-class methods (same Single-Responsibility logic as §4.1 — interface widening for one consumer is interface pollution).

**Sweep strategy:** Architect groups by directory — one Checklist Step per `app/system/modules/{module}/Controller/` folder is reasonable; large folders (e.g. `user/Controller/` with `ResetPasswordController` 11, `RegistrationController` 10, `AuthController` 10 — 31 occurrences in three files) may warrant a dedicated Checklist Step.

**Top-hit files (orientation, not exhaustive — re-verify during ticket planning):**

- `ResetPasswordController` (11), `RegistrationController` (10), `AuthController` (10) — `app/system/modules/user/src/Controller/`
- `PackageController` (7) — `app/installer/src/Controller/`
- `ProfileController` (6), `FinderController` (6), `ControllerResolverTest` (6 — test file, may need different handling)
- `DashboardController` (5), `MenuApiController` (5), `UserApiController` (5), `NodeApiController` (5)

**Test-file note:** `app/modules/kernel/src/Tests/ControllerResolverTest.php` uses `readonly mixed` deliberately to test the resolver's name-based resolution. The Architect decides whether to leave the 6 occurrences as-is (acceptable test-only exception, matches the resolver behavior under test) or to add a `@phpstan-ignore` annotation with a clear rationale. **Document the decision in the commit message.**

---

## 6. BASELINE CLEANUP (Surgical Removal — no regeneration)

After all return types and constructor types are in place (§2 + §3 + §4 + §5), **do not** regenerate the baseline. Same pattern as Step 2.1.3:

- Run `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M`. The output should report **no new errors** (Level 6 fully covered).
- For every existing baseline entry that is now reported as "unmatched" (= the underlying error was eliminated by a return-type fix in this step), **surgically remove that entry** from `phpstan-baseline.neon`. One entry per resolved error, never wholesale.
- **Never** run `--generate-baseline`. Wholesale regeneration would mask any real new error this step might have introduced and breaks the Tester's `git diff --quiet phpstan-baseline.neon` regression check.
- **Never** add `@phpstan-ignore` comments, baseline entries, or inline `@var` overrides to silence Level-6 errors. If a method genuinely cannot have a return type declared (e.g. magic methods with multiple legitimate returns), document the exception in the commit message.
- Final state: PHPStan exits 0; baseline diff is *removals only* (or zero changes if no entries become unmatched).

---

## SUCCESS CRITERIA

- PHPStan Level 6 passes (exit 0, no new baseline entries)
- All §3 Audit Findings resolved
- §4.1 AuthDataCollector mismatch resolved via `instanceof User` (or documented architectural alternative)
- §5.1 Bugbot constructor-`mixed` sweep complete: zero `private readonly mixed $` left in the source codebase (test file `ControllerResolverTest.php` is the only acceptable exception, with documented rationale)
- All methods have explicit return types
- All PHPUnit tests pass
- `phpstan-baseline.neon` diff is *removals only* (or empty)

---

## VALIDATION CHECKLIST

- [ ] `phpstan.neon` level set to 6
- [ ] `app/modules/` methods typed
- [ ] `app/system/` methods typed
- [ ] `app/installer/` + `app/console/` methods typed
- [ ] `packages/` methods typed
- [ ] §3 Audit Findings (Mailer, Post, Console execute, Logger, TwigLoader/TwigCache, mail/index.php) resolved
- [ ] §4.1 AuthDataCollector `instanceof User` narrow applied
- [ ] §5.1 Bugbot sweep — `grep -rn "readonly mixed \$" app/ packages/ | grep -v Tests/ | wc -l` returns `0`
- [ ] §5.1 Bugbot sweep — `ControllerResolverTest.php` decision documented in commit message
- [ ] `./app/vendor/bin/phpstan analyse` passes at Level 6
- [ ] All PHPUnit tests pass
- [ ] `phpstan-baseline.neon` cleaned (surgical removals only — `git diff` shows removals or no changes, never additions)
