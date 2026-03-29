# Pagekit CMS Modernisation Strategy

> This document defines the **vision, philosophy, and strategic decisions** behind the Pagekit modernisation.
> It is intentionally static — progress tracking lives exclusively in [ROADMAP.md](../../.cursor/ROADMAP.md).

## Übersicht

Dieser strategische Plan führt Pagekit CMS von der aktuellen Legacy-Basis zu einem modernen, sicheren und wartbaren System. Jeder Schritt wird in einem eigenen Git-Branch durchgeführt und über Pull Requests geprüft.

**Wichtiges Prinzip: Erst komplett modernisieren, dann neue Features bauen!**

## Phase Definitions

| Phase | Name | Goal | Detail File |
|---|---|---|---|
| 0 | Preparation | Stable, traceable baseline | *(completed)* |
| 1 | Core Backend Modernisation | Renew the foundation (PHP 8.2+, Symfony 6.4, Doctrine DBAL 3) | `PHASE#1_MODERNISING.md` |
| 2 | Developer Experience | Quality tools, CI/CD, Docker, static analysis | `PHASE#2_MODERNISING.md` |
| 3 | Frontend Modernisation | Vue 3, UIkit 3.21+, TypeScript, modern build tools | `PHASE#3_MODERNISING.md` |
| 4 | Production-Ready Release | Essential features for Pagekit 2.0 (2FA, REST API v2, Performance) | `PHASE#4_MODERNISING.md` |
| 5 | Advanced & Enterprise | Optional post-2.0 features, extensions, marketplace | `PHASE#5_FUTURE_VISION.md` |

### Visuelle Roadmap

```
Phase 1: Core Backend-Modernisierung
┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
    ├─ Mailer, PHPUnit, Security   ┃
    ├─ Doctrine DBAL 3.x           ┃
    ├─ PSR-11, Events, Routing     ┃
    ├─ Symfony 6.4 LTS, PSR-6     ┃
    ├─ ORM, DB Migrations          ┃
    └─ Validation, Attributes ━━━━┛
          ⬇️
Phase 2: Developer Experience
┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
    ├─ Controller + DI Attributes  ┃
    ├─ PSR-11 Container Modern.    ┃
    ├─ Static Analysis (PHPStan)   ┃
    ├─ CI/CD Pipeline              ┃
    ├─ Docker Production           ┃
    ├─ Build Tools Modernization   ┃
    └─ Extension Safety System ━━━┛
          ⬇️
Phase 3: Frontend-Modernisierung
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

## 🎯 Pagekit DNA: Leicht & Modular bleiben!

**⚠️ WICHTIG: Wir bauen kein WordPress 2.0!**

Pagekit's Kernphilosophie:

- ✅ **Leichtgewichtig** — Minimaler Core, Features über Extensions
- ✅ **Modular** — Alles ist ein Modul/Extension
- ✅ **Einfach** — Klare Struktur, keine Bloatware
- ✅ **Entwicklerfreundlich** — Modern, aber nicht überladen

### Wie passt unsere Roadmap dazu?

#### Phase 1-3: Foundation ✅ PASST!

- Modernisierung bestehender Core-Features
- Keine neuen Features, nur bessere Basis
- Bleibt schlank und fokussiert

#### Phase 4: Production (2.0.0) ✅ PASST!

**Core-Features (eingebaut):**

- ✅ 2FA — Security Essential
- ✅ **OAuth2 Client** — Social Login (Google, GitHub, etc.)
- ✅ REST API v2 — Moderne Schnittstelle
- ✅ Performance — Cache, Optimierung
- ✅ Monitoring — Production Essentials

**Warum diese im Core?**

- Notwendig für Production
- Geringe Komplexität (mit Libraries)
- Moderne Erwartung (Social Login Standard)
- Stabil und bewährt

#### Phase 5: Advanced (2.1.0+) ⚠️ VORSICHT!

**Als Extensions realisieren (NICHT Core!):**

- ⚠️ **PKBlocks Editor** → **Extension** (Block-Editor, optional!)
- ⚠️ **OAuth2 Server** → **Extension** (Pagekit als Auth-Provider, Enterprise!)
- ⚠️ **SAML/SSO** → **Extension** (Enterprise-Kunden)
- ⚠️ **Multi-Tenancy** → **Extension** (komplexe Setups + Test-Umgebungen)
- ⚠️ **AI Assistant** → **Extension** (spezielle Use-Cases)
- ⚠️ **PWA** → **Extension** (nicht jeder braucht es)

### 📦 Extension-First Strategie

**Regel für Phase 5:**

1. **Frage:** Braucht das JEDER?
   - ✅ Ja → Core
   - ❌ Nein → Extension

2. **Frage:** Erhöht das die Komplexität erheblich?
   - ✅ Ja → Extension
   - ❌ Nein → Core (mit Feature Flag)

3. **Frage:** Ist das ein Nischen-Feature?
   - ✅ Ja → Extension
   - ❌ Nein → Evaluieren

**Beispiele:**

- ✅ **2FA Core** — Security braucht jeder
- ✅ **OAuth2 Client Core** — Social Login ist moderner Standard
- ❌ **OAuth2 Server Extension** — Auth-Provider ist Enterprise Feature
- ❌ **Block-Editor Extension** — Nicht jeder braucht Block-Style
- ✅ **REST API Core** — Moderne API ist Standard
- ❌ **GraphQL Extension** — Nischen-Feature
- ✅ **Cache Core** — Performance braucht jeder
- ❌ **Multi-Tenancy Extension** — Spezielle Anforderung (Test + Enterprise)

### 🔄 Entscheidungsbaum: Core vs. Extension

```
Neues Feature geplant?
    │
    ├─ Braucht es JEDER Nutzer?
    │  ├─ JA → Weiter
    │  └─ NEIN → ⚠️ EXTENSION
    │
    ├─ Ist es ein Security/Performance Essential?
    │  ├─ JA → ✅ CORE
    │  └─ NEIN → Weiter
    │
    ├─ Erhöht es Core-Komplexität stark?
    │  ├─ JA → ⚠️ EXTENSION
    │  └─ NEIN → Weiter
    │
    ├─ Kann es als Service/API bereitgestellt werden?
    │  ├─ JA → ✅ CORE (als API/Service)
    │  └─ NEIN → Weiter
    │
    └─ Default: ⚠️ EXTENSION (wenn unsicher)
