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
- **5.0.2 — Parent-aware PackageManager**: enable-guard (parent must be active), cascade on disable/uninstall, install-reason bookkeeping, rollback — builds on the existing transactional `enable()` and the pre-flight from Step 2.7.2. A package-scoped restore, and a purge that drops the tables a module owns, are still unbuilt. Ownership is what the pre-flight reports (migrations, the module's own config row, its node types, and tables named with the installation prefix plus the module name). Without those operations a removal keeps dumping every prefixed table, and a purge keeps leaving the package's tables in place. Orphaned dependencies stay a report until install-reason bookkeeping can tell a dependency the operator enabled from one that was only pulled in.
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
`https://pagekit.com`) that served package metadata and `.zip` distributables. That API was **shut
down by the original maintainers**; the CMS has no marketplace surface of its own — no search page,
no update check for packages, no client command — until this step builds one against a server this
step provides.

The package lifecycle has **two sides**, and only one of them is settled:

| Side                         | What                                                                                                  | Status                                                                                                     |
| ---------------------------- | ----------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| **Install** (archive in)     | `PackageManager::install(PackageArchive)` behind the panel upload and `php pagekit install <archive>` | In place — a ready-to-run archive is validated, extracted and registered; nothing is resolved or downloaded |
| **Distribute** (archive out) | package index, download, verification, and the client that asks for them                              | ⏳ Open — this step                                                                                        |

The install side is the contract this step builds towards: whatever a marketplace client fetches
ends as a local archive handed to that one install path — `Pagekit\Package\Archive\PackageArchive::open()`
validates it, `PackageManager::install()` writes it, an installed package is updated by installing
the newer archive (`enable()` then runs its migrations from the recorded version), and
`uninstall()` / `enable()` / `disable()` with the per-package version in config stay as they are.
No second install path, no resolver, no Composer at runtime.

### Tasks

**1. Package distribution server (replaces `system.api`)**

- Make `system.api` fully configurable — **never** hardcode `pagekit.com`. The default lives in
  `public/index.php`; the readers are core self-update (`UpdateController`, `SelfupdateCommand`) and
  the dashboard's `systemApi` service (`DashboardModule`, its own definition). A marketplace client
  reads the same key — one endpoint setting, not a second one for packages.
- Provide a self-hostable endpoint set and the client that consumes it — the client is new work,
  nothing in the tree calls these routes today:
  - package search and package details (the search page and the details pane are new UI on the
    package pages, `app/package/views/extensions.php` / `themes.php` and
    `app/package/app/components/*`)
  - an update check for installed packages (installed name + version in, newer versions out)
  - `GET  /api/update` (used by `SelfupdateCommand` and `UpdateController` for core self-update —
    the one consumer that exists)
  - ZIP dist route, e.g. `GET /package/{vendor}/{name}/{version}.zip`
- Distribution ends in a local archive: the client downloads the ZIP into the upload staging
  (`packageStaging`), verifies it (Task 3) and hands it to `PackageManager::install()` — the same
  path the panel upload and `php pagekit install <archive>` use. There is no resolver to configure
  and no repository type to choose; a package's dependencies are whatever the archive contract
  (**2.8**) lets it declare and the dependency graph (**2.7.2**) enforces at install.

**2. Archive / build pipeline (centralise the "create ZIP" logic)**

