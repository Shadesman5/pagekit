# 🎨 Phase 3: Frontend Modernization & Cross-Stack Alignment

**Goal**: Migrate frontend to a modern stack (Vue 3, UIkit 3.21+, TypeScript) **and** align backend boundaries where frontend and PHP must change together (translation/Intl, service-layer DI hygiene).

**Prerequisite**: Phase 2 MUST be completed (Build Tools in Step 2.4 are a prerequisite for frontend work!)

### Phase scope (2026-07-12)

| Track | Steps | Focus |
| ----- | ----- | ----- |
| **Frontend** | 3.1–3.4, 3.5, 3.6 | Vue 3, UIkit, TypeScript, components, E2E selectors |
| **Cross-stack** | 3.4.5, 3.4.6 | Intl platform API, transChoice → ICU (PHP + Vue + loaders), `TranslatorInterface` in service layer |

> **Note:** Template-facing globals (`__()`, `_i()`, `$date`, `$number`) **stay** — they are the Pagekit **platform DX API**, not legacy debt. See ROADMAP § DX & Lightweight Philosophy and Step 3.4.6 decision below.

> **Analysis Result (2026-02-11):** All frontend dependencies were analyzed in depth:
>
> **YOOtheme Vue Libraries (archived):**
>
> - `vue-fields` (~1.1.3): **Not used** in the codebase → ignore
> - `vue-resource` (~1.5.1): **Heavily used** (32 files, 4 interceptors) → replace with `axios`
> - `vue-event-manager` (~2.1.3): **Moderately used** (14 files) → replace with `mitt`
> - Decision: **Replace instead of forking** — actively maintained standard libraries over self-maintenance
>
> **Additional Dependencies:**
>
> - `vue-intl`: Internally uses custom AngularJS logic (not native Intl!). **Completely replace** with
>   the native `Intl` API. Platform API names (`$date`, `$number`, `$currency`, `$relativeDate`) remain
>   as modern reimplementations (not a wrapper!). Extensions like Formmaker, Listings, Events use these.
> - `vue-nestable`: Hierarchical page tree. **NOT replaceable by UIkit sortable!**
>   (sortable = flat lists, nestable = tree structure with parent/child)
> - `lodash`: 24/300+ functions in 65+ files, loaded as a 70 KB global script.
>   ~80% natively replaceable, rest via ~45 lines of custom utilities.
> - `$number`/`$currency` appear unused in the core but are **extension APIs** (Listings, Formmaker)!

## Current Frontend Dependencies (Status Quo)

| Package             | Version          | Status                                 | Action in Phase 3                                               |
| ------------------- | ---------------- | -------------------------------------- | --------------------------------------------------------------- |
| `vue`               | ~2.6.12          | Legacy                                 | → Vue 3.x (Step 3.2 + 3.4)                                      |
| `vue-resource`      | ~1.5.1           | Archived, Vue-3-incompatible           | → `axios` (Step 3.4.1)                                          |
| `vue-event-manager` | ~2.1.3           | Archived, Vue-3-incompatible           | → `mitt` (Step 3.4.2)                                           |
| `vue-intl`          | uatrend/vue-intl | Community fork, custom AngularJS logic | → **Delete & rewrite** with native `Intl` API (Step 3.4.5)      |
| `vue-nestable`      | ~2.6.0           | Hierarchical page tree                 | → Vue 3 tree alternative (Step 3.4.5) — **NOT UIkit sortable!** |
| `vee-validate`      | ~3.3.11          | Validation                             | → `vee-validate` v4 (Vue 3 Composition API) (Step 3.4.5)        |
| `lodash`            | ~4.17.21         | 24/300+ functions in 65+ files         | → Native ES2020+ + `utils.js` (~1 KB) (Step 3.4.5)              |

---

## Step 3.1: UIkit Update

