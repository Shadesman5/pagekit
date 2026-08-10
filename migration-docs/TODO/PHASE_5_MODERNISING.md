# 🔮 Phase 5: Advanced & Enterprise – Optional Post-2.0 Capabilities

**Goal**: Optional capabilities that build on a fully modernised core — none of them are required for the 2.0.0 release.
**Prerequisite**: Phases 1–4 completed (modern core, REST API v2, production-ready release).

**Sequencing:** Table order in ROADMAP = execution order, but Phase 5 order is provisional; only declared dependencies are binding (**5.0** before its consumers, **5.6** after **2.8**). Steps may be reordered, redefined, or dropped. Community demand via the marketplace drives priority, not a schedule.

> This document is intentionally high-level. Detailed task breakdowns and agent prompts are
> created per step once the step becomes active. Progress tracking lives in
> [ROADMAP.md](../../.cursor/ROADMAP.md). See also [MODERNISATION_STRATEGY.md](MODERNISATION_STRATEGY.md).

---

## Core vs. Extension placement

> **Roughly 80% of Phase 5 belongs in extensions, not the core.**
> See [MODERNISATION_STRATEGY.md](MODERNISATION_STRATEGY.md) → Pagekit DNA & Extension-First strategy.

| Feature              | Core? | Extension?  | Rationale                                    |
| -------------------- | ----- | ----------- | -------------------------------------------- |
| **PKBlocks Editor**  | ❌    | ✅         | Complex; not every site needs it             |
| **OAuth2 Client**    | ✅    | ❌         | Social login is a modern baseline            |
| **OAuth2 Server**    | ❌    | ✅         | Acting as an auth provider is enterprise     |
| **SAML/SSO**         | ❌    | ✅         | Enterprise feature                           |
| **Multi-Tenancy**    | ❌    | ✅         | Never a core requirement — **5.5.1**         |
| **PWA Support**      | ❌    | ✅         | Not every site needs it                      |
| **AI Assistant**     | ❌    | ✅         | Cost and external service dependency         |
| **GraphQL API**      | ❌    | ✅         | Niche; REST v2 is the core API — **5.4.2**   |
| **Real-time Collab** | ❌    | ✅         | Complex, needs dedicated infrastructure      |
| **Workflow Engine**  | ❌    | ✅         | Enterprise, very complex                     |
| **CDN Integration**  | ⚠️    | ✅         | Better as a plugin/service binding           |
| **Marketplace**      | ✅    | ❌         | Central to the extension ecosystem           |
| **Sub-Extensions**   | ✅    | ❌         | Platform mechanics, not a feature            |
| **Process-isolated extension runtime** | ❌ | ✅ (candidate) | Optional after marketplace trust exists — never Core; see §5.6 Future candidate |
| **Event Sourcing**   | ❌    | ✅         | Opt-in side database — **5.4.1**             |
| **Agentic DevOps**   | —     | Tooling     | Internal — **5.9**, not a CMS feature        |

---

## Step 5.0: Sub-Extension Platform

- **Branch**: `feature/sub-extensions`
- **Goal**: Let operators decide which parts of the CMS are present at all. One module contract for everything, declared tiers of removability, and a parent/child relation so any extension — and the core itself — can offer opt-in parts that are installed / activated / deactivated / uninstalled individually and managed accordion-style under their parent in the backend Extensions list. Phase-5 foundation (mirrors Step 2.0): later steps deliver their add-ons as sub-extensions on top of it.
- **Prerequisite**: Steps 3.3 (Vue 3) and 3.5 (Component Library) for the accordion UI; Step 2.7.2 (Module Dependency Integrity) — operator-managed activation must not be able to leave the application half-wired.

### One mechanism, four tiers

Pagekit already has a single module model: an uploaded package and a core system module share the same `index.php` manifest and the same `ModuleManager`. The only difference is how they are activated — core modules sit in the hardcoded `require` list of `app/system/index.php`, extensions in the runtime-editable `extensions` config list that `PackageManager` maintains. This step turns that difference into a declared tier instead of a hardcoded array, and does **not** add a second plugin model.

