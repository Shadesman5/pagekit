# 🎨 Phase 3: Frontend-Modernisierung – Das UI erneuern

**Ziel**: Frontend auf modernen Stack migrieren (Vue 3, UIkit 3.21+, TypeScript).
**Voraussetzung**: Phase 2 MUSS abgeschlossen sein (Build Tools in Schritt 2.4 sind Voraussetzung für Frontend-Arbeit!)

> **Analyse-Ergebnis (2026-02-11):** Alle Frontend-Dependencies wurden tiefgehend analysiert:
>
> **YOOtheme Vue-Libraries (archiviert):**
>
> - `vue-fields` (~1.1.3): **Nicht verwendet** in der Codebase → ignorieren
> - `vue-resource` (~1.5.1): **Stark verwendet** (32 Dateien, 4 Interceptors) → ersetzen durch `axios`
> - `vue-event-manager` (~2.1.3): **Moderat verwendet** (14 Dateien) → ersetzen durch `mitt`
> - Entscheidung: **Ersetzen statt Forken** — aktiv gewartete Standard-Libraries statt Eigenwartung
>
> **Weitere Dependencies:**
>
> - `vue-intl`: Nutzt intern custom AngularJS-Logik (kein natives Intl!). **Komplett ersetzen** durch
>   native `Intl` API. Plattform-API-Namen (`$date`, `$number`, `$currency`, `$relativeDate`) bleiben
>   als moderne Neuimplementierung (kein Wrapper!). Extensions wie Formmaker, Listings, Events nutzen diese.
> - `vue-nestable`: Hierarchischer Seiten-Baum. **NICHT durch UIkit sortable ersetzbar!**
>   (sortable = flache Listen, nestable = Baumstruktur mit Eltern/Kind)
> - `lodash`: 24/300+ Funktionen in 65+ Dateien, als 70 KB globales Script geladen.
>   ~80% nativ ersetzbar, Rest durch ~45 Zeilen eigene Utilities.
> - `$number`/`$currency` scheinen im Core ungenutzt, sind aber **Extension-APIs** (Listings, Formmaker)!

## Aktuelle Frontend-Dependencies (Ist-Zustand)

| Package             | Version          | Status                                 | Aktion in Phase 3                                                    |
| ------------------- | ---------------- | -------------------------------------- | -------------------------------------------------------------------- |
| `vue`               | ~2.6.12          | Legacy                                 | → Vue 3.x (Schritt 3.2 + 3.4)                                        |
| `vue-resource`      | ~1.5.1           | Archiviert, Vue-3-inkompatibel         | → `axios` (Schritt 3.4.1)                                            |
| `vue-event-manager` | ~2.1.3           | Archiviert, Vue-3-inkompatibel         | → `mitt` (Schritt 3.4.2)                                             |
| `vue-intl`          | uatrend/vue-intl | Community-Fork, custom AngularJS-Logik | → **Löschen & neu** mit native `Intl` API (Schritt 3.4.5)            |
| `vue-nestable`      | ~2.6.0           | Hierarchischer Seiten-Baum             | → Vue 3 Tree-Alternative (Schritt 3.4.5) — **NICHT UIkit sortable!** |
| `vee-validate`      | ~3.3.11          | Validierung                            | → `vee-validate` v4 (Vue 3 Composition API) (Schritt 3.4.5)          |
| `lodash`            | ~4.17.21         | 24/300+ Funktionen in 65+ Dateien      | → Native ES2020+ + `utils.js` (~1 KB) (Schritt 3.4.5)                |

---

## Schritt 3.1: UIkit Update

- **Branch**: `feature/uikit-update`
- **Ziel**: UIkit 3.5 → 3.21.x (latest)
- **Warum zuerst?**: UIkit ist unabhängig von Vue und kann isoliert aktualisiert werden
- **Tasks**:
  - UIkit Breaking Changes zwischen 3.5 und 3.21 analysieren
  - PHP-Templates aktualisieren (Blade-ähnliche `.php`-Views)
  - Vue-Components anpassen (UIkit JS-Initialisierung)
  - Visual Regression Testing mit Playwright Screenshots
  - Custom UIkit Theme anpassen (falls vorhanden)
- **Risiko**: Niedrig (UIkit ist CSS/JS-only, keine PHP-Abhängigkeit)

---

## Schritt 3.2: Vue.js 2.7 Migration (Bridge)