- **Goal**: UIkit 3.5 → 3.21.x (latest)
- **Why first?**: UIkit is independent of Vue and can be updated in isolation
- **Tasks**:
  - Analyze UIkit breaking changes between 3.5 and 3.21
  - Update PHP templates (Blade-like `.php` views)
  - Adapt Vue components (UIkit JS initialization)
  - Visual regression testing with Playwright screenshots
  - Update custom UIkit theme (if applicable)
- **Risk**: Low (UIkit is CSS/JS-only, no PHP dependency)

---

## Step 3.2: Vue.js 2.7 Migration (Bridge)

- **Goal**: Vue 2.6 → 2.7 (bridge version for safe migration to Vue 3)
- **Why Vue 2.7?**:
  - Vue 2.7 is the last 2.x version (backport of Vue 3 features)
  - Includes Composition API from Vue 3 (testable in 2.x!)
  - Supports `<script setup>` syntax
  - Shows deprecation warnings for Vue 3 breaking changes
  - Makes migration to Vue 3 significantly safer
  - `vue-resource` and `vue-event-manager` still work under 2.7!
- **Migration Path**: Vue 2.6 (CURRENT) → Vue 2.7 (Bridge) → Vue 3.x (Target)
- **Tasks**:
  - Update `vue` package to 2.7
  - Adjust Webpack/build config for Vue 2.7
  - Test Composition API in selected components
  - Systematically analyze and document deprecation warnings
  - Run all E2E tests (regression check)
- **Important**: In this step, `vue-resource` and `vue-event-manager` remain active!

### Step 3.2.1: Template Pre-compilation (CSP) — Complete CSP Gold Standard

- **Prerequisite**: Step 1.13.5 (CSP Step One, ~80%) + Step 3.2 (Vue 2.7 Bridge)
- **Goal**: Eliminate all runtime template compilation for full CSP compliance. Complete the remaining ~20% from Step 1.13.5.
- **Tasks**:
  - **Pre-compile Vue templates** — remove `unsafe-eval` from CSP `script-src` (Vue 2 runtime compiler uses `new Function()` which requires `unsafe-eval`; pre-compiled templates avoid this)
  - **CSP ResponseListener** — move CSP from `.htaccess`-only to a PHP `ResponseListener`/Middleware so that CSP is enforced on **all** servers (Apache, Nginx, PHP Built-in Server, Docker). `.htaccess` remains as defense-in-depth backup. This is critical because `php -S localhost:8080` (used by dev servers, Cloud Agents, and `AGENTS.md` setup) has no CSP without this.
  - **Tighten `style-src`** — evaluate removing `unsafe-inline` after UIkit update (Step 3.1); if UIkit still needs inline styles, document why and keep as conscious exception
  - **Verify:** Zero CSP violations in browser console on all pages (admin + frontend)
- **Result**: Full CSP Gold Standard — no `unsafe-eval`, no `unsafe-inline` in `script-src`, enforced everywhere

---

## Step 3.3: TypeScript Integration

- **Goal**: Gradually introduce TypeScript into the frontend
- **Tasks**:
  - Configure `tsconfig.json` (strict mode)
  - Extend Webpack/build pipeline for `.ts` files
  - Define shared API types (generated from PHP backend routes)
  - Write new composables/utilities in TypeScript
  - Gradually type existing components (not all at once!)
- **Strategy**: Enforce TypeScript for new files, gradually migrate existing `.js` files

---

## Step 3.4: Vue 3 Migration (MAJOR!)

- **Goal**: Vue 2.7 → Vue 3.x (including replacement of all Vue-2-only dependencies)
- **Prerequisite**: Step 3.2 (Vue 2.7 Bridge) MUST be completed!

> **⚠️ This is the most complex step of the entire Phase 3!**
> The Vue 3 migration affects not only Vue itself but also the replacement of
> `vue-resource` and `vue-event-manager`, which are Vue-3-incompatible.

- **Sub-Steps** (in this order):

---

### Step 3.4.1: HTTP Client Migration (vue-resource → axios)