| Tier                        | What                                                                                  | Removable                          |
| --------------------------- | ------------------------------------------------------------------------------------- | ---------------------------------- |
| **kernel**                  | `app/modules/*` — application, routing, view, database, cache, session, filesystem, intl | Never; not even listed             |
| **core**                    | System modules every site needs (user, site, settings, theme, content, widget)          | Never                              |
| **core-extension**          | Bundled but optional — candidates today: feed, markdown, comment, captcha, finder, info, mail | Deactivatable and uninstallable |
| **extension / sub-extension** | Packages from upload or marketplace; a sub-extension declares a parent                | Yes                                |

**Classification rule**: a module is core when the CMS cannot boot and serve a page without it — not when it merely feels essential. Decide each module against that question, or the tier list turns into a matter of taste.

**A sub-extension is not a separate kind of thing** — it is an extension that declares a parent. Two declarations already cover it and no third resolver is needed: Composer `require` states what must exist on disk and carries the version constraints, the module manifest `require` states what must be loaded first, and `extra.parent` adds the parent link. Do not invent version resolution in the manifest.

### Automatic dependency handling

- **Install reason**: every module and package records why it is active — deliberately chosen by an operator, or pulled in as a dependency. Without that distinction, automatic cleanup eventually removes something the operator installed on purpose just because whatever pulled it in is gone.
- **Release rule**: removing something releases only the dependencies it pulled in itself, and only while no other dependent remains. What an operator activated deliberately is never touched automatically.
- **Data guardrail**: automatic cleanup deactivates, it does not delete. A removal nobody explicitly requested runs through the three-stage path of Step 2.7.1 (disable → uninstall → purge after retention, snapshot first), so there is always a way back. Optional modules own user data — comments are the obvious case.

### Sub-steps

- **5.0.1 — Package metadata + model**: `extra.parent` convention in `composer.json` (+ Composer `require` for the real install-time dependency); the package factory/API exposes the parent link and the tier.
- **5.0.2 — Parent-aware PackageManager**: enable-guard (parent must be active), cascade on disable/uninstall, install-reason bookkeeping, rollback — builds on the existing transactional `enable()` and the pre-flight from Step 2.7.2.
- **5.0.3 — Accordion admin UI**: group installed extensions by parent in the shared `package-manager` list; per-child install / activate / deactivate / uninstall. A destructive click shows the pre-flight result first and names the blocking dependents.
- **5.0.4 — Module usage advisor**: report which active modules nothing references and which required ones are missing, with the deactivate / install recommendation surfaced in the backend and over the API. Reads the same dependency graph as the pre-flight, in the opposite direction.

- **Promotion**: move the optional system modules out of the hardcoded `require` list in `app/system/index.php` into the activation registry, so a single list answers "what is switched on".
- **Sequencing**: The Phase 5 order is provisional and may be reordered, but the dependency is fixed — **5.0 must precede its consumers**: Step 5.2.1 ships its add-ons as sub-extensions, and Step 5.6 later extends the mechanism with remote discovery of nested packages in the marketplace.
- **Result**: Any extension family (consent, blog, shop, …) and the core itself can ship optional parts as togglable sub-extensions, each independently versioned and updatable — and the core stays as small as the operator wants it.

---

## Step 5.1: Modern Block Editor

- **Branch**: `feature/pkblocks-editor`
- **Goal**: Block-based content editing (Gutenberg-style) replacing the legacy HTML editor flow.
- **Name**: PKBlocks (Pagekit Blocks)
- **Why Phase 5?**:
  - ⚠️ **Very complex** — a project in its own right (months, not weeks)
  - ⚠️ **Not essential** — the current editor works
  - ✅ **Better after 2.0** — builds on Vue 3 + UIkit 3.x
  - ✅ **Learns from YOOtheme** — adopt the proven patterns, not the product
- **Tech stack** (requires Phase 3):
  - Vue 3 (Composition API)
  - UIkit 3.x Components
  - TypeScript
  - SortableJS (Drag & Drop)
  - Marked.js (Markdown)
  - TipTap (WYSIWYG)
