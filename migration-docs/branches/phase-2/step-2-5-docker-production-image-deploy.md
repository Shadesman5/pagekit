# Step 2.5 — Docker Production Image & Deploy

<!-- Branch doc for Roadmap Step 2.5.
     Path: migration-docs/branches/phase-2/step-2-5-docker-production-image-deploy.md -->

**Branch:** `feature/docker-production-image`
**ROADMAP Step:** 2.5 (Docker Production Image & Deploy)
**GitHub Issue:** [#158](https://github.com/Shadesman5/pagekit/issues/158)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-07-31 03:10
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### Env-override config layer, trusted proxies & weather-key removal (Checklist Step 1)

| File | Change |
|---|---|
| `app/modules/application/src/Module/Loader/EnvConfigLoader.php` (new) | `ConfigLoader` reading the fixed env map: `PAGEKIT_DEBUG` → `application.debug` (bool via `FILTER_VALIDATE_BOOLEAN`); `PAGEKIT_SECRET` → `system.secret`; `PAGEKIT_DB_DRIVER` (validated against `mysql`/`sqlite`, else throws) + `_HOST/_PORT(int)/_NAME/_USER/_PASSWORD/_PREFIX` → `database.connections.mysql.*`; `PAGEKIT_DB_PATH` → `database.connections.sqlite.path`; `PAGEKIT_WEATHER_API_KEY` → `system/dashboard`'s flat `weather.key`. Reads via `getenv()`; a variable that is unset is left out of the map (no-op), one that is set-but-empty overrides with `''`. |
| `app/modules/application/src/Application/TrustedProxies.php` (new) | `configureFromEnvironment()` parses `PAGEKIT_TRUSTED_PROXIES` (comma-separated addresses/CIDRs, or the literal `REMOTE_ADDR`) and calls `Request::setTrustedProxies()` with `HEADER_X_FORWARDED_FOR\|HOST\|PORT\|PROTO` (`X-Forwarded-Prefix` deliberately excluded); no-op when the variable is unset or blank. |
| `app/modules/application/src/Application.php` | `run()` calls `TrustedProxies::configureFromEnvironment()` immediately before building the request via `Request::createFromGlobals()` — only on that path; a `Request` passed into `run()` keeps whatever trust its caller already configured. |
| `app/system/app.php` | `EnvConfigLoader` registered as the last module loader, after the existing `config.file` `ConfigLoader`. |
| `app/console/app.php` | `EnvConfigLoader` registered last, unconditionally on `config.file` (previously the whole loader-plus-`system`-load block was gated by that one `if`); `module->load('system')` now sits in its own `if ($configFile)` check so a console run with no config yet still builds the env-aware loader chain. |
| `app/installer/app.php` | `EnvConfigLoader` registered last, after the installer's own `ConfigLoader`. |
| `app/system/modules/dashboard/index.php` | Hardcoded OpenWeatherMap key and its `AUDIT FIX Step 2.5` comment removed; `weather.key` default is now `''`. |
| `prod.env.example` (new) | Placeholders (one-line comment each) for every var `EnvConfigLoader`/`TrustedProxies` consume so far: `PAGEKIT_DEBUG`, `PAGEKIT_SECRET`, `PAGEKIT_DB_DRIVER/HOST/PORT/NAME/USER/PASSWORD/PREFIX/PATH`, `PAGEKIT_TRUSTED_PROXIES`, `PAGEKIT_WEATHER_API_KEY`. Grows with compose/entrypoint vars in later Checklist Steps. |

### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `app/modules/application/src/Tests/EnvConfigLoaderTest.php` (new) | Fixed-map correctness (`PAGEKIT_DEBUG` bool-spelling data provider, `PAGEKIT_DB_PORT` int-cast, `PAGEKIT_DB_DRIVER` rejecting an unknown driver, flat `weather.key` shape), unset-env leaves modules untouched, a set-but-empty variable overrides with `''`, and loader-chain precedence (`config.php` survives when the env is silent; the env wins when both are set). |
| `app/modules/application/src/Tests/TrustedProxiesTest.php` (new) | `parse()` against comma/whitespace/stray-comma input and the `REMOTE_ADDR` literal; no-op when the variable is unset or blank; a trusted proxy's `X-Forwarded-*` headers decide `isSecure()`/host/port/client IP while `X-Forwarded-Prefix` and the RFC 7239 `Forwarded` header stay untrusted; `Application::run()` wiring, incl. a caller-supplied `Request` keeping its own trust untouched. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer PASS; Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

---

## 🧠 Key Decisions (Rationale)

_TBD / None_

---

## 💥 Breaking Changes (Extensions)

_TBD / None_

---

## ⚠️ Risks & Rollout Notes

- **Weather widget default changes from a shared key to empty (Checklist Step 1).** `system/dashboard`'s `weather.key` no longer ships a working default; the dashboard's location widget shows its "unavailable" state on upgrade until an operator sets `PAGEKIT_WEATHER_API_KEY` or `config.php`'s `system/dashboard.weather.key`. Provider-side rotation of the old key is tracked under Maintainer action (Finalize).

---

## 🔐 Security & Data Impact

- **Committed OpenWeatherMap API key removed (Checklist Step 1).** `app/system/modules/dashboard/index.php`'s hardcoded key and its `AUDIT FIX Step 2.5` marker are gone; the module now defaults `weather.key` to `''` and takes it instead from `PAGEKIT_WEATHER_API_KEY` (via `EnvConfigLoader`) or `config.php`. The key remains in prior git history — provider-side rotation is Maintainer action.
- **Signing secret and DB credentials become environment-settable (Checklist Step 1).** `EnvConfigLoader` lets `PAGEKIT_SECRET` and the MySQL/SQLite connection parameters come from the process environment instead of a writable `config.php` — the basis the immutable-image config model (later Checklist Steps) builds on.

---

## 🛡️ No-Mercy Compliance

- **Rule 4 (Delete over wrap) — Checklist Step 1:** the hardcoded OpenWeatherMap key is deleted outright, not gated behind an env check with the old value kept as fallback; the empty-string default is what the module's own `config('weather.key', '')` call already handles.
- **Rule 5 (audit debt closed) — Checklist Step 1:** the `AUDIT FIX Step 2.5` marker in `app/system/modules/dashboard/index.php` is removed now that the work it flagged is done.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: _TBD / None_

---

## 📋 Phase 1 Audit Closure

_TBD / None_

---

## 👤 Maintainer action

<!-- Human-only follow-ups the maintainer must do (ruleset flips, real Docker/Apache
     verification, secrets, etc.). Not ROADMAP deferrals — those go under Deferred. -->

_TBD / None_

---

## 📚 Deferred / Out-of-Scope

<!-- Future ROADMAP/PHASE work, explicit non-goals, bridges. Do NOT put maintainer
     Manual Work here — that belongs under Maintainer action above. -->

_TBD / None_

---

## 📌 Follow-on (ROADMAP)

_TBD / None_

---

## 🧊 Parked (unplanned)

_TBD / None_

---

## 🧹 Cleanup

_TBD / None_

---

## 🛡️ Audit

_TBD / None_

---

## 🎁 Bonus

_TBD / None_

---

## 🔍 Research

_TBD / None_

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_5_Docker-Production-Image_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_5_Docker-Production-Image.md`
- Predecessor: Step 2.4.1 — Webroot Modernization (public/)
- Successor: Step 2.6 — Filesystem Write Resilience

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