- **Effort**: High (~32 files + 4 interceptor modules)
- **Goal**: Completely replace `vue-resource` with `axios`
- **Why axios?**:
  - Interceptor system is 1:1 compatible with vue-resource's interceptors
  - 46M+ weekly downloads, active maintenance, TypeScript support
  - Can be registered as a Vue plugin (`app.config.globalProperties.$http`)
  - Security patches automatically via Dependabot
- **Affected Areas**:
  - **4 Interceptor Modules** (need to be rewritten):
    - `app/system/app/lib/csrf.js` → axios request interceptor
    - `app/system/app/lib/resourceCache.js` → axios request/response interceptor
    - `app/system/modules/user/app/interceptor.js` → axios response interceptor (401 → login modal)
    - `app/system/modules/captcha/app/interceptor.js` → axios request interceptor (reCAPTCHA)
  - **~28 Vue Components/Views** with `this.$http.get/post` → `this.$http.get/post` (axios, nearly identical API)
  - **6 files** with `Vue.http` directly → `axios` instance
  - **URI Templates** (`api/user{/id}`) → small utility function (~10 lines)
  - **`Vue.url.route()`** → custom URL helper utility
- **Can be partially done BEFORE Vue 3** (axios is Vue-version-independent!)

---

### Step 3.4.2: Event System Migration (vue-event-manager → mitt)

- **Effort**: Medium (~14 files)
- **Goal**: Completely replace `vue-event-manager` with `mitt` + composable
- **Why mitt?**:
  - Officially recommended by the Vue team as a replacement
  - Tiny (~200 bytes), zero dependencies
  - Event priorities from vue-event-manager are **not used** in the project (analyzed!)
- **Affected Areas**:
  - **9 files with `$trigger`** (emitter) → `emitter.emit()`
  - **6 files with `events: {}`** (listener) → `onMounted`/`onUnmounted` + `emitter.on/off`
  - Events: `node-save`, `settings-save`, `user-save`, `post-save`, `widget-save`,
    `widget-cancel`, `saved:widget`, `settings-changed`, `finder-select`, `finder-ready`
- **Migration**:
  - Create custom `useEventBus()` composable (~10 lines)
  - Long-term: Save flows can be refactored to Pinia store actions

---

### Step 3.4.3: Vue 3 Core Migration

- **Goal**: Vue 2.7 → Vue 3.x with migration build
- **Tasks**:
  - Install Vue 3 + `@vue/compat` (migration build)
  - Completely rewrite `app/system/app/vue.js` (new app bootstrap)
  - Global API changes: `Vue.use()` → `app.use()`, `Vue.component()` → `app.component()`
  - Options API → Composition API (gradually, not all at once)
  - Lifecycle hooks: `destroyed` → `unmounted`, `beforeDestroy` → `beforeUnmount`
  - `v-model` changes, `$listeners` removal, filters → computed/methods
  - Custom directives API changes (`bind/update` → `mounted/updated`)
  - `Vue.ready()` custom helper → standard `createApp()` + `app.mount()`
  - Systematically resolve migration build warnings
  - After 0 warnings: remove `@vue/compat` → pure Vue 3

---

### Step 3.4.4: State Management (Pinia)

- **Goal**: Introduce Pinia as the official state manager
- **Why**: Pagekit currently does not use Vuex, but state is distributed across various patterns
  (global variables, event bus, `window.$pagekit`)
- **Tasks**:
  - Install and configure Pinia
  - Create central stores: Auth Store, Site Store, Notification Store
  - Migrate `window.$pagekit` configuration → Pinia store
  - Long-term: Replace event bus save flows with store actions (optional, after 3.4.2)

---

### Step 3.4.5: Additional Dependency Updates

- **Goal**: Update all remaining Vue-2-only dependencies
- **Important**: Some APIs are **platform APIs for extensions** (Formmaker, Listings, Events, etc.)
  and must be preserved as a stable API surface!

