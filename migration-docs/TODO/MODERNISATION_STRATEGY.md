# Pagekit CMS Modernisation Strategy

> This document defines the **vision, philosophy, and strategic decisions** behind the Pagekit modernisation.
> It is intentionally static — **step-level progress tracking lives exclusively in [ROADMAP.md](../../.cursor/ROADMAP.md)**.
> The sole exception is the [Quality Metrics Tracker](#-quality-metrics-tracker) below: a lightweight, append-only snapshot of code-quality signals (PHPStan, tests) over time, which has no natural home in the step-status table.

## Overview

This strategic plan guides Pagekit CMS from its current legacy base to a modern, secure, and maintainable system. Each step is executed in a dedicated Git branch and reviewed via pull requests.

**Key Principle: Fully modernize first, then build new features!**

**Agent pipeline (how work is executed):** Autonomous modernization runs via the Conductor + subagent
Orchestrator workflow (Plan → Execute → Finalize). Human reference:
[`.cursor/WORKFLOW_SUBAGENTS.md`](../../.cursor/WORKFLOW_SUBAGENTS.md).

## Phase Definitions

| Phase | Name                       | Goal                                                               | Detail File                                          |
| ----- | -------------------------- | ------------------------------------------------------------------ | ---------------------------------------------------- |
| 0     | Preparation                | Stable, traceable baseline                                         | _(completed)_                                        |
| 1     | Core Backend Modernisation | Renew the foundation (PHP 8.2+, Symfony 6.4, Doctrine DBAL 3)      | [PHASE_1_MODERNISING.md](PHASE_1_MODERNISING.md)     |
| 2     | Developer Experience       | Quality tools, CI/CD, Docker, static analysis                      | [PHASE_2_MODERNISING.md](PHASE_2_MODERNISING.md)     |
| 3     | Frontend Modernization & Cross-Stack Alignment | Vue 3, UIkit 3.21+, TypeScript, translation/Intl alignment, service-layer DI | [PHASE_3_MODERNISING.md](PHASE_3_MODERNISING.md)     |
| 4     | Production-Ready Release   | Essential features for Pagekit 2.0 (2FA, REST API v2, Performance) | [PHASE_4_MODERNISING.md](PHASE_4_MODERNISING.md)     |
| 5     | Advanced & Enterprise      | Optional post-2.0 features, extensions, marketplace                | [PHASE_5_FUTURE_VISION.md](PHASE_5_FUTURE_VISION.md) |

---

## 📊 Quality Metrics Tracker

> Append-only snapshot of code-quality signals at each **PHPStan level milestone**. This is the one living
> exception to the "static document" rule above (see header). Step _status_ stays in [ROADMAP.md](../../.cursor/ROADMAP.md);
> this table tracks _trends_ (are errors/tests going up or down over the modernisation?).
>
> **Note:** PHPStan was introduced **directly at Level 5** in Step 2.1.1 — there is no "pre-Level-5" data point;
> Level 5 is the project's analysis baseline.

| Date       | Milestone (PR)                     | PHPStan Level | Baseline blocks | Suppressed errors | Unit Tests | E2E Specs |
| ---------- | ---------------------------------- | :-----------: | :-------------: | :---------------: | :--------: | :-------: |
| 2026-03-29 | Step 2.1.1 — Baseline setup (#178) |   5 (start)   |       517       |        891        |   326 ¹    |    25     |
| 2026-05-04 | Step 2.1.4 — Level 5→6 (#203)      |       6       |       367       |        680        |    326     |    25     |
| 2026-06-21 | Step 2.1.5 — Level 6→7 (#210)      |       7       |       345       |        654        |    326     |    25     |
| 2026-07-01 | Step 2.1.6 — Level 7→8 (#212)      |       8       |       331       |        632        |    328     |    25     |

**Suppressed-error trend:** 891 → 680 (−211 / −23.7%) → 654 (−26 / −3.8%). **Lower is better** — each level bump
fixes real issues and surgically removes baseline entries (never `--generate-baseline`, never adds entries).

**E2E** is constant at 25 specs (installation 1 + authentication 14 + dashboard 10) until the suite is expanded
in a later step (see ROADMAP Step 3.6 / 2.1.9).

¹ The first **CI-verified** suite count (326 tests / 765 assertions) was recorded in Step 2.1.2 (#199), when the
CI test gate was introduced. Step 2.1.1 predates the gate, so its suite size was not counted automatically;
the value shown is the nearest CI-verified figure. The PHPStan-level steps (2.1.3–2.1.5) kept the count stable
at 326 by design — pure typing work adds no tests.

**How to append a row** (run from repo root after a level bump):

```bash
grep -c "message:" phpstan-baseline.neon                          # baseline blocks
grep -oP 'count:\s*\K[0-9]+' phpstan-baseline.neon | awk '{s+=$1} END{print s+0}'   # suppressed errors (awk: portable, bc is not always installed)
./app/vendor/bin/phpunit --colors=never 2>&1 | grep -E '^(OK \(|Tests:)'  # unit test total (green: "OK (N tests…)"; issues: "Tests: N…")
```

---

## 🎯 Pagekit DNA: Stay Lightweight & Modular!

**⚠️ IMPORTANT: We are NOT building WordPress 2.0!**

Pagekit's core philosophy:

- ✅ **Lightweight** — Minimal core, features via extensions
- ✅ **Modular** — Everything is a module/extension
- ✅ **Simple** — Clear structure, no bloatware
- ✅ **Developer-friendly** — Modern, but not overloaded

**DX vs. minimal glue (modernization balance):** The 5 Aggressive Rules forbid compatibility layers and adapters, but **do not** forbid intentional **platform APIs** for extension and template authors. Globals like `__()`, `_i()`, `$date`, and `$number` are thin DX aliases backed by Symfony services — not legacy shims. **New or modernized platform APIs are welcome** when they improve extension/template DX, stay lean and secure under the hood, and add at most one minimal glue point per concern. Use **constructor injection** in controllers, services, and listeners; reserve globals for template/theme boundaries where PHP has no DI — never force DI there for purity alone. Static locators (`IntlServiceLocator`) are **minimal glue only** — one per concern, no chains. Full rule text: `.cursor/ROADMAP.md` § DX & Lightweight Philosophy.

### How Does Our Roadmap Align?

#### Phases 1-3: Foundation ✅ FITS!

- Modernizing existing core features
- No new features, just a better foundation
- Stays lean and focused

#### Phase 4: Production (2.0.0) ✅ FITS!

**Core Features (built-in):**

- ✅ 2FA — Security essential
- ✅ **OAuth2 Client** — Social Login (Google, GitHub, etc.)
- ✅ REST API v2 — Modern interface
- ✅ Performance — Cache, optimization
- ✅ Monitoring — Production essentials

**Why include these in the core?**

- Required for production
- Low complexity (with libraries)
- Modern expectation (social login is standard)
- Stable and proven

#### Phase 5: Advanced (2.1.0+) ⚠️ CAUTION!

**Implement as extensions (NOT core!):**

- ⚠️ **PKBlocks Editor** → **Extension** (block editor, optional!)
- ⚠️ **OAuth2 Server** → **Extension** (Pagekit as auth provider, enterprise!)
- ⚠️ **SAML/SSO** → **Extension** (enterprise customers)
- ⚠️ **Multi-Tenancy** → **Extension** (complex setups + test environments)
- ⚠️ **AI Assistant** → **Extension** (specialized use cases)
- ⚠️ **PWA** → **Extension** (not everyone needs it)

### 📦 Extension-First Strategy

**Rule for Phase 5:**

1. **Question:** Does EVERYONE need this?

   - ✅ Yes → Core
   - ❌ No → Extension

2. **Question:** Does it significantly increase complexity?

   - ✅ Yes → Extension
   - ❌ No → Core (with feature flag)

3. **Question:** Is it a niche feature?
   - ✅ Yes → Extension
   - ❌ No → Evaluate

**Examples:**

- ✅ **2FA Core** — Security is needed by everyone
- ✅ **OAuth2 Client Core** — Social login is a modern standard
- ❌ **OAuth2 Server Extension** — Auth provider is an enterprise feature
- ❌ **Block Editor Extension** — Not everyone needs block-style editing
- ✅ **REST API Core** — Modern API is standard
- ❌ **GraphQL Extension** — Niche feature
- ✅ **Cache Core** — Performance is needed by everyone
- ❌ **Multi-Tenancy Extension** — Specialized requirement (test + enterprise)

### 🔄 Decision Tree: Core vs. Extension

```
New feature planned?
    │
    ├─ Does EVERY user need it?
    │  ├─ YES → Continue
    │  └─ NO → ⚠️ EXTENSION
    │
    ├─ Is it a security/performance essential?
    │  ├─ YES → ✅ CORE
    │  └─ NO → Continue
    │
    ├─ Does it significantly increase core complexity?
    │  ├─ YES → ⚠️ EXTENSION
    │  └─ NO → Continue
    │
    ├─ Can it be provided as a service/API?
    │  ├─ YES → ✅ CORE (as API/service)
    │  └─ NO → Continue
    │
    └─ Default: ⚠️ EXTENSION (when in doubt)
```

### 🎨 Marketplace as the Solution

**Phase 5.6: Marketplace** becomes the key:

- Easy extension installation
- Officially vetted extensions
- Community extensions
- Theme store

### 📏 Size Comparison: Are We Staying Lightweight?

**Pagekit 2.0.0 (Target):**

```
Core System: ~10 MB (slight increase from modern features)
Vendor: ~15 MB (Symfony 6.4, modern dependencies)
Total: ~25 MB ✅ STILL LIGHTWEIGHT!

Comparison:
- WordPress: ~50-80 MB core (without plugins!)
- Drupal: ~100+ MB
- Joomla: ~40-60 MB
- Ghost: ~30 MB
- Pagekit 2.0: ~25 MB ✅ PERFECT!
```

**Rule:**

- ✅ Core stays under 30 MB
- ✅ Extensions optionally installable
- ✅ Users decide what they need

### 🚫 What We Do NOT Want to Become

- ❌ WordPress clone (too bloated)
- ❌ Drupal-complex (too complicated)
- ❌ Joomla-chaos (too confusing)

**What we want to be:**

- ✅ Ghost-like (modern, focused)
- ✅ Statamic-like (developer-friendly)
- ✅ Pagekit 2.0 (lightweight, modular, modern)

---

## 🛠 Foundation First Strategy

**Why modernize everything first, then build new features?**

1. **No double work**: Build features on a modern stack instead of migrating them later
2. **Better quality**: New features directly use modern best practices
3. **Fewer bugs**: Testing infrastructure is in place before new features arrive
4. **Easier maintenance**: Consistent, modern codebase

---

## 📊 Versioning Strategy

**IMPORTANT: The MAJOR.MINOR.PATCH strategy is intended from Phase 4 onwards; before that it is STATE.MAJOR.MINOR-PATCH**

### PATCH Version (1.0.x → 1.0.y)

- ✅ **Bug fixes** — Fixing defects
- ✅ **Security patches** — Security updates
- ✅ **Dependency updates** — Updating dependencies
- ✅ **Modernizations** — Code improvements without new features

### MINOR Version (1.x.0 → 1.y.0)

- 🆕 **New features** — New functionality
- 🔄 **Phase completion** — An entire phase has been completed
- 🏗️ **Architecture changes** — Major structural improvements
- ⚠️ **Breaking changes** — Changes that may affect extensions

### MAJOR Version (x.0.0 → y.0.0)

- 🚀 **Production release** — System is production-ready
- 💥 **Massive breaking changes** — Fundamental architecture changes
- 🎉 **Completely new version** — New generation of the system

---

## 🧭 Core Principles

1. **PHP Version**: Minimum PHP 8.2 for modern features
2. **Test First**: Every change must be backed by tests
3. **Incremental**: Small, testable steps instead of big-bang updates
4. **Documentation**: Every phase produces documentation
5. **No Backward Compatibility**: Remove radically!
6. **Security First**: Security always has priority!

## ⚠️ Risk Management

- ⚠️ **Breaking Changes** — Document and communicate
- ⚠️ **Extension Compatibility** — Review and migrate, no compatibility layers!
- ⚠️ **Performance Regression** — Prevent through benchmarking
- ⚠️ **Security Vulnerabilities** — Patch immediately
- ⚠️ **Technical Debt** — Reduce continuously
