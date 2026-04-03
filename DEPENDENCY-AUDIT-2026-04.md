# Dependency Audit Report -- April 2026

> **Scope:** All direct `composer.json` (PHP) and `package.json` (JS) dependencies.
> **Tool output:** `composer audit`, `composer outdated --direct`, `yarn audit`, `yarn outdated`.
> **Goal:** Identify vulnerabilities, deprecations, and staleness; propose a minimal safe update plan.

---

## 1. Executive Summary

| Ecosystem | Known Vulns | Outdated (direct) | Critical Action Items |
|-----------|-------------|--------------------|-----------------------|
| PHP (Composer) | **0** (roave/security-advisories enforced) | 28 packages (mostly Symfony major bump) | 3 safe patch-level bumps |
| JS (Yarn) | **59** (13 tinymce, 8 lodash, 8 picomatch, 7 brace-expansion, ...) | 35 packages | TinyMCE upgrade is urgent |

---

## 2. Vulnerability Details

### 2.1 PHP -- No Known Vulnerabilities

`composer audit` reports **zero** advisories. The `roave/security-advisories` dev dependency actively blocks installation of packages with known CVEs.

### 2.2 JavaScript -- 59 Vulnerabilities (5 Low / 36 Moderate / 18 High)

| Package | # CVEs | Severity | Root Cause | Fix Path |
|---------|--------|----------|------------|----------|
| **tinymce** | **13** | 1 low, 9 moderate, 3 high (XSS/mXSS) | Pinned at `~5.5.1`; TinyMCE 5 **EOL since April 2023** | Upgrade to `~5.10.9` (within v5) fixes 12 of 13; last CVE (iframe XSS) requires `>=6.8.1` |
| **lodash** | 8 | moderate (prototype pollution) | `4.17.23` via transitive; some paths are dev-only | `lodash` direct dep already at 4.17.23 -- transitive via `gulp-eslint` etc. |
| **picomatch** | 8 | high (ReDoS) | Transitive via webpack 4, chokidar 3 | Locked by webpack 4; no fix without webpack 5 |
| **braces** | 4 | high (resource consumption) | Transitive via webpack 4 > micromatch | Locked by webpack 4 |
| **micromatch** | 4 | moderate (ReDoS) | Transitive via webpack 4 | Locked by webpack 4 |
| **flatted** | 4 | high (prototype pollution) | Transitive via `gulp-eslint` > eslint > file-entry-cache | Locked by gulp-eslint 6 |
| **brace-expansion** | 7 | high (ReDoS) | Transitive via minimatch in multiple paths | Complex transitive; partially fixed by `glob` upgrade |
| **postcss** | 3 | moderate (ReDoS) | Transitive via vue-loader/css-loader | Dev-only; no runtime risk |
| **vue** | 2 | moderate | Vue 2.6.14 known issues | Locked until Vue 3 migration |
| **vue-template-compiler** | 1 | moderate | Mirrors Vue version | Locked until Vue 3 migration |
| **serialize-javascript** | 2 | high | Transitive via webpack 4 | Locked by webpack 4 |
| **elliptic** | 1 | high | Transitive via webpack 4 crypto | Locked by webpack 4 |
| **tmp** | 1 | high | Transitive | Dev-only |

---

## 3. PHP Dependency Analysis

### 3.1 Safe Patch/Minor Bumps (no code changes required)

| Package | Current | Available | Type | Risk |
|---------|---------|-----------|------|------|
| `symfony/validator` | 7.4.6 | 7.4.8 | patch | None |
| `twig/twig` | 3.23.0 | 3.24.0 | minor | Minimal -- Twig minor releases are BC |

**Recommendation:** Apply immediately via `composer update symfony/validator twig/twig`.

### 3.2 Major Upgrades Available -- NOT Recommended Yet

| Package | Current | Latest | Notes |
|---------|---------|--------|-------|
| **Symfony 6.4 -> 7.x** | 6.4.34 | 7.4.8 | 6.4 is LTS: bug fixes until **Nov 2026**, security fixes until **Nov 2027**. No urgency. Upgrade is a major project (PHP 8.2 min already met, but API surface changes throughout). |
| **doctrine/dbal 3 -> 4** | 3.10.5 | 4.4.3 | Significant breaking changes: type system rework, removed `requiresSQLCommentHint()`, changed type mappings. Requires thorough migration. Tracked in ROADMAP. |
| **doctrine/data-fixtures 1 -> 2** | 1.8.2 | 2.2.1 | Dev dependency; tied to DBAL major version. Upgrade with DBAL 4. |
| **php-debugbar 1 -> 3** | 1.23.6 | 3.6.4 | jQuery removal, widget API rewrite, storage format change. Medium-effort migration. |
| **phpunit 11 -> 12** | 11.5.55 | 12.5.16 | Dev dependency. PHPUnit 11 is supported. Upgrade at convenience. |
| **psr/log 2 -> 3** | 2.0.0 | 3.0.2 | Interface-only package; psr/log 3.0 identical API to 2.0 but drops PHP <8.1. Safe but requires checking all dependents accept `^3.0`. |
| **symfony/phpunit-bridge 6 -> 8** | 6.4.26 | 8.0.8 | Dev-only. Compatible with PHPUnit 11. Upgrade with PHPUnit 12 if desired. |
| **friendsofphp/php-cs-fixer** | 3.94+ | latest 3.x | Already on latest major; patch updates handled by `^3.94`. |

