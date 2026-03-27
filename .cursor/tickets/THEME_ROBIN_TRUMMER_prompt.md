# Cloud Agent Prompt: Create Pagekit Theme "theme-flavor" (Robin Trummer Clone)

## Task Overview

Create a new Pagekit CMS theme called **`theme-flavor`** by forking the existing `theme-one` at `packages/pagekit/theme-one/`. The new theme must visually replicate the **Robin Trummer Personal Trainer** website at https://robin-trummer.framer.website/.

The new theme lives at `packages/pagekit/theme-flavor/`.

**Two deliverables:**
1. **The theme itself** — dark, modern UIkit 3.5 theme
2. **`insert-data.php`** — standalone script that populates Pagekit with all Robin Trummer content (pages, widgets, menus, theme config)

### Site Structure

This is **NOT** a pure one-pager. The site has:
- **Homepage** — One-pager landing page with sections (Hero, Services, About, Contact, etc.) built from widgets in positions
- **Unterseiten (Subpages)** — Regular content pages in the navbar menu:
  - **Impressum** (Legal Notice) — standard text page
  - **Datenschutz** (Privacy Policy) — standard text page
  - Potentially more subpages later (the theme must support both one-pager AND multi-page layouts)

### Visual Character
- Dark, moody aesthetic (gym/fitness vibe)
- Full-screen hero with background image
- Sticky transparent navbar
- Section-based layout with alternating dark sections
- Pricing cards, about section, product recommendations, contact form
- Mobile-responsive via UIkit 3.5
- Subpages: clean, readable text on dark background with consistent styling

---

## Reference Website: Design Specification

### Global Design Tokens

| Token | Value |
|-------|-------|
| **Primary Background** | `#0a0a0a` (near-black) |
| **Secondary Background** | `#111111` (dark gray, alternating sections) |
| **Accent Background** | `#1a1a1a` (cards, elevated surfaces) |
| **Primary Text** | `#ffffff` (white) |
| **Secondary Text** | `#a0a0a0` (muted gray for descriptions) |
| **Accent Color** | `#c8a96e` (warm gold — used for CTA buttons, highlights, borders) |
| **Accent Hover** | `#b8944e` (darker gold on hover) |
| **Font Headings** | Bold, uppercase, tight letter-spacing (use UIkit default or a system sans like `Inter`, `Helvetica Neue`, `Arial`) |
| **Font Body** | Clean sans-serif, `400` weight, ~16px base |
| **Border Radius** | Minimal (`2px` or `0`) — sharp, modern look |
| **Section Padding** | Large vertical padding (`80px-120px`) |

### Section-by-Section Breakdown

The reference site is a **single-page layout** with anchor navigation. Here is how each section maps to Pagekit widget positions:

#### 1. NAVBAR (Pagekit: `main` menu + `navbar` position)
- **Style**: Transparent over hero, becomes solid dark on scroll (sticky)
- **Left**: Logo text "Robin Trummer" (bold, white)
- **Right**: Navigation links: Services, Über mich, Kontakt (anchor links)
- **Right (extra)**: Social media icons (Instagram, YouTube, TikTok) — can be in `header` position
- **Mobile**: Hamburger menu → offcanvas

#### 2. HERO (Pagekit position: `hero`)
- **Layout**: Full viewport height (`100vh`), background image (gym interior, dark/moody)
- **Overlay**: Semi-transparent dark overlay on image (`rgba(0,0,0,0.5)`)
- **Content**: Centered vertically
  - Small label: "RESET & RISE – Maßgeschneidertes 1-zu-1 Personal Training in Hamburg."
  - Large heading: "DEIN WEG ZU MEHR KRAFT, FOKUS UND REGENERATION."
  - CTA button: "Erstgespräch sichern" (gold accent color, rounded slightly)
- **Below heading**: 3 feature badges in a row: "Ganzheitlicher Ansatz", "Individuelle Betreuung", "Technik- & Boxcoaching"

#### 3. QUOTE SECTION (Pagekit position: `top-a`)
- **Background**: Solid dark (`#111`)
- **Content**: Centered italic quote text with quotation marks
- **Quote**: "Entwicklung beginnt mit einer Entscheidung. RESET & RISE ist diese Entscheidung."
- **Style**: `uk-section-secondary`, medium padding, `uk-text-center`

#### 4. SERVICES / PRICING (Pagekit position: `top-b`)
- **Background**: Dark with subtle texture/gradient
- **Label**: Small uppercase "RESET & RISE 12-WOCHEN-PROGRAMM"
- **Heading**: "DEINE 3 MONATE TRANSFORMATION."
- **Layout**: 2-column grid (`uk-child-width-1-2@m`)
  - **Card 1**: "12-WOCHEN FOUNDATION"
    - Feature list (checkmarks)
    - Price: "1.900 € statt 2.500 €" (strikethrough on old price)
    - CTA button
  - **Card 2**: "ERNÄHRUNGSMODUL (6 Einheiten)"
    - Feature list
    - Price: "600 € statt 900 €"
    - CTA button
- **Card style**: Dark card with subtle border, slight elevation