- **Branch**: `feature/vue-27-migration`
- **Ziel**: Vue 2.6 → 2.7 (Bridge-Version für sichere Migration zu Vue 3)
- **Warum Vue 2.7?**:
  - Vue 2.7 ist die letzte 2.x Version (Backport von Vue 3 Features)
  - Enthält Composition API aus Vue 3 (testbar in 2.x!)
  - Unterstützt `<script setup>` Syntax
  - Zeigt Deprecation Warnings für Vue 3 Breaking Changes
  - Macht Migration zu Vue 3 deutlich sicherer
  - `vue-resource` und `vue-event-manager` funktionieren noch unter 2.7!
- **Migration Path**: Vue 2.6 (AKTUELL) → Vue 2.7 (Bridge) → Vue 3.x (Ziel)
- **Tasks**:
  - `vue` Package auf 2.7 aktualisieren
  - Webpack/Build-Config für Vue 2.7 anpassen
  - Composition API in ausgewählten Komponenten testen
  - Deprecation Warnings systematisch analysieren und dokumentieren
  - Alle E2E-Tests durchlaufen lassen (Regression Check)
- **Wichtig**: In diesem Schritt bleiben `vue-resource` und `vue-event-manager` noch aktiv!

### Schritt 3.2.1: Template Pre-compilation (CSP) - Step Two

- **Voraussetzung**: Schritt 1.13.5 (CSP Step One, 80% erledigt) + Schritt 3.2
- **Ziel**: Alle Runtime-Template-Compilation eliminieren für vollständige CSP-Compliance

---

## Schritt 3.3: TypeScript Integration

- **Branch**: `feature/typescript`
- **Ziel**: TypeScript schrittweise ins Frontend einführen
- **Tasks**:
  - `tsconfig.json` konfigurieren (strict mode)
  - Webpack/Build-Pipeline für `.ts`-Dateien erweitern
  - Shared API Types definieren (aus PHP-Backend-Routen generieren)
  - Neue Composables/Utilities in TypeScript schreiben
  - Bestehende Components schrittweise typisieren (nicht alles auf einmal!)
- **Strategie**: TypeScript für neue Dateien erzwingen, bestehende `.js` schrittweise migrieren

---

## Schritt 3.4: Vue 3 Migration (MAJOR!)

- **Branch**: `feature/vue-3-migration`
- **Ziel**: Vue 2.7 → Vue 3.x (inkl. Austausch aller Vue-2-only Dependencies)
- **Voraussetzung**: Schritt 3.2 (Vue 2.7 Bridge) MUSS abgeschlossen sein!

> **⚠️ Dies ist der aufwändigste Schritt der gesamten Phase 3!**
> Die Vue 3 Migration betrifft nicht nur Vue selbst, sondern auch den Austausch von
> `vue-resource` und `vue-event-manager`, die Vue-3-inkompatibel sind.

- **Sub-Steps** (in dieser Reihenfolge):

---

### Schritt 3.4.1: HTTP Client Migration (vue-resource → axios)

- **Aufwand**: Hoch (~32 Dateien + 4 Interceptor-Module)
- **Ziel**: `vue-resource` komplett ersetzen durch `axios`
- **Warum axios?**:
  - Interceptor-System ist 1:1 kompatibel mit vue-resource's Interceptors
  - 46M+ wöchentliche Downloads, aktive Wartung, TypeScript-Support
  - Kann als Vue Plugin registriert werden (`app.config.globalProperties.$http`)
  - Security-Patches automatisch via Dependabot
- **Betroffene Bereiche**:
  - **4 Interceptor-Module** (müssen umgeschrieben werden):
    - `app/system/app/lib/csrf.js` → axios request interceptor
    - `app/system/app/lib/resourceCache.js` → axios request/response interceptor
    - `app/system/modules/user/app/interceptor.js` → axios response interceptor (401 → Login-Modal)
    - `app/system/modules/captcha/app/interceptor.js` → axios request interceptor (reCAPTCHA)
  - **~28 Vue Components/Views** mit `this.$http.get/post` → `this.$http.get/post` (axios, fast gleiche API)
  - **6 Dateien** mit `Vue.http` direkt → `axios` Instanz
  - **URI-Templates** (`api/user{/id}`) → kleine Utility-Funktion (~10 Zeilen)
  - **`Vue.url.route()`** → eigene URL-Helper Utility
- **Kann teilweise VOR Vue 3 gemacht werden** (axios ist Vue-version-unabhängig!)

---

### Schritt 3.4.2: Event System Migration (vue-event-manager → mitt)

- **Aufwand**: Mittel (~14 Dateien)
- **Ziel**: `vue-event-manager` komplett ersetzen durch `mitt` + Composable
- **Warum mitt?**:
  - Offiziell vom Vue-Team als Ersatz empfohlen
  - Winzig (~200 Bytes), zero dependencies
  - Event Priorities von vue-event-manager werden **nicht genutzt** im Projekt (analysiert!)