### 3.3 Potentially Removable

| Package | Version | Notes |
|---------|---------|-------|
| `paragonie/random-lib` | ~2.0.1 | PHP 8.2+ has native `random_bytes()` and `random_int()`. The `Random` class (`\Random\Randomizer`) in PHP 8.2 covers all use cases. **Candidate for removal** if call sites are migrated to native PHP. |

---

## 4. JavaScript Dependency Analysis

### 4.1 URGENT -- Security: TinyMCE

**Current:** `~5.5.1` (installed: 5.5.1)
**TinyMCE 5 EOL:** April 20, 2023 -- no more security patches.
**13 known XSS/mXSS vulnerabilities** affecting this version.

**Smallest safe fix:**

| Option | Target | CVEs Fixed | Breaking? |
|--------|--------|------------|-----------|
| A) `~5.10.9` | Stay on v5 | 12 of 13 | **Minimal** -- same major, same API surface. Plugin API unchanged. |
| B) `~6.8.1` | Jump to v6 | All 13 | **Moderate** -- TinyMCE 6 dropped some plugins, changed config keys. Commercial license changes. |
| C) `~7.0.0+` | Jump to v7/8 | All 13 | **High** -- major rewrite, premium-only features, licensing changes. |

**Recommendation:** Option A (`~5.10.9`) -- fixes 12/13 CVEs with minimal risk. The remaining CVE (iframe XSS, `>=6.8.1`) can be mitigated with CSP headers or deferred to the TinyMCE 7 migration.

### 4.2 Safe Patch Bumps (apply now)

| Package | Current | Target | Risk |
|---------|---------|--------|------|
| `@babel/preset-env` | 7.29.0 | 7.29.2 | patch, None |
| `@babel/runtime` | 7.28.6 | 7.29.2 | patch, None |
| `@playwright/test` | 1.58.2 | 1.59.1 | minor, E2E test runner -- low risk |
| `dotenv` | 17.3.1 | 17.4.0 | minor, None |
| `lodash` | 4.17.23 | 4.18.1 | minor, Broad usage but lodash minors are BC |

### 4.3 Constrained by Webpack 4 (defer to Webpack 5 migration)

These packages cannot be upgraded without also upgrading Webpack to v5:

| Package | Current | Latest | Blocked By |
|---------|---------|--------|------------|
| `webpack` | 4.47.0 | 5.105.4 | Major migration |
| `webpack-cli` | 3.3.12 | 7.0.2 | webpack 5 |
| `css-loader` | 4.3.0 | 7.1.4 | webpack 5 |
| `html-loader` | 1.3.2 | 5.1.0 | webpack 5 |
| `less-loader` | 7.3.0 | 12.3.2 | webpack 5 |
| `style-loader` | 1.3.0 | 4.0.0 | webpack 5 |
| `babel-loader` | 8.4.1 | 10.1.1 | webpack 5 |
| `eslint-webpack-plugin` | 2.7.0 | 6.0.0 | webpack 5 |
| `file-loader` | 6.1.1 | N/A | Replaced by asset modules in webpack 5 |

**Note:** This cluster is responsible for ~28 of the 59 yarn audit CVEs (picomatch, braces, micromatch, serialize-javascript, elliptic) via transitive dependencies.

### 4.4 Constrained by Vue 2 (defer to Vue 3 migration)

| Package | Current | Latest | Notes |
|---------|---------|--------|-------|
| `vue` | 2.6.14 | 3.5.32 | Vue 3 migration is a separate ROADMAP phase |
| `vue-template-compiler` | 2.6.14 | 2.7.16 | Must match `vue` version. Safe bump to `~2.7` only if Vue is also bumped to `~2.7` |
| `vue-loader` | 15.11.1 | 17.4.2 | v16+ requires Vue 3 |
| `vue-eslint-parser` | 7.11.0 | 10.4.0 | v8+ requires ESLint 8; v9+ requires ESLint 9 |
| `vee-validate` | 3.3.11 | 4.15.1 | v4 requires Vue 3 |

### 4.5 ESLint Ecosystem (defer to ESLint 9 flat config migration)

| Package | Current | Latest | Notes |
|---------|---------|--------|-------|
| `eslint` | 7.32.0 | 10.2.0 | ESLint 8 dropped node 12; ESLint 9 uses flat config. Major migration. |
| `eslint-config-prettier` | 6.15.0 | 10.1.8 | Tied to ESLint version |
| `eslint-plugin-vue` | 7.20.0 | 10.8.0 | Tied to ESLint version |
| `eslint-watch` | 7.0.0 | 8.0.0 | Tied to ESLint version |
| `babel-eslint` | 10.1.0 | deprecated | Replace with `@babel/eslint-parser` |
| `gulp-eslint` | 6.0.0 | deprecated | Transitive source of 4 `flatted` CVEs |