#### 5. ABOUT (Pagekit position: `top-c`)
- **Background**: Slightly different dark shade
- **Label**: Small uppercase "ÜBER MICH"
- **Heading**: "ROBIN TRUMMER – DEIN COACH FÜR GESUNDHEIT & PERFORMANCE"
- **Layout**: 2-column (`uk-child-width-1-2@m`)
  - Left: Profile image (rounded or with subtle border)
  - Right: Bio text + CTA button "Mein Ansatz kennenlernen"

#### 6. RECOMMENDATIONS (Pagekit position: `bottom-a`)
- **Background**: Dark
- **Label**: "MEINE EMPFEHLUNGEN"
- **Heading**: "QUALITÄT FÜR DEINE PERFORMANCE."
- **Layout**: 2-column grid
  - Card 1: "RINGANA FRESH" — image + description + "Zum Shop" link
  - Card 2: "DYNAMIK PLUS" — image + description + "Zum Shop" link

#### 7. QUOTE 2 (Pagekit position: `bottom-b`)
- Same style as Quote 1 but different text
- "Der Abstand zwischen deinen Träumen und der Realität nennt sich Aktion."

#### 8. CONTACT (Pagekit position: `bottom-c`)
- **Background**: Dark with background image (gym, low opacity)
- **Label**: "KONTAKT"
- **Heading**: "LASS UNS DEIN RESET STARTEN."
- **Form fields**: Name, Email, Nachricht (textarea), Submit button
- **Form styling**: Dark inputs with subtle borders, gold accent submit button

#### 9. FOOTER (Pagekit position: `footer`)
- **Minimal**: Logo text left, nav links right
- **Copyright**: "© 2026 TTAGS"
- **Dark background**, small padding

---

## Technical Implementation

### Step 1: Fork theme-one

Copy the entire `packages/pagekit/theme-one/` directory to `packages/pagekit/theme-flavor/`.

### Step 2: Update Identifiers

**`composer.json`** — Change to:
```json
{
    "name": "pagekit/theme-flavor",
    "type": "pagekit-theme",
    "version": "1.0.0",
    "title": "Flavor",
    "description": "A dark, modern one-pager theme for personal brands and coaches.",
    "license": "MIT",
    "authors": [
        {
            "name": "Pagekit",
            "email": "info@pagekit.com",
            "homepage": "http://pagekit.com"
        }
    ],
    "extra": {
        "image": "image.jpg"
    },
    "archive": {
        "exclude": ["node_modules", "!/app", "!/css", "/app/assets", "gulpfile.js", "package.json"]
    }
}
```

**`index.php`** — Change `'name' => 'theme-flavor'`.

**`functions.php`** — Rename the helper class from `ThemeOneHelpers` to `ThemeFlavorHelpers`. Update ALL references (in `functions.php` AND `index.php`).

**`package.json`** — Change `"name": "pagekit-theme-flavor"`.

### Step 3: Adjust Widget Positions and Config

In `index.php`, keep positions identical to theme-one for maximum compatibility:

```php
'positions' => [
    'header'    => 'Header',
    'navbar'    => 'Navbar',
    'hero'      => 'Hero',
    'top-a'     => 'Top A',
    'top-b'     => 'Top B',
    'top-c'     => 'Top C',
    'sidebar'   => 'Sidebar',
    'bottom-a'  => 'Bottom A',
    'bottom-b'  => 'Bottom B',
    'bottom-c'  => 'Bottom C',
    'footer'    => 'Footer',
    'offcanvas' => 'Offcanvas'
],
```

Adjust the **default styles** in `node.positions`. IMPORTANT: `content_hide` must be `false` by default — subpages (Impressum, Datenschutz) use the page editor content, only the homepage uses widgets:

```php
'node' => [
    'title_hide' => false,
    'title_large' => false,
    'alignment' => '',
    'html_class' => '',
    'content_hide' => false,  // subpages need page content! homepage overrides this via _nodes config
    'sidebar_first' => false,
    'positions' => [
        'hero' => [
            'height' => 'full',
            'style'  => 'uk-section-secondary',
            'size'   => '',
            'header_transparent' => true,
            'header_transparent_noplaceholder' => true,
        ],
        'top-a' => [
            'style' => 'uk-section-secondary',
            'size'  => 'uk-section-large',
        ],
        'top-b' => [
            'style' => 'uk-section-secondary',
            'size'  => 'uk-section-large',
        ],
        'top-c' => [
            'style' => 'uk-section-secondary',
            'size'  => 'uk-section-large',
        ],
        'bottom-a' => [
            'style' => 'uk-section-secondary',
            'size'  => 'uk-section-large',
        ],
        'bottom-b' => [
            'style' => 'uk-section-secondary',
            'size'  => 'uk-section-large',
        ],
        'bottom-c' => [
            'style' => 'uk-section-secondary',
            'size'  => 'uk-section-large',
        ],
    ],
],
```

Update the global config to default to sticky transparent navbar:

```php
'config' => [
    'logo_contrast' => '',
    'logo_offcanvas' => '',
    'header' => [
        'layout' => 'horizontal-right',
        'fullwidth' => false,
        'logo_padding_remove' => false
    ],
    'navbar' => [
        'sticky' => 1,
        'dropbar' => '',
        'dropbar_align' => 'left',
        'dropdown_boundary' => false,
        'offcanvas' => [
            'mode' => 'slide',
            'overlay' => true,
            'flip' => false
        ]
    ]
],
```