- Extract the archiving logic out of `ArchiveCommand::execute()` into a reusable
  `ArchiveService::create(string $packageName, ?string $targetDir = null): string` that **returns
  the resulting ZIP path** at the service layer (the proper place for a return value — the old
  `return $target` from `execute()` was incompatible with Symfony's `execute(): int`).
- `ArchiveCommand` and `BuildCommand` become thin CLI wrappers (output + exit code).
- `php pagekit archive <vendor/name>` is the one archive format: `\ZipArchive` over the installed
  tree, the package's root `.gitignore` and `composer.json` `archive.exclude` applied as one
  gitignore-semantics matcher, no script execution. A server-side build shares that matcher and
  that writer through the service — it does not grow a second exclude syntax. The filename scheme
  is whatever the archive contract (**2.8**) fixes; the dist route serves the archive by
  `vendor/name/version`, so the name on disk is the index's business, not the client's.
- The command validates the package name (`PackageArchive::NAME_PATTERN`) before it touches a path
  and derives the output name from the validated name. A server-side build must keep that check in
  front of every path it builds from a request field — the source directory, the output file, the
  dist route.

**3. Validation & security**

- The archive check (`Pagekit\Package\Archive\PackageArchive::open()`) refuses a malformed manifest
  (missing or invalid `name` / `type` / `version` / `title`, a module manifest without a literal
  `autoload` map) and an unsafe archive (absolute or traversing entry names, symlink entries,
  oversize declared content) before anything is written; a published package meets the same check
  at install. What this step adds is **provenance**: a checksum the index publishes and the client
  verifies before the archive reaches the install, and a signature over it once the trust model
  (signing keys, who may publish under a vendor name) exists — the download is untrusted input until
  both hold.
- ⚠️ **Security**: `php pagekit archive` executes nothing a package supplies — `composer.json` is data
  (excludes) to it, never a command. A server-side build of **untrusted third-party** packages must
  keep it that way: any build hook the marketplace offers runs sandboxed with an allow-list of
  commands, never a shell line read out of a package field.
- Note: this is a **build-time** execution concern and is distinct from Step 2.7 (Extension Safety),
  which covers **runtime/boot-time** fault isolation.

**4. Developer experience**

- Document the extension/theme packaging workflow (required `composer.json` shape, asset build
  order, the lifecycle key, `archive.exclude`) on top of the archive contract (**2.8**) — the
  marketplace-facing half (publishing, versioning against the index) is what this step adds.
- Provide a package template/skeleton so third-party developers have a known-good starting point.

### Affected / relevant files

- CLI build side: `app/console/src/Commands/ArchiveCommand.php`, `app/console/src/Commands/BuildCommand.php`
- CLI install side: `app/console/src/Commands/InstallCommand.php` installs one local archive
  (`php pagekit install <archive>`) and stays that way. A client command that resolves a name
  against the index, downloads and verifies before handing the archive over is new work beside it,
  not a stub to re-enable — there is none.
- Install side (`Pagekit\Package`): `app/package/src/PackageManager.php`,
  `app/package/src/Archive/PackageArchive.php`, `app/package/src/Controller/PackageController.php`
- Marketplace UI: new — nothing of a former marketplace surface exists under `app/installer/`; the
  package pages (`app/package/views/extensions.php`, `themes.php`, `app/package/app/components/*`,
  `app/package/app/lib/package.js`) are where search, details and the update check hook in.
- Core self-update (`GET /api/update`): `app/console/src/Commands/SelfupdateCommand.php`,
  `app/installer/src/SelfUpdater.php`, `app/installer/src/Controller/UpdateController.php`

### Notes & provenance

- **Forward-debt tags that belong to this step:** `SelfupdateCommand` (`pagekit self-update` refuses
  with `Command::FAILURE` until a server answers `GET /api/update`) and
  `SelfUpdater::setUpdateMode()` (the maintenance-mode toggle is empty). Both are core self-update;
  the package half of this step has no disabled entry point waiting anywhere in the tree.
- The bundled `pagekit/blog` and `pagekit/theme-one` ship as in-repo sources and are activated by
  the web installer; nothing here blocks an installation without a marketplace.
- **Aggressive Rules note**: do **not** pre-build `ArchiveService` or the package server before this
  step is active. `ArchiveCommand` works as-is; centralising it earlier would be speculative work
  without a consumer (the same reasoning that keeps every marketplace API out of the package module
  until this step has a server for it to talk to).
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
  requirement — same DNA as Multi-Tenancy (**5.5.1**). Distinct from the **build-time** sandbox
  note in Task 3 above.
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