- **`vue-intl`** → **Completely replace** with native `Intl` API (NO wrapper, NO adapter!):

  - vue-intl is **deleted** — no code survives (Rule 4: DELETE OVER WRAP)
  - New implementation as Vue 3 plugin (`useIntl()` composable + `app.config.globalProperties`):
    - `$date(value, format)` — rewritten with `Intl.DateTimeFormat`
    - `$number(value, fractionSize)` — rewritten with `Intl.NumberFormat`
    - `$currency(amount, symbol)` — rewritten with `Intl.NumberFormat` style: 'currency'
    - `$relativeDate(date, options)` — rewritten with `Intl.RelativeTimeFormat`
  - **Same function names = Pagekit platform API** (not legacy compat, but stable API!)
  - Rule 3: "Breaking changes allowed internally, public behavior stays the same"
  - CLDR format names (`longDate`, `mediumDate`) → map to `Intl.DateTimeFormat` options
  - CLDR locale data (formats.json) → check if `Intl` API native locale data is sufficient
  - **Extensions need the API names**: Formmaker, Listings, Events, future extensions
  - Effort: Medium (~12 core files + new plugin)

- **`vue-nestable`** (~2.6.0) → Vue 3-compatible nested tree alternative:

  - Used for **hierarchical page tree** (3 files: site index, input-tree)
  - **NOT replaceable by UIkit sortable!** (sortable = flat lists, nestable = tree structure)
  - UIkit sortable is used separately (widgets, dashboard, roles) — different purpose!
  - Options: `@he-tree/vue` (Vue 3), custom tree component, or vue-nestable fork
  - **Drag-handle UX rework (admin page list) — added 2026-06-30:** Move the drag from the whole row to a dedicated left-side handle icon (as the mobile view already does) and stop the row/`.check-item` click from toggling selection. Removes the cosmetic "select-then-deselect" flicker on desktop reorder — a stray native `click` fires at drag-end and hits the `.check-item` click handler in `app/system/app/directives/check-all.js` (the up-vs-down asymmetry depends on whether mousedown+mouseup land on the same element). Also touches the `check-all` directive (reworked in 3.4.3). Discovered 2026-06-30 during the routing-cache fix.
  - Effort: Low (3 files)

- **`lodash`** (~4.17.21) → Native ES2020+ APIs + small utility file:

  - Currently: 70 KB loaded as a complete global script (only 24/300+ functions used)
  - ~80% of functions (19/24) have **direct native replacements** (find, map, filter, etc.)
  - ~20% (5/24) need small utilities: `deepMerge()`, `debounce()`, `isEmpty()`,
    `setByPath()`, `groupBy()` — together ~45 lines of code
  - Create custom `app/system/app/lib/utils.js` (~1 KB vs. 70 KB lodash)
  - **65+ files** need to be updated (mechanical but extensive)
  - Effort: High (65+ files, but mechanical search & replace work)

- **`vee-validate`** (3.3.11) → v4.x (Vue 3 Composition API):

  - `ValidationObserver`/`ValidationProvider` → `useForm()`/`useField()` composables
  - Effort: Medium

- **Build Tools**:
  - Webpack config for Vue 3 loader (`vue-loader` v17+)
  - Rebuild and test all bundles

---

### Step 3.4.6: Translation System Modernization