### 4.6 Other Notable Packages

| Package | Current | Latest | Notes |
|---------|---------|--------|-------|
| `marked` | 4.3.0 | 17.0.5 | v5+ dropped CJS build and deprecated many options. Major migration required. |
| `uikit` | 3.5.17 | 3.25.14 | Same major; minor/patch bumps. UIkit 3.x is BC but **large version gap** -- test thoroughly. |
| `gulp` | 4.0.2 | 5.0.1 | gulp 5 drops Node 10/12; new task runner internals. |
| `gulp-less` | 4.0.1 | 5.0.0 | Tied to gulp major |
| `less` | 3.13.1 | 4.6.4 | Less 4 has some breaking changes (math parsing). |
| `chokidar` | 3.6.0 | 5.0.0 | v4+ is ESM-only. Blocked by webpack 4. |
| `glob` | 7.2.3 | 13.0.6 | v8+ is ESM-only. |
| `cldr-core` | 36.0.0 | 48.2.0 | Data package; safe to bump but may change CLDR data output |
| `cldr-localenames-modern` | 36.0.0 | 45.0.0 | Same as above |
| GitHub forks (`Codemirror`, `JSONStorage`, `vue-intl`) | exotic | exotic | Pinned to GitHub `#master`; no version tracking possible. **Risk: unaudited.** |

---

## 5. Proposed Update Plan

### Phase 0 -- Immediate (no code changes, low risk)

```bash
# PHP patches
composer update symfony/validator twig/twig --with-all-dependencies

# JS patches
yarn upgrade @babel/preset-env@^7.29.2 @babel/runtime@^7.29.2 @playwright/test@^1.59.1 dotenv@^17.4.0 lodash@^4.18.1
```

**Estimated risk: Near-zero.** Run `phpunit` and `yarn compile-js` to verify.

### Phase 1 -- TinyMCE Security Fix (urgent, small code risk)

```
# In package.json, change:
"tinymce": "~5.5.1"  -->  "tinymce": "~5.10.9"
```

Then `yarn install && yarn compile-js --mode=production`.

**Test:** Load the admin editor, create/edit a page, verify toolbar and plugins still work. TinyMCE 5.10.x is API-compatible with 5.5.x.

### Phase 2 -- UIkit Minor Bump (moderate testing needed)

```
"uikit": "~3.5.8"  -->  "uikit": "~3.21.0"
```

**Risk:** UIkit 3.x maintains BC, but 16 minor versions is a large gap. Visual regression testing required across all admin pages and the default theme.

### Phase 3 -- Webpack 5 Migration (large, planned separately)

Upgrading webpack 4 -> 5 unblocks:
- 28 transitive vulnerability fixes
- Modern loaders (css-loader 7, html-loader 5, etc.)
- `file-loader` -> asset modules
- Tree shaking improvements

This is a **dedicated engineering effort** touching `webpack.config.js`, all loader configs, and potentially Vue SFC handling. Should be its own feature branch.

### Phase 4 -- ESLint Modernization (large, planned separately)

- Replace `babel-eslint` with `@babel/eslint-parser`
- Upgrade ESLint 7 -> 9 (flat config)
- Remove deprecated `gulp-eslint` (fixes 4 `flatted` CVEs)
- Update all eslint-plugin-* packages

### Phase 5 -- Doctrine DBAL 4 + Symfony 7 (largest, per ROADMAP)

Per the project ROADMAP. Symfony 6.4 LTS is supported until **Nov 2027**, so there is no immediate urgency. Plan the migration for when DBAL 4 and Symfony 7 benefits outweigh the migration cost.

### Phase 6 -- Vue 3 Migration (largest frontend effort, per ROADMAP)

Unlocks: vee-validate 4, vue-loader 17, modern Vue ecosystem, removal of vue-template-compiler.

---

## 6. Packages to Consider Removing

| Package | Reason | Replacement |
|---------|--------|-------------|
| `paragonie/random-lib` | PHP 8.2 `\Random\Randomizer` covers all use cases natively | Native PHP |
| `babel-eslint` | Deprecated since 2020 | `@babel/eslint-parser` |
| `gulp-eslint` | Unmaintained; source of 4 CVEs | Direct eslint CLI or `eslint-webpack-plugin` |
| `json-loader` | Built into webpack since v2 | Remove entirely |
| `file-loader` | Replaced by asset modules in webpack 5 | webpack 5 asset modules |

---

## 7. Risk Matrix Summary

| Risk Level | Packages |
|------------|----------|
| **CRITICAL (CVEs, EOL)** | `tinymce` (13 XSS CVEs, EOL) |
| **HIGH (CVEs, transitive)** | `braces`, `picomatch`, `flatted`, `serialize-javascript`, `elliptic` -- all locked by webpack 4 |
| **MEDIUM (stale, large gap)** | `uikit`, `marked`, `less`, `gulp`, `eslint` ecosystem |
| **LOW (working LTS)** | Symfony 6.4 stack, Doctrine DBAL 3, Vue 2.6 (planned migration) |
| **NONE** | `twig/twig`, `symfony/validator`, Babel patches, Playwright |