### Step 4: LESS Customization (Main Visual Work)

The LESS files are in `less/theme/`. The key file is `less/theme/variables.less`. This is where the bulk of visual customization happens. UIkit 3.5 uses LESS variables extensively.

Create or update `less/theme/variables.less` with a **complete dark theme override**:

```less
// ========================================
// Theme Flavor — Dark One-Pager Variables
// ========================================

// Global
@global-color:                          #a0a0a0;
@global-emphasis-color:                 #ffffff;
@global-muted-color:                    #666666;
@global-link-color:                     #c8a96e;
@global-link-hover-color:               #b8944e;
@global-background:                     #0a0a0a;
@global-muted-background:               #111111;
@global-primary-background:             #c8a96e;
@global-secondary-background:           #111111;
@global-border:                         #222222;
@global-inverse-color:                  #ffffff;

// Font
@global-font-family:                    -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
@global-font-size:                      16px;
@global-line-height:                    1.7;

// Headings — bold, uppercase feel
@global-xxlarge-font-size:              3.5rem;
@global-xlarge-font-size:               2.5rem;
@global-large-font-size:                1.75rem;
@global-medium-font-size:               1.25rem;
@global-small-font-size:                0.875rem;

// Navbar
@navbar-background:                     transparent;
@navbar-color-mode:                     light;
@navbar-nav-item-color:                 rgba(255,255,255,0.8);
@navbar-nav-item-hover-color:           #ffffff;
@navbar-nav-item-active-color:          #c8a96e;
@navbar-sticky-background:              rgba(10,10,10,0.95);
@navbar-nav-item-font-size:             0.875rem;
@navbar-nav-item-text-transform:        uppercase;
@navbar-nav-item-letter-spacing:        0.1em;

// Section
@section-default-background:            @global-background;
@section-muted-background:              @global-muted-background;
@section-primary-background:            @global-primary-background;
@section-secondary-background:          @global-secondary-background;
@section-secondary-color-mode:          light;

// Card
@card-default-background:               #1a1a1a;
@card-default-color:                    @global-color;
@card-default-title-color:              @global-emphasis-color;
@card-default-border:                   #222222;
@card-primary-background:               @global-primary-background;
@card-primary-color:                    #0a0a0a;
@card-secondary-background:             #1a1a1a;

// Button
@button-default-background:             transparent;
@button-default-color:                  #ffffff;
@button-default-border:                 #c8a96e;
@button-default-hover-background:       #c8a96e;
@button-default-hover-color:            #0a0a0a;
@button-primary-background:             #c8a96e;
@button-primary-color:                  #0a0a0a;
@button-primary-hover-background:       #b8944e;
@button-primary-hover-color:            #0a0a0a;

// Form (for contact section)
@form-background:                       rgba(255,255,255,0.05);
@form-color:                            #ffffff;
@form-border:                           #333333;
@form-focus-border:                     #c8a96e;
@form-focus-background:                 rgba(255,255,255,0.08);
@form-placeholder-color:                #666666;

// Footer
@footer-background:                     #050505;

// Heading
@heading-hero-font-size:                4rem;
@heading-hero-line-height:              1.1;

// Text
@text-lead-color:                       #cccccc;
@text-meta-color:                       #666666;

// Offcanvas
@offcanvas-bar-background:              #111111;
@offcanvas-bar-color-mode:              light;

// Logo
@logo-color:                            #ffffff;
@logo-hover-color:                      #c8a96e;

// Divider
@divider-icon-color:                    #333333;
```

Additionally, add **custom LESS rules** in `less/theme.less` (or a new partial `less/theme/flavor.less` imported in `_import.less`):

```less
// Theme Flavor custom styles

// Hero overlay
.tm-hero-overlay {
    position: relative;
    &::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0, 0, 0, 0.55);
        z-index: 0;
    }
    > * {
        position: relative;
        z-index: 1;
    }
}

// Section label (small uppercase text above headings)
.tm-section-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.15em;
    color: #c8a96e;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

// Gold accent border on cards
.tm-card-accent {
    border: 1px solid #222;
    transition: border-color 0.3s ease;
    &:hover {
        border-color: #c8a96e;
    }
}

// Price display
.tm-price {
    font-size: 1.5rem;
    font-weight: 700;
    color: #c8a96e;
}
.tm-price-old {
    text-decoration: line-through;
    color: #666;
    font-size: 1rem;
    margin-left: 0.5rem;
}

// Feature list with custom checkmarks
.tm-feature-list {
    list-style: none;
    padding: 0;
    li {
        padding: 0.5rem 0;
        padding-left: 1.5rem;
        position: relative;
        &::before {
            content: '✓';
            position: absolute;
            left: 0;
            color: #c8a96e;
            font-weight: bold;
        }
    }
}

// Quote styling
.tm-quote {
    font-style: italic;
    font-size: 1.25rem;
    color: #cccccc;
    max-width: 700px;
    margin: 0 auto;
    &::before {
        content: '"';
        font-size: 3rem;
        color: #c8a96e;
        display: block;
        line-height: 1;
    }
}

// Smooth scroll behavior for one-pager
html {
    scroll-behavior: smooth;
}

// Feature badges in hero
.tm-hero-badges {
    display: flex;
    gap: 1.5rem;
    flex-wrap: wrap;
    justify-content: center;
    margin-top: 2rem;
    .tm-hero-badge {
        background: rgba(255,255,255,0.08);
        border: 1px solid rgba(255,255,255,0.12);
        padding: 0.75rem 1.5rem;
        font-size: 0.85rem;
        color: #ffffff;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
}
```