- **Goal**: Migrate frontend translation and formatting to the native Intl API (see vue-intl → Intl in 3.4.5) **and** complete backend translation alignment in one step (ICU migration + service-layer DI).
- **Content**: Formal bundling of the Intl platform API ($date, $number, $currency, $relativeDate); remove legacy plural bridges; inject `TranslatorInterface` in DI-capable PHP — template-facing global helpers `__()`/`_i()` stay (see keep-vs-remove decision below).
- **Prerequisite**: Step 2.0.2 (Validator-Translator Integration) — translator is already in the container.
- **Order**: Interleaved with 3.4.5 in implementation; single ROADMAP sub-step **3.4.6** (one ticket / one pipeline run).
- **Tasks (identified from 2.1.1 review):**
  - **Decision — keep vs. remove global functions** (best practice): global translation helpers are the legitimate DX API for **DI-less PHP templates/themes**, analogous to the `$date`/`$number` platform API. **Keep** `__()` (alias for `trans()`) and `_i()` (ICU MessageFormat), plus the `IntlServiceLocator` DI-glue that backs them (permanent — not a bridge to remove). **Remove** only the legacy `_c()` / `transChoice` plural bridge (below). The "no global functions" goal applies to **service/domain code** — there, inject `TranslatorInterface`. So this step _narrows_ the translation API, it does not delete the template-facing helpers.
  - **Remove transChoice (PHP + Vue):**
    - Remove global `_c()` function (`app/system/modules/intl/functions.php`)
    - Remove namespaced `Pagekit\_c()` (`functions-pagekit-namespace.php`)
    - Remove `transChoice` Twig filter (`app/system/modules/view/index.php`)
    - Remove `transChoice()` from Vue plugin (`app/system/app/lib/trans.js`)
    - Migrate all `|transChoice` calls in views:
      - `packages/pagekit/blog/views/admin/post-index.php` (3 occurrences)
      - `packages/pagekit/blog/views/admin/comment-index.php` (3 occurrences)
      - `app/system/modules/widget/views/index.php` (1 occurrence)
      - `app/system/modules/user/views/admin/user-index.php` (2 occurrences)
    - Migrate all `$tc()`/`transChoice` calls in JS/Vue files (~20 files) to `$t()` with ICU
  - **ICU Frontend Support:**
    - Implement Vue equivalent `$transICU()` in `app/system/app/lib/trans.js` (PHP-side `_i()` already exists)
  - **Translation-key extraction (optional, low priority):**
    - Extend `app/console/src/NodeVisitor/PhpNodeVisitor.php` to also extract message keys from PHP 8 `#[Assert\...]` attributes (the visitor currently only handles `__`, `_c`, `trans`, `transChoice` function calls). Feeds the `ExtensionTranslateCommand` extraction pipeline. (Routed from repo TODO inventory §2; carries a canonical `Step 3.4.6` TODO comment in the source.)
    - Extend the JS/Vue extraction in `app/console/src/Commands/ExtensionTranslateCommand.php` to honour the optional **domain** argument of `$trans()/$transChoice()` calls. The current regex only captures the message id and hardcodes the `'messages'` domain, so custom-domain strings in `.js` files (where the `| trans` filter is unavailable) are extracted into the wrong `.pot`. Best solved with a JS AST (mirroring `PhpNodeVisitor` on the PHP side). (Routed from repo TODO inventory §3; carries a canonical `Step 3.4.6` TODO comment in the source.)
  - **Replace forked Intl loaders with Symfony built-ins:**
    - Pagekit ships forks of Symfony's `ArrayLoader` / `PoFileLoader` / `MoFileLoader` in `app/system/modules/intl/src/Loader/`. The fork exists only to massage gettext plurals into Pagekit's legacy `|`-separated / `{N}`-prefixed `transChoice` format. Once transChoice → ICU lands (above), drop the forks and use `Symfony\Component\Translation\Loader\{Po,Mo}FileLoader` — which also support `msgctxt` contexts + catalogue metadata that the fork silently drops (resolves the canonical `Step 3.4.6` TODO in `PoFileLoader::parse()`). (Routed from repo TODO inventory §3.)
  - **TranslatorInterface in service layer** (same step — backend alignment):
    - Replace `use function Pagekit\__;` in **DI-capable** PHP with constructor-injected `Symfony\Contracts\Translation\TranslatorInterface`
    - **Keep** `__()` / `_i()` in: PHP view templates, mail templates, theme helper functions, any path without DI (same rule as `$date` / `$number` platform API)
    - **Migrate** in: controllers (~15+ files, e.g. `RegistrationController`, `MailController`, blog API controllers), event listeners with user-facing messages, domain services
    - Audit: `rg 'use function Pagekit\\__' --glob '*.php'` — classify each file as **inject** vs **keep helper**
    - Per module: add `TranslatorInterface` to constructor, replace `__('key')` with `$this->translator->trans('key', …)`, remove `use function Pagekit\__;`
    - Document the boundary in extension developer docs (templates = helpers; services = DI)
    - Optional: PHPStan note to flag new `use function Pagekit\__` under `src/` trees

