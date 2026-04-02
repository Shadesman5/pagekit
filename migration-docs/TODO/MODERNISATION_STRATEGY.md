# Pagekit CMS Modernisation Strategy

> This document defines the **vision, philosophy, and strategic decisions** behind the Pagekit modernisation.
> It is intentionally static — progress tracking lives exclusively in [ROADMAP.md](../../.cursor/ROADMAP.md).

## Overview

This strategic plan guides Pagekit CMS from its current legacy base to a modern, secure, and maintainable system. Each step is executed in a dedicated Git branch and reviewed via pull requests.

**Key Principle: Fully modernize first, then build new features!**

## Phase Definitions

| Phase | Name                       | Goal                                                               | Detail File                                          |
| ----- | -------------------------- | ------------------------------------------------------------------ | ---------------------------------------------------- |
| 0     | Preparation                | Stable, traceable baseline                                         | _(completed)_                                        |
| 1     | Core Backend Modernisation | Renew the foundation (PHP 8.2+, Symfony 6.4, Doctrine DBAL 3)      | [PHASE_1_MODERNISING.md](PHASE_1_MODERNISING.md)     |
| 2     | Developer Experience       | Quality tools, CI/CD, Docker, static analysis                      | [PHASE_2_MODERNISING.md](PHASE_2_MODERNISING.md)     |
| 3     | Frontend Modernisation     | Vue 3, UIkit 3.21+, TypeScript, modern build tools                 | [PHASE_3_MODERNISING.md](PHASE_3_MODERNISING.md)     |
| 4     | Production-Ready Release   | Essential features for Pagekit 2.0 (2FA, REST API v2, Performance) | [PHASE_4_MODERNISING.md](PHASE_4_MODERNISING.md)     |
| 5     | Advanced & Enterprise      | Optional post-2.0 features, extensions, marketplace                | [PHASE_5_FUTURE_VISION.md](PHASE_5_FUTURE_VISION.md) |

### Visual Roadmap

```
Phase 1: Core Backend Modernization
┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
    ├─ Mailer, PHPUnit, Security   ┃
    ├─ Doctrine DBAL 3.x           ┃
    ├─ PSR-11, Events, Routing     ┃
    ├─ Symfony 6.4 LTS, PSR-6     ┃
    ├─ ORM, DB Migrations          ┃
    └─ Validation, Attributes ━━━━┛
          ⬇️
Phase 2: Developer Experience
┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
    ├─ Foundation Consolidation:         ┃
    │  ├─ Attributes, Container, Cache   ┃
    │  └─ Validator, Package/Migrations  ┃
    ├─ Static Analysis (PHPStan)         ┃
    ├─ CI/CD Pipeline                    ┃
    ├─ Docker Production                 ┃
    ├─ Build Tools Modernization         ┃
    └─ Extension Safety System ━━━━━━━━━┛
          ⬇️
Phase 3: Frontend Modernization
┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
    ├─ UIkit 3.5 → 3.21+ Update         ┃
    ├─ Vue 2.7 Bridge                    ┃
    ├─ CSP Template Pre-compilation      ┃
    ├─ TypeScript Integration            ┃
    ├─ Vue 3 Migration:                  ┃
    │  ├─ vue-resource → axios           ┃
    │  ├─ vue-event-manager → mitt       ┃
    │  ├─ Vue 3 Core + @vue/compat       ┃
    │  ├─ Pinia State Management         ┃
    │  └─ vee-validate v4, lodash → ES6  ┃
    ├─ Component Library                 ┃
    └─ E2E Selector Strategy ━━━━━━━━━━━┛
          ⬇️
Phase 4: Production-Ready (→ 2.0.0)
┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
    ├─ 2FA + OAuth2 Social Login 🔐┃
    ├─ REST API v2 (OpenAPI, JWT)  ┃
    ├─ Performance Optimization    ┃
    └─ Monitoring & Health ━━━━━━━┛ PRODUCTION READY 🚀
          ⬇️
Phase 5: Advanced & Enterprise (Post-2.0)
┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
    ├─ PKBlocks Editor 📝          ┃
    ├─ OAuth2 Server               ┃
    ├─ Advanced Performance        ┃
    ├─ Advanced CMS (PWA, AI)      ┃
    ├─ Enterprise (Multi-Tenancy)  ┃
    └─ Marketplace & Store ━━━━━━━┛
          ⬇️
Future: Next Generation (3.x+)
┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
    ├─ Headless CMS Mode           ┃
    ├─ Cloud-Native                ┃
    ├─ Advanced AI                 ┃
    └─ Mobile-First ━━━━━━━━━━━━━━┛
```

---

## 🎯 Pagekit DNA: Stay Lightweight & Modular!

**⚠️ IMPORTANT: We are NOT building WordPress 2.0!**

Pagekit's core philosophy:

- ✅ **Lightweight** — Minimal core, features via extensions
- ✅ **Modular** — Everything is a module/extension
- ✅ **Simple** — Clear structure, no bloatware
- ✅ **Developer-friendly** — Modern, but not overloaded

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