### Step 5: Template Adjustments

The PHP views in `views/` need minimal changes since theme-one's architecture already supports section-based layouts.

**`views/template.php`** — Mostly keep as-is. Optionally add smooth scroll and the dark body class:

```php
<html class="<?= $params['html_class'] ?>" lang="<?= $intl->getLocaleTag() ?>">
```
Add to `<body>`: `class="tm-theme-flavor"` for scoping custom CSS if needed.

**`views/section.php`** — Keep as-is. The section styling comes from LESS variables and position config.

**`views/header.php`** — Keep the current template, it already supports:
- Logo left + menu right (`horizontal-right` layout)
- Sticky navbar
- Transparent header over first section

**All other views** — Keep identical to theme-one.

### Step 6: JavaScript

**`js/theme.js`** — Keep the existing theme-one JavaScript. It already handles:
- Transparent header detection
- Sticky navbar behavior
- Min-height for main content
- UIkit component mixins

No changes needed unless you want to add smooth scroll for anchor links (UIkit's `uk-scroll` attribute handles this).

### Step 7: Vue Admin Components

**`app/components/site-theme.vue`** — Update the component's `settings-save` endpoint to use `this.name` (which will be `theme-flavor`). The rest of the settings UI remains the same.

**`app/components/node-theme.vue`** — Keep identical.

**`app/components/widget-theme.vue`** — Keep identical.

All three Vue files: Search-replace `window.$theme` references — they work based on the theme module name, so just changing `index.php`'s `name` is sufficient.

### Step 8: Build Pipeline

**`gulpfile.js`** — Keep identical. It compiles `less/theme.less` → `css/theme.css`.

**`webpack.config.js`** — Keep identical. It bundles the 3 Vue admin components.

**`package.json`** — Already updated in Step 2.

### Step 9: Build & Verify

After all changes:

```bash
cd packages/pagekit/theme-flavor
npm install       # installs UIkit + dev deps, runs gulp (postinstall)
npx webpack       # builds admin Vue bundles
```

Verify:
- `css/theme.css` exists and contains dark theme styles
- `app/bundle/node-theme.js`, `site-theme.js`, `widget-theme.js` exist
- `app/assets/uikit/` is populated

---

## File Checklist

| File | Action |
|------|--------|
| `composer.json` | Rename to `pagekit/theme-flavor` |
| `index.php` | Change name to `theme-flavor`, update helper class name, update node defaults |
| `functions.php` | Rename `ThemeOneHelpers` → `ThemeFlavorHelpers` |
| `package.json` | Rename to `pagekit-theme-flavor` |
| `less/theme/variables.less` | **Complete rewrite** with dark theme tokens |
| `less/theme.less` or new `less/theme/flavor.less` | Add custom component styles (hero overlay, badges, quote, pricing, feature list) |
| `less/theme/_import.less` | Add `@import "flavor.less";` if using separate file |
| `views/template.php` | Add `tm-theme-flavor` body class |
| `views/header.php` | No changes needed |
| `views/section.php` | No changes needed |
| `views/position-grid.php` | No changes needed |
| `views/position-panel.php` | No changes needed |
| `views/position-blank.php` | No changes needed |
| `views/menu-navbar.php` | No changes needed |
| `views/offcanvas.php` | No changes needed |
| `views/header-logo.php` | No changes needed |
| `app/components/site-theme.vue` | No changes needed (uses `this.name` dynamically) |
| `app/components/node-theme.vue` | No changes needed |
| `app/components/widget-theme.vue` | No changes needed |
| `js/theme.js` | No changes needed |
| `gulpfile.js` | No changes needed |
| `webpack.config.js` | No changes needed |

---

## Important Constraints

1. **PHP 8.2+ strict typing** — Maintain typed properties and return types.
2. **UIkit 3.5** — Use UIkit classes and components exclusively. No additional CSS frameworks.
3. **Vue 2.6** — Admin components use Vue 2 Options API. Do NOT use Vue 3 syntax.
4. **No hardcoded content** — The theme provides layout and styling only. All text content comes from Pagekit widgets/pages.
5. **Vendor dir is `app/vendor/`** — Pagekit convention, but the theme has its own `node_modules/`.
6. **Comments in English** — All code comments must be in English.
7. **No WordPress/Laravel code** — This is Pagekit (Symfony-based).
8. **The LESS variables are the main lever** — UIkit themes are customized primarily through LESS variable overrides. The theme partials in `less/theme/` mirror UIkit components and override their variables.

---

## Step 10: Create `insert-data.php` — Demo Content Installer

This is the **second deliverable**. Create the file at:

```
packages/pagekit/theme-flavor/scripts/insert-data.php
```

This script is a **standalone PHP script** that the user runs AFTER Pagekit is installed and the theme-flavor is activated. It populates the database with all the Robin Trummer website content.

### How Pagekit's Content Model Works (Reference)

**Database tables** (prefix `pk_`):

| Table | Purpose |
|-------|---------|
| `@system_page` | Page content (HTML body) — columns: `id`, `title`, `content`, `data` (JSON) |
| `@system_node` | Menu entries / routes — columns: `id`, `parent_id`, `priority`, `status`, `title`, `slug`, `path`, `link`, `type`, `menu`, `roles`, `data` (JSON) |
| `@system_widget` | Widgets — columns: `id`, `title`, `type`, `status`, `nodes` (node IDs for visibility), `roles`, `data` (JSON with `content` key) |
| `@system_config` | Config buckets — columns: `id`, `name` (unique), `value` (JSON) |

**Theme config** is stored in `@system_config` with `name = 'theme-flavor'`. The JSON value contains:

```json
{
    "_menus": { "main": "main", "offcanvas": "main" },
    "_positions": { "hero": [1, 2], "top-a": [3], "footer": [10] },
    "_widgets": { "1": { "title_hide": true, "title_size": "uk-h3", "alignment": true, "html_class": "", "panel": "" } },
    "_nodes": { "1": { "title_hide": true, "content_hide": true, "positions": { "hero": { "style": "uk-section-secondary", "height": "full" } } } }
}
```

**Key relationships:**
- `@system_page.id` is referenced by `@system_node.link` as `@page/{id}` and `@system_node.data` as `{"defaults":{"id":{page_id}}}`
- Widget visibility: `@system_widget.nodes` = comma-separated node IDs (empty = all pages)
- Widget placement: `_positions` in theme config maps position name → array of widget IDs
- Frontpage: `system/site` config → `frontpage` = node ID

### The Existing Pattern (from `app/installer/install-demo.php`)

The script gets `$app` via `require` context. Pattern:

```php
$db = $app->get('db');
$config = $app->get('config');

$config->set('system/site', $config('system/site')->merge([
    'frontpage' => 1, 'view' => ['logo' => 'storage/pagekit-logo.svg']
]));

$db->insert('@system_page', ['title' => 'Home', 'content' => '<p>...</p>', 'data' => '{"title":null}']);
$db->insert('@system_node', ['priority' => 1, 'status' => 1, 'title' => 'Home', 'slug' => 'home', 'path' => '/home', 'link' => '@page/1', 'type' => 'page', 'menu' => 'main', 'data' => '{"defaults":{"id":1}}']);
$db->insert('@system_widget', ['title' => 'Hero', 'type' => 'system/text', 'status' => 1, 'nodes' => 1, 'data' => '{"content":"<h1>...</h1>"}']);
$db->insert('@system_config', ['name' => 'theme-one', 'value' => '{...JSON...}']);
```

### insert-data.php: Requirements

The script must be a **self-contained, bootstrapping PHP script** that:

1. Bootstraps the Pagekit application (requires `config.php` to exist)
2. Checks that theme-flavor is active (warn if not)
3. **Cleans existing demo content** (truncates pages, nodes, widgets, removes theme config) with a safety prompt
4. Inserts all content for the Robin Trummer website
5. Reports success with a summary

### insert-data.php: Bootstrap Pattern

```php
#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    exit('This script must be run from the command line.');
}

$rootDir = realpath(__DIR__ . '/../../../../');

if (!file_exists($rootDir . '/config.php')) {
    exit("Error: config.php not found. Run the Pagekit installer first.\n");
}

$configFile = $rootDir . '/config.php';
$autoload = $rootDir . '/app/autoload.php';

$config = require $configFile;
$config['path'] = $rootDir;
$config['path.packages'] = $rootDir . '/packages/*/*';
$config['config.file'] = $configFile;

require $autoload;

use Pagekit\Application;
use Pagekit\Module\Loader\AutoLoader;
use Pagekit\Module\Loader\ConfigLoader;

$app = new Application($config);

$app->get('module')->register([
    'packages/*/*/index.php',
    'app/modules/*/index.php',
    'app/system/index.php',
], $rootDir);

$app->get('module')->addLoader(new AutoLoader($app->get('autoloader')));
$app->get('module')->addLoader(new ConfigLoader(require $rootDir . '/app/system/config.php'));
$app->get('module')->addLoader(new ConfigLoader(require $configFile));
$app->get('module')->load('system');

$db = $app->get('db');
$configMgr = $app->get('config');

echo "=== Theme Flavor: Content Installer ===\n\n";
```

NOTE: If the bootstrap approach above is too complex or fragile, an alternative simpler approach is acceptable: create the script to be `require`'d from the Pagekit CLI context, similar to how `install-demo.php` works, where `$app` is already available. In that case, the file header should document: "Run via: `php pagekit flavor:insert-data`" and register as a console command, OR simply document: "Include from Pagekit context" and provide a small wrapper.

**The simplest viable approach**: A PHP script that the user runs with:
```bash
cd /path/to/pagekit
php packages/pagekit/theme-flavor/scripts/insert-data.php
```

### insert-data.php: Content to Insert

#### Pages (`@system_page`)

| ID | Title | Type | Content |
|----|-------|------|---------|
| 1 | Home | Widget-driven (content_hide=true on this node) | Minimal — `<p>Homepage</p>` (content is hidden, widgets do the work) |
| 2 | Impressum | Standard page | Full legal notice HTML. Use placeholder text: company name "Robin Trummer / TTAGS", address placeholder "Musterstraße 1, 20095 Hamburg", etc. Mark clearly with `<!-- PLACEHOLDER: Replace with real legal data -->` |
| 3 | Datenschutz | Standard page | Privacy policy HTML. Use standard DSGVO structure with placeholder data. Mark with `<!-- PLACEHOLDER -->` comments |

#### Nodes / Menu (`@system_node`)

| ID | Title | Slug | Path | Menu | Link | Type | Priority | Notes |
|----|-------|------|------|------|------|------|----------|-------|
| 1 | Home | home | /home | main | @page/1 | page | 1 | Frontpage |
| 2 | Services | services | /services | main | @page/1#tm-top-b | link | 2 | Anchor to services section |
| 3 | Über mich | ueber-mich | /ueber-mich | main | @page/1#tm-top-c | link | 3 | Anchor to about section |
| 4 | Kontakt | kontakt | /kontakt | main | @page/1#tm-bottom-c | link | 4 | Anchor to contact section |
| 5 | Impressum | impressum | /impressum | — | @page/2 | page | 5 | NOT in main menu, accessible via footer link |
| 6 | Datenschutz | datenschutz | /datenschutz | — | @page/3 | page | 6 | NOT in main menu, accessible via footer link |

Note on anchor links: Pagekit's node types for anchor links may need `type => 'link'` with `link` set to the URL including the anchor. Check how the link type works. If `link` type doesn't exist, use `type => 'page'` pointing to the homepage page and add the anchor in the menu template. An alternative approach: just use regular page links without anchors in the navbar and let the one-pager scroll behavior be handled by the widget HTML content with `id` attributes that match the section IDs (`#tm-top-b`, etc.).

IMPORTANT: The section IDs are auto-generated by `section.php` as `id="tm-{$name}"` — so `hero` → `#tm-hero`, `top-b` → `#tm-top-b`, etc.

#### Widgets (`@system_widget`)

All widgets use `type => 'system/text'` and `status => 1`.

**Homepage-only widgets** (set `nodes => 1` to restrict to homepage):

| Widget ID | Position | Title | Content Summary |
|-----------|----------|-------|-----------------|
| 1 | hero | Hero Content | Subline "RESET & RISE – Maßgeschneidertes 1-zu-1 Personal Training in Hamburg." + H1 "DEIN WEG ZU MEHR KRAFT, FOKUS UND REGENERATION." + subtitle "Starte jetzt deine Transformation." + CTA button `<a class="uk-button uk-button-primary uk-button-large" href="#tm-bottom-c" uk-scroll>Erstgespräch sichern</a>` + hero badges div |
| 2 | hero | Hero Profile Image | Profile image widget: `<img src="storage/theme-flavor/robin-trummer-profil.jpg" alt="Robin Trummer" class="uk-border-circle" width="120">` + name below |
| 3 | top-a | Quote 1 | `<div class="tm-quote uk-text-center">"Entwicklung beginnt mit einer Entscheidung. RESET & RISE ist diese Entscheidung."</div>` |
| 4 | top-b | Services Section | Full HTML: section label + heading + 2-column grid with pricing cards (12-Wochen Foundation + Ernährungsmodul). Use `.tm-feature-list`, `.tm-price`, `.tm-card-accent` classes. |
| 5 | top-c | About Section | Full HTML: section label "ÜBER MICH" + heading + 2-column grid (image left, bio text right) + CTA button |
| 6 | bottom-a | Recommendations | Full HTML: section label "MEINE EMPFEHLUNGEN" + heading + 2-column grid with product cards (Ringana Fresh + Dynamik Plus) with external links |
| 7 | bottom-b | Quote 2 | `<div class="tm-quote uk-text-center">"Der Abstand zwischen deinen Träumen und der Realität nennt sich Aktion. Verstehen beginnt mit Erleben."</div>` |
| 8 | bottom-c | Contact Section | Full HTML: section label "KONTAKT" + heading "LASS UNS DEIN RESET STARTEN." + contact form (Name, Email, Nachricht textarea, Submit button). Use UIkit form classes + `.uk-button-primary` for submit. Form `action` can be `#` as placeholder. |

**Global widgets** (visible on ALL pages, `nodes` empty/null):

| Widget ID | Position | Title | Content Summary |
|-----------|----------|-------|-----------------|
| 9 | footer | Footer | Compact footer: logo text "Robin Trummer" + nav links (Services, Über mich, Kontakt, Impressum, Datenschutz) + copyright "© 2026 TTAGS" + Social icons (Instagram, YouTube, TikTok as `uk-icon` links) |

#### Theme Config (`@system_config`, name = `theme-flavor`)

The JSON value must contain:

```json
{
    "_menus": {
        "main": "main",
        "offcanvas": "main"
    },
    "_positions": {
        "hero": [1, 2],
        "top-a": [3],
        "top-b": [4],
        "top-c": [5],
        "bottom-a": [6],
        "bottom-b": [7],
        "bottom-c": [8],
        "footer": [9],
        "navbar": [],
        "header": [],
        "sidebar": []
    },
    "_widgets": {
        "1": { "title_hide": true, "title_size": "uk-h3", "alignment": true, "html_class": "", "panel": "" },
        "2": { "title_hide": true, "title_size": "uk-h3", "alignment": true, "html_class": "", "panel": "" },
        "3": { "title_hide": true, "title_size": "uk-h3", "alignment": true, "html_class": "", "panel": "" },
        "4": { "title_hide": true, "title_size": "uk-h3", "alignment": "", "html_class": "", "panel": "" },
        "5": { "title_hide": true, "title_size": "uk-h3", "alignment": "", "html_class": "", "panel": "" },
        "6": { "title_hide": true, "title_size": "uk-h3", "alignment": "", "html_class": "", "panel": "" },
        "7": { "title_hide": true, "title_size": "uk-h3", "alignment": true, "html_class": "", "panel": "" },
        "8": { "title_hide": true, "title_size": "uk-h3", "alignment": "", "html_class": "", "panel": "" },
        "9": { "title_hide": true, "title_size": "uk-h3", "alignment": true, "html_class": "", "panel": "" }
    },
    "_nodes": {
        "1": {
            "title_hide": true,
            "title_large": false,
            "alignment": true,
            "html_class": "",
            "content_hide": true,
            "sidebar_first": false,
            "positions": {
                "hero": {
                    "height": "full",
                    "style": "uk-section-secondary",
                    "size": "",
                    "image": "",
                    "image_position": "",
                    "effect": "",
                    "width": "",
                    "vertical_align": "middle",
                    "padding_remove_top": false,
                    "padding_remove_bottom": false,
                    "preserve_color": false,
                    "overlap": false,
                    "header_transparent": true,
                    "header_preserve_color": false,
                    "header_transparent_noplaceholder": true
                },
                "top-a": { "style": "uk-section-secondary", "size": "uk-section-large", "image": "", "image_position": "", "effect": "", "width": "", "height": "", "vertical_align": "middle", "padding_remove_top": false, "padding_remove_bottom": false, "preserve_color": false, "overlap": false, "header_transparent": false, "header_preserve_color": false, "header_transparent_noplaceholder": false },
                "top-b": { "style": "uk-section-secondary", "size": "uk-section-large", "image": "", "image_position": "", "effect": "", "width": "", "height": "", "vertical_align": "middle", "padding_remove_top": false, "padding_remove_bottom": false, "preserve_color": false, "overlap": false, "header_transparent": false, "header_preserve_color": false, "header_transparent_noplaceholder": false },
                "top-c": { "style": "uk-section-default", "size": "uk-section-large", "image": "", "image_position": "", "effect": "", "width": "", "height": "", "vertical_align": "middle", "padding_remove_top": false, "padding_remove_bottom": false, "preserve_color": false, "overlap": false, "header_transparent": false, "header_preserve_color": false, "header_transparent_noplaceholder": false },
                "main": { "style": "uk-section-default", "size": "uk-section-large", "image": "", "image_position": "", "effect": "", "width": "", "height": "", "vertical_align": "middle", "padding_remove_top": false, "padding_remove_bottom": false, "preserve_color": false, "overlap": false, "header_transparent": false, "header_preserve_color": false, "header_transparent_noplaceholder": false },
                "bottom-a": { "style": "uk-section-secondary", "size": "uk-section-large", "image": "", "image_position": "", "effect": "", "width": "", "height": "", "vertical_align": "middle", "padding_remove_top": false, "padding_remove_bottom": false, "preserve_color": false, "overlap": false, "header_transparent": false, "header_preserve_color": false, "header_transparent_noplaceholder": false },
                "bottom-b": { "style": "uk-section-secondary", "size": "", "image": "", "image_position": "", "effect": "", "width": "", "height": "", "vertical_align": "middle", "padding_remove_top": false, "padding_remove_bottom": false, "preserve_color": false, "overlap": false, "header_transparent": false, "header_preserve_color": false, "header_transparent_noplaceholder": false },
                "bottom-c": { "style": "uk-section-secondary", "size": "uk-section-large", "image": "", "image_position": "", "effect": "", "width": "", "height": "", "vertical_align": "middle", "padding_remove_top": false, "padding_remove_bottom": false, "preserve_color": false, "overlap": false, "header_transparent": false, "header_preserve_color": false, "header_transparent_noplaceholder": false }
            }
        },
        "5": {
            "title_hide": false,
            "title_large": false,
            "alignment": false,
            "html_class": "",
            "content_hide": false,
            "sidebar_first": false,
            "positions": {
                "hero": { "style": "uk-section-secondary", "size": "", "height": "", "image": "", "image_position": "", "effect": "", "width": "", "vertical_align": "middle", "padding_remove_top": false, "padding_remove_bottom": false, "preserve_color": false, "overlap": false, "header_transparent": false, "header_preserve_color": false, "header_transparent_noplaceholder": false },
                "main": { "style": "uk-section-default", "size": "uk-section-large", "image": "", "image_position": "", "effect": "", "width": "", "height": "", "vertical_align": "middle", "padding_remove_top": false, "padding_remove_bottom": false, "preserve_color": false, "overlap": false, "header_transparent": false, "header_preserve_color": false, "header_transparent_noplaceholder": false }
            }
        },
        "6": {
            "title_hide": false,
            "title_large": false,
            "alignment": false,
            "html_class": "",
            "content_hide": false,
            "sidebar_first": false,
            "positions": {
                "hero": { "style": "uk-section-secondary", "size": "", "height": "", "image": "", "image_position": "", "effect": "", "width": "", "vertical_align": "middle", "padding_remove_top": false, "padding_remove_bottom": false, "preserve_color": false, "overlap": false, "header_transparent": false, "header_preserve_color": false, "header_transparent_noplaceholder": false },
                "main": { "style": "uk-section-default", "size": "uk-section-large", "image": "", "image_position": "", "effect": "", "width": "", "height": "", "vertical_align": "middle", "padding_remove_top": false, "padding_remove_bottom": false, "preserve_color": false, "overlap": false, "header_transparent": false, "header_preserve_color": false, "header_transparent_noplaceholder": false }
            }
        }
    }
}
```

Note: Node IDs 5 and 6 (Impressum/Datenschutz) get their own `_nodes` entry with `content_hide: false` so the page editor content is shown. Node 1 (Home) gets `content_hide: true` because everything comes from widgets.

#### Site Config Update

```php
$configMgr->set('system/site', $configMgr('system/site')->merge([
    'frontpage' => 1,
    'view' => ['logo' => '']  // text logo, set in admin
]));
```

### insert-data.php: Safety Features

1. **Check if config.php exists** — exit with error if not
2. **Check for existing content** — if `@system_page` has rows, print warning and ask for confirmation (or use `--force` flag)
3. **Transaction** — wrap all inserts in a database transaction, rollback on error
4. **ID tracking** — use `$db->lastInsertId()` after each insert to track auto-generated IDs (do NOT hardcode IDs — they might not start at 1 if the installer already created content)
5. **Output progress** — print each step: "Creating pages... OK", "Creating menu nodes... OK", etc.

### insert-data.php: Placeholder Images

The script should create a `storage/theme-flavor/` directory and document which images the user needs to provide:

```
storage/theme-flavor/
  hero-bg.jpg          — Hero background (dark gym interior, 1920x1080+)
  robin-profil.jpg     — Profile photo (square, 400x400+)
  ringana.jpg          — Ringana product image (400x300)
  dynamikplus.jpg      — Dynamik Plus product image (400x300)
```

The script should create placeholder references in the widget HTML. If images don't exist, the sections still work (just without background images).

---

## Updated File Checklist

| File | Action |
|------|--------|
| `composer.json` | Rename to `pagekit/theme-flavor` |
| `index.php` | Change name to `theme-flavor`, update helper class name, update node defaults |
| `functions.php` | Rename `ThemeOneHelpers` → `ThemeFlavorHelpers` |
| `package.json` | Rename to `pagekit-theme-flavor` |
| `less/theme/variables.less` | **Complete rewrite** with dark theme tokens |
| `less/theme.less` or new `less/theme/flavor.less` | Add custom component styles (hero overlay, badges, quote, pricing, feature list) |
| `less/theme/_import.less` | Add `@import "flavor.less";` if using separate file |
| `views/template.php` | Add `tm-theme-flavor` body class |
| `views/system/site/page.php` | Ensure subpages render cleanly with title + content on dark bg |
| `views/header.php` | No changes needed |
| `views/section.php` | No changes needed |
| `views/position-grid.php` | No changes needed |
| `views/position-panel.php` | No changes needed |
| `views/position-blank.php` | No changes needed |
| `views/menu-navbar.php` | No changes needed |
| `views/offcanvas.php` | No changes needed |
| `views/header-logo.php` | No changes needed |
| `app/components/site-theme.vue` | No changes needed |
| `app/components/node-theme.vue` | No changes needed |
| `app/components/widget-theme.vue` | No changes needed |
| `js/theme.js` | No changes needed |
| `gulpfile.js` | No changes needed |
| `webpack.config.js` | No changes needed |
| **`scripts/insert-data.php`** | **NEW** — Demo content installer |

---

## Expected Result

### After theme build + activation:
- Dark, professional aesthetic on all pages
- Gold accent colors on buttons, links, and highlights
- Transparent sticky navbar
- Full-height hero section support on homepage
- Clean dark cards and sections
- Properly styled forms
- Readable subpages (Impressum, Datenschutz) with clean dark text layout
- Responsive mobile layout via UIkit grid

### After running `insert-data.php`:
- Homepage with all 8 widget sections populated (Hero, Quote, Services, About, Recommendations, Quote 2, Contact, Footer)
- Main menu with: Home, Services (anchor), Über mich (anchor), Kontakt (anchor)
- Subpages: Impressum and Datenschutz with placeholder legal text
- Footer with links to all pages including Impressum/Datenschutz
- Theme config fully set up with position assignments and per-node settings
- User just needs to: upload images to `storage/theme-flavor/`, set logo in admin, customize text