---

## Step 3.5: Component Library

- **Goal**: Reusable Vue 3 component library for Pagekit Admin
- **Prerequisite**: Step 3.4 (Vue 3 Migration) must be completed!
- **Components**:
  - Design system based on UIkit 3.21+ tokens
  - Admin UI components (VModal, VPagination, VLoader, InputFilter, etc. — already existing, modernize)
  - Storybook integration for documentation and testing
  - Accessibility (a11y) audit and improvements
- **Strategy**: Use existing components in `app/system/app/components/` as a base, don't start from scratch

---

## Step 3.6: E2E Selector Strategy (data-testid)

- **Goal**: Stable, language-independent E2E selectors via `data-testid` instead of UIkit classes or label text
- **Why**: Reduces flakiness on UI or translation changes; Playwright recommends `getByTestId()`
- **Tasks**:
  - Add `data-testid` to critical flows (login, admin navigation, central forms)
  - Gradually migrate E2E specs to `getByTestId('…')`
  - Document convention (e.g., in `tests/e2e/README.md`)
- **Can run in parallel with 3.4/3.5** when templates/components are being worked on anyway

---

### Step 3.6.1: E2E Test Suite Rework (best-practice migration)

- **Status**: ⏳ Planned (added 2026-07-09; **re-homed from Step 2.1.9** during ticket planning — the full E2E rework is too large/orthogonal for the 2.1.9 coverage PR).
- **Goal**: Rework the Playwright E2E suite to follow current best practices so it is trustworthy for regression testing. The Phase 1 audit found most specs poorly written — only ~3 of the 11 specs (`01-setup/installation`, `02-core/authentication`, `02-core/dashboard`) are sound, and the Orchestrator/Tester currently runs only those three at end-of-ticket.
- **Prerequisite**: Step 3.6 (E2E Selector Strategy) — the rework should build on the `data-testid` selectors so specs are stable and language-independent (avoids reworking twice).
- **GitHub issue**: _to be created_ via the `github-issue-creator` skill (labels `phase-3, migration, frontend`; milestone "Phase 3: Frontend Modernization & Cross-Stack Alignment"). No issue exists yet — the original 1.10.5 issue (#135) is closed. NOTE: the Cloud-Agent architect cannot create issues under the read-only-`gh` constraint; create at the next opportunity with write access.
- **Closes Phase 1 audit:** **Step 1.10.5 (E2E Testing with Playwright) ⚠️ → 🛡️** — this step (NOT Step 2.1.9) resolves the 1.10.5 finding: "Most E2E tests were poorly created, not following best practices; only the first 3 tests are reasonably functional. Full E2E rework needed."
- **Tasks (draft)**:
  - Audit all 11 specs under `tests/e2e/specs/`; rewrite the weak ones (everything beyond the 3 sound specs) to Playwright best practices (web-first assertions, `getByTestId()` from 3.6, no arbitrary waits, isolated per-test state).
  - Fix flaky setup/teardown and align with the clean-state handling documented in `.cursor/agents/tester.md`.
  - Expand the Orchestrator/Tester end-of-ticket E2E set beyond the current 3 once the reworked specs are stable.
  - All specs green locally; document the convention in `tests/e2e/README.md`.
- **Risk**: Medium — broad E2E surface; orthogonal to the PHP test-coverage work in 2.1.9 / 2.9.