- **Core Features (v1.0)**:
  - **Block Library** (linke Sidebar)
    - Text Block (Markdown + WYSIWYG Toggle)
    - Image Block (mit Cropping)
    - Gallery Block (UIkit Lightbox)
    - Video Block (YouTube, Vimeo, Local)
    - Button Block (UIkit Styling)
    - Columns/Grid Block (UIkit Grid)
    - Spacer Block
  - **Drag & Drop** Interface
  - **Settings Panel** (rechte Sidebar)
  - **Live Preview** (mitte)
  - **Responsive Preview** (Desktop/Tablet/Mobile)
- **UIkit Integration**:
  - Accordion Block
  - Slider Block
  - Card Block
  - Tabs Block
  - Modal/Popup Block
  - Parallax Block
  - Slideshow Block
- **Advanced Features (v2.0)**:
  - Custom Block API für Extensions
  - Block Templates/Patterns
  - Import/Export (JSON)
  - Block History (Undo/Redo)
  - Block Locking
  - Global Blocks (reusable)
- **Philosophy**:
  - ✅ **Lightweight** — no feature bloat
  - ✅ **Modular** — one Vue component per block
  - ✅ **Clean output** — no inline-style monster
  - ✅ **Developer-friendly** — a simple block API
  - ✅ **Open source** — MIT
  - ❌ **NOT** a WordPress Gutenberg clone
  - ❌ **NOT** a theme builder (too complex)
  - ✅ **ONLY** a content editor — that is the whole scope
- **Adopted from YOOtheme**: UIkit components as building blocks, settings panel on the right, live preview, clean markup output — as patterns, in an MIT project.
- **Estimated size**: ~5–7 MB including dependencies

---

## Step 5.2: Advanced Security & OAuth2 Provider

- **Branch**: `feature/advanced-security`
- **Goal**: Hardening beyond the Step 4.1 baseline (audit logging, fine-grained permissions, signed packages).
- **Features**:
  - **OAuth2 Server (Pagekit als Auth-Provider)** ← Enterprise Extension!
    - Pagekit als OAuth2 Authorization Server
    - Token-basierte Authentication für externe Apps
    - Scopes & Permissions Management
    - Client App Management
    - Library: league/oauth2-server
  - **SAML 2.0 Support**
    - Single Sign-On (SSO)
    - SAML Identity Provider
    - SAML Service Provider
    - Enterprise SSO Integration
  - **Advanced Audit Logging**
    - User Action Tracking
    - Security Event Logging
    - Compliance Reports

---

## Step 5.2.1: Advanced Privacy & Consent Extensions

- **Branch**: `feature/advanced-privacy`
- **Goal**: Heavyweight privacy/consent add-ons that build on the Step 4.10 cookie-consent baseline extension — each delivered as an opt-in **sub-extension** of the baseline (builds on Step 5.0), not part of it.
- **Features**:
  - **Automated cookie scanner** — crawl the site to detect cookies and pre-fill the consent registry.
  - **Geo-targeting / per-jurisdiction rulesets** — region-aware banner behaviour (needs a GeoIP source).
  - **Cookie-policy generator** — jurisdiction-aware policy page, auto-populated from the cookie registry.
  - **GDPR Tools** — data export & deletion (right of access / erasure).
  - **Privacy Policy Management** — manage and publish privacy/cookie policy content.

---

## Step 5.3: Advanced Performance

- **Branch**: `feature/advanced-performance`
- **Goal**: Edge caching, HTTP/2 push alternatives, queue/worker offloading for heavy tasks.
- **Features**:
  - CDN Integration
  - Edge Caching
  - GraphQL API (optional)
  - Advanced Query Optimization
  - **Multi-DB**: PostgreSQL as a first-class tested Doctrine driver next to SQLite (dev/test default) and MySQL/MariaDB (production) — the portable QueryBuilder path must be proven by a CI leg, not assumed

---

## Step 5.4: Advanced CMS Features

- **Branch**: `feature/advanced-cms`
- **Goal**: Multi-site, content versioning/workflow, scheduled publishing, and other opt-in CMS capabilities that not every site needs.
- **Features**:
  - PWA (Progressive Web App)
  - Real-time Collaboration
  - AI Content Assistant
  - Advanced Workflow System
  - JSON-LD / schema.org helpers (candidate)

### Step 5.4.1: Event Sourcing Module (opt-in)