- **Betroffene Bereiche**:
  - **9 Dateien mit `$trigger`** (Emitter) → `emitter.emit()`
  - **6 Dateien mit `events: {}`** (Listener) → `onMounted`/`onUnmounted` + `emitter.on/off`
  - Events: `node-save`, `settings-save`, `user-save`, `post-save`, `widget-save`,
    `widget-cancel`, `saved:widget`, `settings-changed`, `finder-select`, `finder-ready`
- **Migration**:
  - Eigenes `useEventBus()` Composable erstellen (~10 Zeilen)
  - Langfristig: Save-Flows können zu Pinia Store Actions refactored werden

---

### Schritt 3.4.3: Vue 3 Core Migration

- **Ziel**: Vue 2.7 → Vue 3.x mit Migration Build
- **Tasks**:
  - Vue 3 + `@vue/compat` (Migration Build) installieren
  - `app/system/app/vue.js` komplett umschreiben (neuer App-Bootstrap)
  - Global API Changes: `Vue.use()` → `app.use()`, `Vue.component()` → `app.component()`
  - Options API → Composition API (schrittweise, nicht alles auf einmal)
  - Lifecycle Hooks: `destroyed` → `unmounted`, `beforeDestroy` → `beforeUnmount`
  - `v-model` Changes, `$listeners` Entfernung, Filters → Computed/Methods
  - Custom Directives API-Änderungen (`bind/update` → `mounted/updated`)
  - `Vue.ready()` Custom Helper → Standard `createApp()` + `app.mount()`
  - Migration Build Warnings systematisch abarbeiten
  - Nach 0 Warnings: `@vue/compat` entfernen → pure Vue 3

---

### Schritt 3.4.4: State Management (Pinia)

- **Ziel**: Pinia als offiziellen State Manager einführen
- **Warum**: Pagekit nutzt aktuell kein Vuex, aber State wird über diverse Patterns verteilt
  (globale Variablen, Event-Bus, `window.$pagekit`)
- **Tasks**:
  - Pinia installieren und konfigurieren
  - Zentrale Stores erstellen: Auth Store, Site Store, Notification Store
  - `window.$pagekit` Konfiguration → Pinia Store migrieren
  - Event-Bus Save-Flows langfristig durch Store Actions ersetzen (optional, nach 3.4.2)

---

### Schritt 3.4.5: Weitere Dependency-Updates

- **Ziel**: Alle verbleibenden Vue-2-only Dependencies aktualisieren
- **Wichtig**: Einige APIs sind **Plattform-APIs für Extensions** (Formmaker, Listings, Events, etc.)
  und müssen als stabile API-Oberfläche erhalten bleiben!

- **`vue-intl`** → **Komplett ersetzen** durch native `Intl` API (KEIN Wrapper, KEIN Adapter!):

  - vue-intl wird **gelöscht** — kein Code überlebt (Regel 4: DELETE OVER WRAP)
  - Neue Implementierung als Vue 3 Plugin (`useIntl()` Composable + `app.config.globalProperties`):
    - `$date(value, format)` — neu geschrieben mit `Intl.DateTimeFormat`
    - `$number(value, fractionSize)` — neu geschrieben mit `Intl.NumberFormat`
    - `$currency(amount, symbol)` — neu geschrieben mit `Intl.NumberFormat` style: 'currency'
    - `$relativeDate(date, options)` — neu geschrieben mit `Intl.RelativeTimeFormat`
  - **Gleiche Funktionsnamen = Pagekit Plattform-API** (nicht Legacy-Compat, sondern stabile API!)
  - Regel 3: "Breaking changes allowed internally, public behavior stays the same"
  - CLDR-Format-Namen (`longDate`, `mediumDate`) → `Intl.DateTimeFormat` Options mappen
  - CLDR Locale-Daten (formats.json) → prüfen ob `Intl` API native Locale-Daten ausreicht
  - **Extensions brauchen die API-Namen**: Formmaker, Listings, Events, zukünftige Extensions
  - Aufwand: Mittel (~12 Core-Dateien + neues Plugin)

- **`vue-nestable`** (~2.6.0) → Vue 3-kompatible Nested-Tree Alternative:

  - Wird für **hierarchischen Seiten-Baum** gebraucht (3 Dateien: site index, input-tree)
  - **NICHT durch UIkit sortable ersetzbar!** (sortable = flache Listen, nestable = Baumstruktur)
  - UIkit sortable wird separat verwendet (Widgets, Dashboard, Rollen) — andere Aufgabe!
  - Optionen: `@he-tree/vue` (Vue 3), eigenes Tree-Component, oder vue-nestable Fork
  - Aufwand: Niedrig (3 Dateien)