```

### 🎨 Marketplace als Lösung

**Phase 5.6: Marketplace** wird zum Schlüssel:

- Extensions einfach installieren
- Offiziell geprüfte Extensions
- Community Extensions
- Theme Store

### 📏 Größenvergleich: Bleiben wir leicht?

**Pagekit 2.0.0 (Ziel):**

```
Core System: ~10 MB (leichte Zunahme durch moderne Features)
Vendor: ~15 MB (Symfony 6.4, moderne Dependencies)
Total: ~25 MB ✅ IMMER NOCH LEICHT!

Vergleich:
- WordPress: ~50-80 MB Core (ohne Plugins!)
- Drupal: ~100+ MB
- Joomla: ~40-60 MB
- Ghost: ~30 MB
- Pagekit 2.0: ~25 MB ✅ PERFEKT!
```

**Regel:**

- ✅ Core bleibt unter 30 MB
- ✅ Extensions optional installierbar
- ✅ User entscheidet, was sie brauchen

### 🚫 Was wir NICHT werden wollen

- ❌ WordPress-Klon (zu überladen)
- ❌ Drupal-Komplex (zu kompliziert)
- ❌ Joomla-Chaos (zu unübersichtlich)

**Was wir sein wollen:**

- ✅ Ghost-ähnlich (Modern, fokussiert)
- ✅ Statamic-ähnlich (Developer-friendly)
- ✅ Pagekit 2.0 (Leicht, modular, modern)

---

## 🛠 Foundation First Strategie

**Warum erst alles modernisieren, dann neue Features?**

1. **Keine doppelte Arbeit**: Features auf modernem Stack bauen statt später migrieren
2. **Bessere Qualität**: Neue Features nutzen direkt moderne Best Practices
3. **Weniger Bugs**: Testing Infrastructure vorhanden bevor neue Features kommen
4. **Einfachere Wartung**: Konsistente, moderne Codebase

---

## 📊 Versionierungsstrategie

**WICHTIG: Die MAJOR.MINOR.PATCH Strategie ist ab Phase 4 vorgesehen, davor ist es STATE.MAJOR.MINOR-PATCH**

### PATCH Version (1.0.x → 1.0.y)

- ✅ **Bugfixes** — Behebung von Fehlern
- ✅ **Security Patches** — Sicherheitsupdates
- ✅ **Dependency Updates** — Aktualisierung von Abhängigkeiten
- ✅ **Modernisierungen** — Code-Verbesserungen ohne neue Features

### MINOR Version (1.x.0 → 1.y.0)

- 🆕 **Neue Features** — Neue Funktionalität
- 🔄 **Phase-Abschluss** — Eine komplette Phase wurde abgeschlossen
- 🏗️ **Architektur-Änderungen** — Große strukturelle Verbesserungen
- ⚠️ **Breaking Changes** — Änderungen, die Extensions betreffen könnten

### MAJOR Version (x.0.0 → y.0.0)

- 🚀 **Production Release** — System ist production-ready
- 💥 **Massive Breaking Changes** — Grundlegende Architektur-Änderungen
- 🎉 **Komplett neue Version** — Neue Generation des Systems

---

## 🧭 Grundprinzipien

1. **PHP Version**: Minimum PHP 8.2 für moderne Features
2. **Test First**: Jede Änderung muss durch Tests abgesichert sein
3. **Incremental**: Kleine, testbare Schritte statt Big-Bang-Updates
4. **Documentation**: Jede Phase produziert Dokumentation
5. **No Backward Compatibility**: Radikal entfernen!
6. **Security First**: Sicherheit hat immer Priorität!

## ⚠️ Risikomanagement

- ⚠️ **Breaking Changes** dokumentieren und kommunizieren
- ⚠️ **Extension Compatibility** prüfen und migrieren, keine compatibility layer!
- ⚠️ **Performance Regression** durch Benchmarking vermeiden
- ⚠️ **Security Vulnerabilities** sofort patchen
- ⚠️ **Technical Debt** kontinuierlich abbauen