- **Goal**: Optional event store (separate SQLite under `tmp/events/`, never DocRoot) for audit / time-travel — **never** a mandatory Core path.
- **DNA**: Extension or clearly isolated module; Core hot paths must not require the event DB
- **Out of scope**: Making Event Sourcing the default persistence model

### Step 5.4.2: GraphQL API Extension

- **Goal**: GraphQL as an **extension** for niches that need it — Core API remains REST v2 (**4.4**).
- **DNA**: Not in Core; Extension-First (MODERNISATION_STRATEGY)
- **Note**: Advanced Performance (**5.3**) may mention GraphQL as optional infra — product surface ownership is **this** sub-step

---

## Step 5.5: Enterprise Features

- **Branch**: `feature/enterprise`
- **Goal**: SSO/SAML, tenancy, role delegation, compliance tooling — almost entirely extensions.
- **Features**:
  - Multi-Tenancy Support → **5.5.1**
  - Workflow Engine
  - Advanced Analytics Dashboard
  - White-Label Support

### Step 5.5.1: Multi-Tenancy Extension

- **Goal**: Opt-in multi-tenant capability for sites that need it — **never** a Core requirement.
- **DNA**: Extension; Core stays single-site by default.
- **Out of scope**: Rewriting the Core as multi-tenant-first

---

## Step 5.6: Marketplace & Extensions

- **Branch**: `feature/marketplace`
- **Goal**: Re-establish a working extension/theme ecosystem so first-party **and third-party**
  developers can build, publish, distribute, and install packages — replacing the discontinued
  `pagekit.com` marketplace API.
- **Features**:
  - Official Marketplace
  - Extension Store
  - One-Click Updates
  - Theme Store

### Context

The original Pagekit marketplace relied on an external API at `system.api` (default
`https://pagekit.com`) that served package metadata and `.zip` distributables (see the historic
`dist.url` entries in `packages/composer/installed.json`). That API was **shut down by the original
maintainers**, so the discovery/download half of the ecosystem is currently non-functional.

The package lifecycle has **two sides**, and they are at different maturity levels:

| Side                          | What                                                     | Where      | Status                                               |
| ----------------------------- | -------------------------------------------------------- | ---------- | ---------------------------------------------------- |
| **Install / Upload** (zip in) | `PackageManager`, `Composer` helper, backend ZIP upload  | Step 2.0.4 | ✅ Modernised (clean API + "marketplace foundation") |
| **Build / Publish** (zip out) | `ArchiveCommand`, `composer archive`, packaging workflow | Step 5.6   | ⏳ Open — this step                                  |

Step 2.0.4 explicitly deferred the marketplace **API integration** to this step and only laid the
foundation (clean `PackageManager` API: `install()`, `update()`, `uninstall()`, `enable()`,
`disable()`; modernised ZIP upload; per-package versioning in config).

### Tasks

**1. Package distribution server (replaces `system.api`)**

- Make `system.api` fully configurable — **never** hardcode `pagekit.com` (it is currently a
  fallback in `MarketplaceController`, `PackageController`, `UpdateController`, `DashboardModule`,
  `PackageManager`, `Composer` helper, `SelfupdateCommand`).
- Provide a self-hostable endpoint set consumed by the existing frontend:
  - `POST /api/package/search` (used by `marketplace.vue`)
  - `POST /api/package/update` (used by `package.js` `queryUpdates`)
  - `GET  /api/update` (used by `SelfupdateCommand`)
  - ZIP dist route, e.g. `GET /package/{vendor}/{name}/{version}.zip`
- Composer integration already supports two repositories — keep both:
  `['type' => 'artifact', 'url' => path.artifact]` and `['type' => 'composer', 'url' => system.api]`
  (see `app/installer/src/Helper/Composer.php`).

**2. Archive / build pipeline (centralise the "create ZIP" logic)**