- **`lodash`** (~4.17.21) → Native ES2020+ APIs + kleine Utility-Datei:

  - Aktuell: 70 KB als komplettes globales Script geladen (nur 24/300+ Funktionen genutzt)
  - ~80% der Funktionen (19/24) haben **direkte native Ersetzungen** (find, map, filter, etc.)
  - ~20% (5/24) brauchen kleine Utilities: `deepMerge()`, `debounce()`, `isEmpty()`,
    `setByPath()`, `groupBy()` — zusammen ~45 Zeilen Code
  - Eigene `app/system/app/lib/utils.js` erstellen (~1 KB vs. 70 KB lodash)
  - **65+ Dateien** müssen aktualisiert werden (mechanisch, aber umfangreich)
  - Aufwand: Hoch (65+ Dateien, aber mechanische Search & Replace Arbeit)

- **`vee-validate`** (3.3.11) → v4.x (Vue 3 Composition API):

  - `ValidationObserver`/`ValidationProvider` → `useForm()`/`useField()` Composables
  - Aufwand: Mittel

- **Build Tools**:
  - Webpack-Config für Vue 3 Loader (`vue-loader` v17+)
  - Alle Bundles neu bauen und testen

---

### Schritt 3.4.6: Translation System Modernization

- **Ziel**: Frontend-Übersetzung und Formatierung auf native Intl-API umstellen (siehe vue-intl → Intl in 3.4.5).
- **Inhalt**: Formale Bündelung des Intl-Plattform-APIs ($date, $number, $currency, $relativeDate) und ggf. Backend-Anbindung (Symfony Translator DI, keine globalen Funktionen).
- **Reihenfolge**: In der Implementierung mit 3.4.5 verzahnt; in der ROADMAP als eigener Sub-Step 3.4.6 geführt.
- **Aufgaben (aus 2.1.1 Review identifiziert):**
  - **transChoice entfernen (PHP + Vue):**
    - Globale `_c()` Funktion entfernen (`app/system/modules/intl/functions.php`)
    - Namespaced `Pagekit\_c()` entfernen (`functions-pagekit-namespace.php`)
    - `transChoice` Twig-Filter entfernen (`app/system/modules/view/index.php`)
    - `transChoice()` aus Vue-Plugin entfernen (`app/system/app/lib/trans.js`)
    - Alle `|transChoice`-Aufrufe in Views migrieren:
      - `packages/pagekit/blog/views/admin/post-index.php` (3 Stellen)
      - `packages/pagekit/blog/views/admin/comment-index.php` (3 Stellen)
      - `app/system/modules/widget/views/index.php` (1 Stelle)
      - `app/system/modules/user/views/admin/user-index.php` (2 Stellen)
    - Alle `$tc()`/`transChoice`-Aufrufe in JS/Vue-Dateien (~20 Dateien) auf `$t()` mit ICU migrieren
  - **ICU Frontend-Support:**
    - Vue-Equivalent `$transICU()` in `app/system/app/lib/trans.js` implementieren (PHP-Seite `_i()` existiert bereits)

---

## Schritt 3.5: Component Library

- **Ziel**: Wiederverwendbare Vue 3 Component Library für Pagekit Admin
- **Voraussetzung**: Schritt 3.4 (Vue 3 Migration) muss abgeschlossen sein!
- **Components**:
  - Design System basierend auf UIkit 3.21+ Tokens
  - Admin UI Components (VModal, VPagination, VLoader, InputFilter, etc. — bereits vorhanden, modernisieren)
  - Storybook Integration für Dokumentation und Testing
  - Accessibility (a11y) Audit und Verbesserungen
- **Strategie**: Bestehende Components in `app/system/app/components/` als Basis nehmen, nicht von Null anfangen

---

## Schritt 3.6: E2E Selector Strategy (data-testid)

- **Ziel**: Stabile, sprachunabhängige E2E-Selektoren via `data-testid` statt UIkit-Klassen oder Label-Text
- **Warum**: Reduziert Flakiness bei UI- oder Übersetzungsänderungen; Playwright empfiehlt `getByTestId()`
- **Tasks**:
  - Kritische Flows (Login, Admin-Navigation, zentrale Formulare) mit `data-testid` anreichern
  - E2E-Specs schrittweise auf `getByTestId('…')` umstellen
  - Konvention dokumentieren (z. B. in `tests/e2e/README.md`)
- **Kann parallel zu 3.4/3.5** erfolgen, wenn ohnehin an Templates/Komponenten gearbeitet wird