- Extract the archiving logic out of `ArchiveCommand::execute()` into a reusable
  `ArchiveService::create(string $packageName, ?string $targetDir = null): string` that **returns
  the resulting ZIP path** at the service layer (the proper place for a return value — the old
  `return $target` from `execute()` was incompatible with Symfony's `execute(): int`).
- `ArchiveCommand` and `BuildCommand` become thin CLI wrappers (output + exit code).
- Standardise on a single archive format and share the exclude rules already declared in each
  package's `composer.json` `archive.exclude`. Align the CLI (`php pagekit archive`) with the
  Composer-native `composer archive --format=zip` workflow that `packages/*/package.json` scripts
  already use, so external developers can package without a full Pagekit install.
- Filename scheme should match the upload side: `vendor-package-version.zip`
  (see `PackageController::uploadAction`).
- Derive the package name from `composer.json` (`name` + `version`) instead of the raw CLI
  argument, and reject path-traversal sequences (`..`, absolute paths) in `$name` before building
  `$sourcePath`. The current `getPackageFilename()` only sanitises the **output** filename; the
  **input** path is still used verbatim.

**3. Validation & security**

- Validate uploaded/published packages: required `composer.json` fields (`name`, `type`,
  `version`), `type` must be `pagekit-extension` or `pagekit-theme`, checksum verification
  (`shasum`, as the legacy `installed.json` carried).
- ⚠️ **Security**: `ArchiveCommand` runs `system($jsonData['archive']['scripts'], $return)` — it
  executes an arbitrary shell command taken from a package's `composer.json` at archive time. This
  is acceptable when a developer packages their **own** code locally, but is **dangerous** if the
  marketplace ever builds **untrusted third-party** packages server-side. Before any server-side
  build of external packages: sandbox, whitelist, or remove this hook.
- Note: this is a **build-time** execution concern and is distinct from Step 2.7 (Extension Safety),
  which covers **runtime/boot-time** fault isolation.

**4. Developer experience**

- Document the extension/theme packaging workflow (required `composer.json` shape, asset build
  order, `extra.scripts`, `archive.exclude`).
- Provide a package template/skeleton so third-party developers have a known-good starting point.

### Affected / relevant files

- CLI build side: `app/console/src/Commands/ArchiveCommand.php`, `app/console/src/Commands/BuildCommand.php`
- CLI install/update side (disabled stubs since 2020 — the marketplace-client half):
  `app/console/src/Commands/InstallCommand.php`, `app/console/src/Commands/UpdateCommand.php`
- Install side (already modern): `app/installer/src/Package/PackageManager.php`,
  `app/installer/src/Helper/Composer.php`, `app/installer/src/Controller/PackageController.php`
- Marketplace UI/API consumers: `app/installer/src/Controller/MarketplaceController.php`,
  `app/installer/src/Controller/UpdateController.php`, `app/installer/app/components/marketplace.vue`,
  `app/installer/app/lib/package.js`
- Self-update: `app/console/src/Commands/SelfupdateCommand.php`, `app/installer/src/SelfUpdater.php`

### Notes & provenance

- The CLI commands (`archive`, `build`, `setup`, `start`, `uninstall`) are **functional today**.
  During TODO triage their obsolete `// TODO: Callback` markers (a leftover from a pre-Symfony,
  Laravel-style "command returns data / invokes a callback" idea with no consumer) were removed, and
  `BuildCommand` was corrected from `return (int) $this->line(...)` to
  `$this->line(...); return Command::SUCCESS;`. These TODOs were **not** related to the marketplace
  feature.
- **Disabled marketplace-client commands (2020) — precised & tagged to this step (2026-06-18):**
  Distinct from the Callback cleanup above, five entry points are the *client* half of the
  marketplace and were stubbed out when the `pagekit.com` backend was shut down. They cannot be
  re-enabled before Task 1 (package-distribution server) exists:
  `InstallCommand` (`pagekit install`), `UpdateCommand` (`pagekit update`),
  `SelfupdateCommand` (`pagekit self-update`, needs `GET /api/update`),
  `SelfUpdater::setUpdateMode()` (empty maintenance-mode toggle), and the commented-out
  `Composer::install()` block in `BuildCommand` (optional release bundling of published package
  versions). Their vague TODOs (`// TODO`, `// TODO: Implement this.`,
  `// TODO: Don't install packages from repo during development.`) were rewritten to
  `// TODO: Step 5.6 (Marketplace & Extensions) — …`. They do **not** block 2.0: the bundled
  `pagekit/blog` + `pagekit/theme-one` ship as in-repo sources and are activated by the web installer.
  The three disabled CLI commands also returned a misleading exit code
  (`return (int) $this->error(...)`, and `error()` is `void` → `0`/SUCCESS despite the error);
  corrected to a clear message plus `return Command::FAILURE;` so automated callers (CI, cloud
  agents) detect the disabled state.
- During the same triage the vague `// TODO: Make this more robust.` in
  `ArchiveCommand::getPackageFilename()` (a 2019 upload leftover) was closed with minimal output
  sanitisation (collapse duplicate hyphens, trim surrounding hyphens, empty-string fallback). The
  fuller robustness work — composer-name-based filenames, the `vendor-package-version.zip` scheme,
  and input path-traversal validation — belongs to Tasks 2–3 above.
- **Aggressive Rules note**: do **not** pre-build `ArchiveService` or the package server before this
  step is active. `ArchiveCommand` works as-is; centralising it earlier would be speculative work
  without a consumer (the same reasoning that moved the marketplace API from Step 2.0.4 to here).
- The **public, versioned, JWT-authenticated** API patterns are defined in Step 4.4 (REST API v2);
  the marketplace API should follow those conventions rather than reviving the legacy ad-hoc
  `emulateJSON` endpoints.

### Future candidate (not scheduled): Process-isolated extension runtime

- **What**: Optional process-level isolation for *enabled* third-party PHP (separate worker /
  restricted capabilities), so malicious or hostile code cannot share the Core process.
- **Why not Core / not 2.7**: Step 2.7 is **fault isolation** (survive bugs, auto-disable, notify).
  Process sandboxing is **security isolation** against untrusted code — a different product and a
  different runtime model. In-process extensions remain the default CMS contract.
- **When to reconsider**: only after **5.6** has a working marketplace **and** a trust model
  (signing / integrity / review). Until then, trust is distribution-side, not execution-side.
- **Placement if pursued**: Extension or Sub-Extension (builds on **5.0**), never a Core
  requirement — same DNA as Multi-Tenancy (**5.5.1**). Distinct from the **build-time**
  `archive.scripts` sandbox note in Task 3 above.
- **Not a ROADMAP row yet**: community / threat-model demand decides; do not invent a step ID
  before that.

---

## Step 5.7: Extension Author Toolchain (npm)

- **Branch**: `feature/extension-build-toolchain`
- **Goal**: Publish the extension/theme build preset as a versioned npm package, so authors install a pinned toolchain instead of copying a Vite config into every package.
- **Prerequisite**: Step 2.8 (packaging contract + the documented preset this step publishes) and Step 4.7 (Rebranding — fixes the published scope name).
- **Why Phase 5?**:
  - ✅ **Not essential** — the copy-in config from Step 2.8 already produces valid packages.
  - ✅ **Better after rebranding** — a published package name cannot be renamed cheaply once authors depend on it.
- **Features**:
  - Package exporting the build config: externals `vue` / `uikit` / `uikit-util` mapped to the runtime globals, IIFE output, SFC plugin — the same shape the core pipeline emits.
  - Thin CLI for the author build plus an upload-ready ZIP.
  - Versioned independently of the core, with a documented compatibility range per core release, so a change to the runtime externals reaches authors as a version bump instead of a doc diff.
- **Boundary**: the `composer.json` shape, `archive.exclude` and the package skeleton stay with Step 5.6 Task 4 — this step owns the JS build side only.
- **Result**: extension authors build against a pinned, updatable toolchain; the core carries no author tooling in its own runtime or repo.

---

## Step 5.8: Developer Experience (DX)

- **Branch**: per candidate (first: `feature/dx-typed-view-context`)
- **Goal**: Optional, opt-in DX polish for people building on Pagekit — never mandatory, never at the cost of the lightweight, DI-less template DNA. A container for small ergonomics wins that only make sense on a fully modern core.
- **Why Phase 5?**:
  - ✅ **Not essential** — pure ergonomics; the core is fully usable without it.
  - ✅ **Better after a modern core** — builds on the Presenter/DTO seam (Step 2.1.10) and Vue 3 + TypeScript (Phase 3).
  - ⚠️ **Must stay opt-in** — forcing it would hurt extension-author DX and break the "loose templates are allowed" DNA.

### Candidate: Statically-typed view context (opt-in)

- **Background**: `PhpEngine::evaluate()` injects template variables at runtime via `extract()`, which static analysis cannot follow. `.php` view templates therefore carry permanent `variable.undefined` PHPStan suppressions — documented as accepted false positives in `phpstan.neon`. This is **not** a bug and **not** tech debt.
- **What we already have**: new-name typos (e.g. `$rooot`) are still caught — a fresh, unmatched `variable.undefined` surfaces in CI (preserved by the "never `--generate-baseline`, surgical-only" discipline). The *remaining* gap is narrow: **IDE autocomplete in templates** and **type-checking of method/property access** on template variables (today effectively `mixed`).
- **Idea**: Offer an **optional** typed view-context (a Presenter/DTO passed as one typed variable) that controllers *may* use instead of a loose `extract()` array — giving IDE autocomplete + PHPStan type-checking + a self-documenting data contract (also friendlier for AI/LLM comprehension). Reuse the existing `Presenter` layer (Step 2.1.10); do **not** invent a parallel mechanism.
- **Hard constraints (DNA)**:
  - **Opt-in only** — loose, DI-less `.php` templates stay fully valid for core and extensions; no forced migration, no per-file `/** @var */` mandate.
  - **One glue point** — reuse the Presenter/DTO seam; no new framework layer, no runtime bloat.
  - **No Twig migration for this** — moving templates out of PHP analysis adds no type-safety and is a heavy, unrelated change.
- **Priority**: Low. The documented `phpstan.neon` "accept" is the resting state; pursue only if template type-safety / IDE support becomes a *felt* developer pain.
- **Aggressive Rules note**: do **not** pre-build a typed-context API before this step is active — speculative work without a consumer.

---

## Step 5.9: Agentic DevOps & Self-Healing Pipelines

- **Goal**: Chain of pipelines: deterministic self-heal for trivial failures → agent loop for hard failures (new PR or continue on open PR) → review/merge-prep → optional controlled merge + post-merge verify → issue/PR ideation automation. Normal CI/CD remains the always-on quality gate. **Not a CMS product feature** — tooling only.
- **DNA / safety**:
  - Tooling lives in `.github/` / Conductor / companion repos — does not bloat CMS runtime
  - No silent auto-merge without explicit gates (**5.9.4**)
  - Agents touching the **product** go through public APIs (**4.4**), not a back door
- **Out of scope**: Replacing human ownership of releases

### Step 5.9.1: Sentinel / Deterministic Self-Heal

- Trivial failures (formatter, missing `composer install`, lockfile drift) fixed by scripts/workflows before agents are invoked
- Hand-off payload to agents: logs, stack trace, failing job context

### Step 5.9.2: Agentic Fix Loop (PR create/update)

- Analyse → sub-steps → builder/validator loop
- Open a PR or continue on an existing open PR
- Builds on Conductor Plan/Execute/Finalize patterns

### Step 5.9.3: Review & Merge-Prep Pipelines

- Architecture/style review agents + existing Bugbot/CI
- Prepare merge checklist (labels, milestones, required checks green)

### Step 5.9.4: Controlled Auto-Merge + Post-Merge Verify

- Merge only when policy allows (required checks, review signals, risk class)
- Post-merge smoke / staging verify; rollback signal on failure
- Default remains human merge until this sub-step explicitly enables automation

### Step 5.9.5: Issue/PR Ideation Automation

- GitHub Issues/user ideas → feasibility weigh-in → plan → PR
- Route to human and/or AI reviewers; permanent loop with CI still authoritative

---

## Long-term ideas (beyond 3.0)

Unscheduled and unowned — no ROADMAP slots. Listed so they are not re-invented, and so it stays visible that each of them is an extension candidate rather than core scope.

- 🔮 **Headless CMS mode** — fully API-first operation with the admin as just one client
- 🔮 **Cloud-native** — Kubernetes-native operation, serverless request handling
- 🔮 **Advanced AI integration** — content generation, SEO assistance, personalisation
- 🔮 **Native admin apps** — iOS/Android clients on the REST API
- 🔮 **Advanced analytics** — ML-based content insights
