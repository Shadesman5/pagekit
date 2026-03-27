#!/usr/bin/env php
<?php
/**
 * Theme Flavor — Demo Content Installer
 *
 * Populates Pagekit with Robin Trummer Personal Trainer content.
 * Run: php packages/pagekit/theme-flavor/scripts/insert-data.php [--force]
 */

if (PHP_SAPI !== 'cli') {
    exit('This script must be run from the command line.');
}

$rootDir = realpath(__DIR__ . '/../../../../');

if (!$rootDir || !file_exists($rootDir . '/config.php')) {
    exit("Error: config.php not found. Run the Pagekit installer first.\n");
}

$configFile = $rootDir . '/config.php';
$autoload   = $rootDir . '/app/autoload.php';

if (!file_exists($autoload)) {
    exit("Error: app/autoload.php not found. Is this a valid Pagekit installation?\n");
}

$config = require $configFile;
$config['path']             = $rootDir;
$config['path.packages']    = $rootDir . '/packages/*/*';
$config['config.file']      = $configFile;

require $autoload;

$app = new Pagekit\Application($config);
$app->get('module')->addLoader(new Pagekit\Module\Loader\ConfigLoader(require $rootDir . '/app/system/config.php'));
$app->get('module')->addLoader(new Pagekit\Module\Loader\ConfigLoader($config));
$app->get('module')->load('system');

$db        = $app->get('db');
$configMgr = $app->get('config');

$force = in_array('--force', $argv, true);

echo "=== Theme Flavor: Content Installer ===\n\n";

// Safety check
$existingPages = $db->fetchColumn("SELECT COUNT(*) FROM @system_page");
if ($existingPages > 0 && !$force) {
    echo "WARNING: Database already contains {$existingPages} page(s).\n";
    echo "This script will TRUNCATE pages, nodes, widgets, and theme config.\n";
    echo "Run with --force to proceed, or back up your data first.\n\n";
    exit(1);
}

// Create image storage directory
$storageDir = $rootDir . '/storage/theme-flavor';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
    echo "[OK] Created storage/theme-flavor/ directory\n";
}

echo "Starting content insertion...\n\n";

try {
    $db->beginTransaction();

    // Clean existing content
    echo "[1/6] Cleaning existing content... ";
    $db->executeQuery("DELETE FROM @system_widget");
    $db->executeQuery("DELETE FROM @system_node");
    $db->executeQuery("DELETE FROM @system_page");
    $db->executeQuery("DELETE FROM @system_config WHERE name = 'theme-flavor'");
    echo "OK\n";

    // =========================================================================
    // Pages
    // =========================================================================
    echo "[2/6] Creating pages... ";

    $db->insert('@system_page', [
        'title'   => 'Home',
        'content' => '<p>Homepage</p>',
        'data'    => '{"title":null}',
    ]);
    $homePageId = (int) $db->lastInsertId();

    $db->insert('@system_page', [
        'title'   => 'Impressum',
        'content' => impressumContent(),
        'data'    => '{"title":null}',
    ]);
    $impressumPageId = (int) $db->lastInsertId();

    $db->insert('@system_page', [
        'title'   => 'Datenschutz',
        'content' => datenschutzContent(),
        'data'    => '{"title":null}',
    ]);
    $datenschutzPageId = (int) $db->lastInsertId();

    echo "OK (3 pages)\n";

    // =========================================================================
    // Nodes / Menu
    // =========================================================================
    echo "[3/6] Creating menu nodes... ";

    $db->insert('@system_node', [
        'priority'  => 1,
        'status'    => 1,
        'title'     => 'Home',
        'slug'      => 'home',
        'path'      => '/home',
        'link'      => '@page/' . $homePageId,
        'type'      => 'page',
        'menu'      => 'main',
        'data'      => json_encode(['defaults' => ['id' => $homePageId]]),
    ]);
    $homeNodeId = (int) $db->lastInsertId();

    $db->insert('@system_node', [
        'priority'  => 2,
        'status'    => 1,
        'title'     => 'Services',
        'slug'      => 'services',
        'path'      => '/services',
        'link'      => '/home#tm-top-b',
        'type'      => 'link',
        'menu'      => 'main',
        'data'      => '{}',
    ]);
    $servicesNodeId = (int) $db->lastInsertId();

    $db->insert('@system_node', [
        'priority'  => 3,
        'status'    => 1,
        'title'     => "\xC3\x9Cber mich",
        'slug'      => 'ueber-mich',
        'path'      => '/ueber-mich',
        'link'      => '/home#tm-top-c',
        'type'      => 'link',
        'menu'      => 'main',
        'data'      => '{}',
    ]);
    $aboutNodeId = (int) $db->lastInsertId();

    $db->insert('@system_node', [
        'priority'  => 4,
        'status'    => 1,
        'title'     => 'Kontakt',
        'slug'      => 'kontakt',
        'path'      => '/kontakt',
        'link'      => '/home#tm-bottom-c',
        'type'      => 'link',
        'menu'      => 'main',
        'data'      => '{}',
    ]);
    $contactNodeId = (int) $db->lastInsertId();

    $db->insert('@system_node', [
        'priority'  => 5,
        'status'    => 1,
        'title'     => 'Impressum',
        'slug'      => 'impressum',
        'path'      => '/impressum',
        'link'      => '@page/' . $impressumPageId,
        'type'      => 'page',
        'menu'      => '',
        'data'      => json_encode(['defaults' => ['id' => $impressumPageId]]),
    ]);
    $impressumNodeId = (int) $db->lastInsertId();

    $db->insert('@system_node', [
        'priority'  => 6,
        'status'    => 1,
        'title'     => 'Datenschutz',
        'slug'      => 'datenschutz',
        'path'      => '/datenschutz',
        'link'      => '@page/' . $datenschutzPageId,
        'type'      => 'page',
        'menu'      => '',
        'data'      => json_encode(['defaults' => ['id' => $datenschutzPageId]]),
    ]);
    $datenschutzNodeId = (int) $db->lastInsertId();

    echo "OK (6 nodes)\n";

    // =========================================================================
    // Widgets
    // =========================================================================
    echo "[4/6] Creating widgets... ";

    $widgetIds = [];

    // Widget 1: Hero Content (homepage only)
    $db->insert('@system_widget', [
        'title'  => 'Hero Content',
        'type'   => 'system/text',
        'status' => 1,
        'nodes'  => (string) $homeNodeId,
        'data'   => json_encode(['content' => heroContent()]),
    ]);
    $widgetIds[] = (int) $db->lastInsertId();

    // Widget 2: Hero Profile (homepage only) — skipped as secondary hero element
    // Instead we embed profile in hero content above. Use slot for optional profile image.
    $db->insert('@system_widget', [
        'title'  => 'Hero Profile',
        'type'   => 'system/text',
        'status' => 1,
        'nodes'  => (string) $homeNodeId,
        'data'   => json_encode(['content' => heroProfileContent()]),
    ]);
    $widgetIds[] = (int) $db->lastInsertId();

    // Widget 3: Quote 1 (top-a)
    $db->insert('@system_widget', [
        'title'  => 'Quote 1',
        'type'   => 'system/text',
        'status' => 1,
        'nodes'  => (string) $homeNodeId,
        'data'   => json_encode(['content' => '<div class="tm-quote uk-text-center">' . "\n" . 'Entwicklung beginnt mit einer Entscheidung. RESET &amp; RISE ist diese Entscheidung.' . "\n" . '</div>']),
    ]);
    $widgetIds[] = (int) $db->lastInsertId();

    // Widget 4: Services / Pricing (top-b)
    $db->insert('@system_widget', [
        'title'  => 'Services',
        'type'   => 'system/text',
        'status' => 1,
        'nodes'  => (string) $homeNodeId,
        'data'   => json_encode(['content' => servicesContent()]),
    ]);
    $widgetIds[] = (int) $db->lastInsertId();

    // Widget 5: About (top-c)
    $db->insert('@system_widget', [
        'title'  => 'About',
        'type'   => 'system/text',
        'status' => 1,
        'nodes'  => (string) $homeNodeId,
        'data'   => json_encode(['content' => aboutContent()]),
    ]);
    $widgetIds[] = (int) $db->lastInsertId();

    // Widget 6: Recommendations (bottom-a)
    $db->insert('@system_widget', [
        'title'  => 'Recommendations',
        'type'   => 'system/text',
        'status' => 1,
        'nodes'  => (string) $homeNodeId,
        'data'   => json_encode(['content' => recommendationsContent()]),
    ]);
    $widgetIds[] = (int) $db->lastInsertId();

    // Widget 7: Quote 2 (bottom-b)
    $db->insert('@system_widget', [
        'title'  => 'Quote 2',
        'type'   => 'system/text',
        'status' => 1,
        'nodes'  => (string) $homeNodeId,
        'data'   => json_encode(['content' => '<div class="tm-quote uk-text-center">' . "\n" . 'Der Abstand zwischen deinen Tr&auml;umen und der Realit&auml;t nennt sich Aktion. Verstehen beginnt mit Erleben.' . "\n" . '</div>']),
    ]);
    $widgetIds[] = (int) $db->lastInsertId();

    // Widget 8: Contact (bottom-c)
    $db->insert('@system_widget', [
        'title'  => 'Contact',
        'type'   => 'system/text',
        'status' => 1,
        'nodes'  => (string) $homeNodeId,
        'data'   => json_encode(['content' => contactContent()]),
    ]);
    $widgetIds[] = (int) $db->lastInsertId();

    // Widget 9: Footer (global, all pages)
    $db->insert('@system_widget', [
        'title'  => 'Footer',
        'type'   => 'system/text',
        'status' => 1,
        'nodes'  => '',
        'data'   => json_encode(['content' => footerContent($impressumNodeId, $datenschutzNodeId)]),
    ]);
    $widgetIds[] = (int) $db->lastInsertId();

    echo "OK (9 widgets)\n";

    // =========================================================================
    // Theme Config
    // =========================================================================
    echo "[5/6] Setting theme configuration... ";

    $themeConfig = buildThemeConfig($widgetIds, $homeNodeId, $impressumNodeId, $datenschutzNodeId);
    $db->insert('@system_config', [
        'name'  => 'theme-flavor',
        'value' => json_encode($themeConfig),
    ]);

    echo "OK\n";

    // =========================================================================
    // Site Config
    // =========================================================================
    echo "[6/6] Updating site configuration... ";

    $configMgr->set('system/site', $configMgr('system/site')->merge([
        'frontpage' => $homeNodeId,
        'view'      => ['logo' => ''],
    ]));

    echo "OK\n";

    $db->commit();

    echo "\n=== Installation Complete ===\n\n";
    echo "Summary:\n";
    echo "  Pages:   3 (Home, Impressum, Datenschutz)\n";
    echo "  Nodes:   6 (Home, Services, Ueber mich, Kontakt, Impressum, Datenschutz)\n";
    echo "  Widgets: 9 (Hero, Profile, Quote1, Services, About, Recommendations, Quote2, Contact, Footer)\n";
    echo "\n";
    echo "Next steps:\n";
    echo "  1. Activate theme-flavor in the Pagekit admin (Site > Settings > Theme)\n";
    echo "  2. Upload images to storage/theme-flavor/:\n";
    echo "     - hero-bg.jpg      (Hero background, 1920x1080+)\n";
    echo "     - robin-profil.jpg (Profile photo, square, 400x400+)\n";
    echo "     - ringana.jpg      (Product image, 400x300)\n";
    echo "     - dynamikplus.jpg  (Product image, 400x300)\n";
    echo "  3. Set your logo text in admin: Site > Settings > Theme\n";
    echo "  4. Customize content via the Pagekit admin widget editor\n";
    echo "\n";

} catch (\Throwable $e) {
    $db->rollBack();
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

// =============================================================================
// Content Helper Functions
// =============================================================================

function heroContent(): string
{
    return <<<'HTML'
<div class="uk-text-center">
    <p class="tm-section-label">RESET &amp; RISE &ndash; Ma&szlig;geschneidertes 1-zu-1 Personal Training in Hamburg.</p>
    <h1 class="uk-heading-medium uk-margin-remove-top">DEIN WEG ZU MEHR KRAFT, FOKUS UND REGENERATION.</h1>
    <p class="uk-text-lead">Starte jetzt deine Transformation.</p>
    <p class="uk-margin-medium-top">
        <a class="uk-button uk-button-primary uk-button-large" href="#tm-bottom-c" uk-scroll>Erstgespr&auml;ch sichern</a>
    </p>
    <div class="tm-hero-badges">
        <div class="tm-hero-badge">Ganzheitlicher Ansatz</div>
        <div class="tm-hero-badge">Individuelle Betreuung</div>
        <div class="tm-hero-badge">Technik- &amp; Boxcoaching</div>
    </div>
</div>
HTML;
}

function heroProfileContent(): string
{
    return <<<'HTML'
<div class="uk-text-center uk-margin-top">
    <img src="storage/theme-flavor/robin-profil.jpg" alt="Robin Trummer" class="uk-border-circle" width="120" height="120" onerror="this.style.display='none'">
    <p class="uk-text-small uk-text-muted uk-margin-small-top">Robin Trummer</p>
</div>
HTML;
}

function servicesContent(): string
{
    return <<<'HTML'
<div class="uk-text-center uk-margin-large-bottom">
    <p class="tm-section-label">RESET &amp; RISE 12-WOCHEN-PROGRAMM</p>
    <h2 class="uk-heading-small">DEINE 3 MONATE TRANSFORMATION.</h2>
</div>

<div class="uk-grid-large uk-child-width-1-2@m" uk-grid>
    <div>
        <div class="uk-card uk-card-default uk-card-body tm-card-accent">
            <h3 class="uk-card-title">12-WOCHEN FOUNDATION</h3>
            <ul class="tm-feature-list">
                <li>12 Wochen strukturiertes Training</li>
                <li>Individueller Trainingsplan</li>
                <li>W&ouml;chentliche Check-ins</li>
                <li>Technik-Coaching (inkl. Boxen)</li>
                <li>Regenerations-Protokoll</li>
                <li>WhatsApp-Support</li>
            </ul>
            <p>
                <span class="tm-price">1.900 &euro;</span>
                <span class="tm-price-old">2.500 &euro;</span>
            </p>
            <p class="uk-margin-medium-top">
                <a class="uk-button uk-button-primary" href="#tm-bottom-c" uk-scroll>Jetzt starten</a>
            </p>
        </div>
    </div>
    <div>
        <div class="uk-card uk-card-default uk-card-body tm-card-accent">
            <h3 class="uk-card-title">ERN&Auml;HRUNGSMODUL (6 Einheiten)</h3>
            <ul class="tm-feature-list">
                <li>6 Ern&auml;hrungsberatungen</li>
                <li>Individueller Ern&auml;hrungsplan</li>
                <li>Makro- &amp; Mikron&auml;hrstoff-Analyse</li>
                <li>Supplement-Beratung</li>
                <li>Rezepte &amp; Meal-Prep Tipps</li>
                <li>Nachhaltige Gewohnheiten</li>
            </ul>
            <p>
                <span class="tm-price">600 &euro;</span>
                <span class="tm-price-old">900 &euro;</span>
            </p>
            <p class="uk-margin-medium-top">
                <a class="uk-button uk-button-primary" href="#tm-bottom-c" uk-scroll>Jetzt buchen</a>
            </p>
        </div>
    </div>
</div>
HTML;
}

function aboutContent(): string
{
    return <<<'HTML'
<div class="uk-text-center uk-margin-large-bottom">
    <p class="tm-section-label">&Uuml;BER MICH</p>
    <h2 class="uk-heading-small">ROBIN TRUMMER &ndash; DEIN COACH F&Uuml;R GESUNDHEIT &amp; PERFORMANCE</h2>
</div>

<div class="uk-grid-large uk-child-width-1-2@m uk-flex-middle" uk-grid>
    <div>
        <img src="storage/theme-flavor/robin-profil.jpg" alt="Robin Trummer" class="uk-width-1-1" style="border-radius: 4px;" onerror="this.style.display='none'">
    </div>
    <div>
        <p class="uk-text-lead">Seit &uuml;ber 8 Jahren begleite ich Menschen auf ihrem Weg zu mehr Kraft, Gesundheit und mentaler St&auml;rke.</p>
        <p>Mein Ansatz verbindet funktionelles Training, Boxcoaching und ganzheitliche Regeneration. Ich glaube daran, dass echte Ver&auml;nderung mit einer bewussten Entscheidung beginnt &ndash; und mit konsequentem Handeln Realit&auml;t wird.</p>
        <p>Als zertifizierter Personal Trainer und Ern&auml;hrungsberater in Hamburg biete ich dir ein ma&szlig;geschneidertes 1-zu-1 Programm, das auf deine individuellen Ziele und Bed&uuml;rfnisse abgestimmt ist.</p>
        <p class="uk-margin-medium-top">
            <a class="uk-button uk-button-default" href="#tm-bottom-c" uk-scroll>Meinen Ansatz kennenlernen</a>
        </p>
    </div>
</div>
HTML;
}

function recommendationsContent(): string
{
    return <<<'HTML'
<div class="uk-text-center uk-margin-large-bottom">
    <p class="tm-section-label">MEINE EMPFEHLUNGEN</p>
    <h2 class="uk-heading-small">QUALIT&Auml;T F&Uuml;R DEINE PERFORMANCE.</h2>
</div>

<div class="uk-grid-large uk-child-width-1-2@m" uk-grid>
    <div>
        <div class="uk-card uk-card-default uk-card-body tm-card-accent">
            <div class="uk-margin-bottom">
                <img src="storage/theme-flavor/ringana.jpg" alt="RINGANA FRESH" class="uk-width-1-1" style="border-radius: 4px;" onerror="this.style.display='none'">
            </div>
            <h3 class="uk-card-title">RINGANA FRESH</h3>
            <p>Frische, vegane Nahrungserg&auml;nzung und Naturkosmetik f&uuml;r deinen aktiven Lifestyle. Nachhaltig, ethisch und wirkungsvoll.</p>
            <p>
                <a class="uk-button uk-button-default" href="#" target="_blank" rel="noopener">Zum Shop</a>
            </p>
        </div>
    </div>
    <div>
        <div class="uk-card uk-card-default uk-card-body tm-card-accent">
            <div class="uk-margin-bottom">
                <img src="storage/theme-flavor/dynamikplus.jpg" alt="DYNAMIK PLUS" class="uk-width-1-1" style="border-radius: 4px;" onerror="this.style.display='none'">
            </div>
            <h3 class="uk-card-title">DYNAMIK PLUS</h3>
            <p>Premium Trainings-Equipment und Supplements f&uuml;r maximale Performance. Von Athleten f&uuml;r Athleten entwickelt.</p>
            <p>
                <a class="uk-button uk-button-default" href="#" target="_blank" rel="noopener">Zum Shop</a>
            </p>
        </div>
    </div>
</div>
HTML;
}

function contactContent(): string
{
    return <<<'HTML'
<div class="uk-text-center uk-margin-large-bottom">
    <p class="tm-section-label">KONTAKT</p>
    <h2 class="uk-heading-small">LASS UNS DEIN RESET STARTEN.</h2>
</div>

<form class="uk-form-stacked uk-width-2-3@m uk-align-center" action="#" method="post">
    <div class="uk-margin">
        <label class="uk-form-label" for="contact-name">Name</label>
        <div class="uk-form-controls">
            <input class="uk-input" id="contact-name" type="text" placeholder="Dein Name" required>
        </div>
    </div>
    <div class="uk-margin">
        <label class="uk-form-label" for="contact-email">E-Mail</label>
        <div class="uk-form-controls">
            <input class="uk-input" id="contact-email" type="email" placeholder="Deine E-Mail Adresse" required>
        </div>
    </div>
    <div class="uk-margin">
        <label class="uk-form-label" for="contact-message">Nachricht</label>
        <div class="uk-form-controls">
            <textarea class="uk-textarea" id="contact-message" rows="6" placeholder="Deine Nachricht..." required></textarea>
        </div>
    </div>
    <div class="uk-margin uk-text-center">
        <button class="uk-button uk-button-primary uk-button-large" type="submit">Nachricht senden</button>
    </div>
</form>
HTML;
}

function footerContent(int $impressumNodeId, int $datenschutzNodeId): string
{
    return <<<HTML
<div class="uk-text-center">
    <p class="uk-h4 uk-margin-remove"><strong>Robin Trummer</strong></p>
    <ul class="uk-subnav uk-subnav-divider uk-flex-center uk-margin-small-top">
        <li><a href="/home#tm-top-b" uk-scroll>Services</a></li>
        <li><a href="/home#tm-top-c" uk-scroll>&Uuml;ber mich</a></li>
        <li><a href="/home#tm-bottom-c" uk-scroll>Kontakt</a></li>
        <li><a href="/impressum">Impressum</a></li>
        <li><a href="/datenschutz">Datenschutz</a></li>
    </ul>
    <ul class="uk-iconnav uk-flex-center uk-margin-small-top">
        <li><a href="#" uk-icon="instagram"></a></li>
        <li><a href="#" uk-icon="youtube"></a></li>
        <li><a href="#" uk-icon="tiktok"></a></li>
    </ul>
    <p class="uk-text-small uk-text-muted uk-margin-top">&copy; 2026 TTAGS</p>
</div>
HTML;
}

function buildThemeConfig(array $widgetIds, int $homeNodeId, int $impressumNodeId, int $datenschutzNodeId): array
{
    $posDefaults = function (string $style = 'uk-section-secondary', string $size = 'uk-section-large'): array {
        return [
            'style'                           => $style,
            'size'                            => $size,
            'image'                           => '',
            'image_position'                  => '',
            'effect'                          => '',
            'width'                           => '',
            'height'                          => '',
            'vertical_align'                  => 'middle',
            'padding_remove_top'              => false,
            'padding_remove_bottom'           => false,
            'preserve_color'                  => false,
            'overlap'                         => false,
            'header_transparent'              => false,
            'header_preserve_color'           => false,
            'header_transparent_noplaceholder' => false,
        ];
    };

    $widgetConfig = function (bool $titleHide, bool $alignment): array {
        return [
            'title_hide'  => $titleHide,
            'title_size'  => 'uk-h3',
            'alignment'   => $alignment,
            'html_class'  => '',
            'panel'       => '',
        ];
    };

    $widgetSettings = [];
    foreach ($widgetIds as $i => $id) {
        $isTitleHidden = true;
        $isCentered    = in_array($i, [0, 1, 2, 6, 8], true);
        $widgetSettings[(string) $id] = $widgetConfig($isTitleHidden, $isCentered);
    }

    $subpagePositions = [
        'hero' => array_merge($posDefaults('uk-section-secondary', ''), [
            'height' => '',
        ]),
        'main' => $posDefaults('uk-section-default', 'uk-section-large'),
    ];

    return [
        '_menus' => [
            'main'      => 'main',
            'offcanvas' => 'main',
        ],
        '_positions' => [
            'hero'     => [$widgetIds[0], $widgetIds[1]],
            'top-a'    => [$widgetIds[2]],
            'top-b'    => [$widgetIds[3]],
            'top-c'    => [$widgetIds[4]],
            'bottom-a' => [$widgetIds[5]],
            'bottom-b' => [$widgetIds[6]],
            'bottom-c' => [$widgetIds[7]],
            'footer'   => [$widgetIds[8]],
            'navbar'   => [],
            'header'   => [],
            'sidebar'  => [],
        ],
        '_widgets' => $widgetSettings,
        '_nodes' => [
            (string) $homeNodeId => [
                'title_hide'    => true,
                'title_large'   => false,
                'alignment'     => true,
                'html_class'    => '',
                'content_hide'  => true,
                'sidebar_first' => false,
                'positions' => [
                    'hero' => array_merge($posDefaults('uk-section-secondary', ''), [
                        'height'                           => 'full',
                        'header_transparent'              => true,
                        'header_transparent_noplaceholder' => true,
                    ]),
                    'top-a'    => $posDefaults('uk-section-secondary', 'uk-section-large'),
                    'top-b'    => $posDefaults('uk-section-secondary', 'uk-section-large'),
                    'top-c'    => $posDefaults('uk-section-default', 'uk-section-large'),
                    'main'     => $posDefaults('uk-section-default', 'uk-section-large'),
                    'bottom-a' => $posDefaults('uk-section-secondary', 'uk-section-large'),
                    'bottom-b' => $posDefaults('uk-section-secondary', ''),
                    'bottom-c' => $posDefaults('uk-section-secondary', 'uk-section-large'),
                ],
            ],
            (string) $impressumNodeId => [
                'title_hide'    => false,
                'title_large'   => false,
                'alignment'     => false,
                'html_class'    => '',
                'content_hide'  => false,
                'sidebar_first' => false,
                'positions'     => $subpagePositions,
            ],
            (string) $datenschutzNodeId => [
                'title_hide'    => false,
                'title_large'   => false,
                'alignment'     => false,
                'html_class'    => '',
                'content_hide'  => false,
                'sidebar_first' => false,
                'positions'     => $subpagePositions,
            ],
        ],
    ];
}

function impressumContent(): string
{
    return <<<'HTML'
<!-- PLACEHOLDER: Replace with real legal data -->
<h2>Impressum</h2>

<h3>Angaben gem&auml;&szlig; &sect; 5 TMG</h3>
<p>
    Robin Trummer / TTAGS<br>
    Musterstra&szlig;e 1<br>
    20095 Hamburg
</p>

<h3>Kontakt</h3>
<p>
    Telefon: +49 (0) 40 123 456 78<br>
    E-Mail: info@example.com
</p>

<h3>Umsatzsteuer-ID</h3>
<p>
    Umsatzsteuer-Identifikationsnummer gem&auml;&szlig; &sect; 27 a Umsatzsteuergesetz:<br>
    <!-- PLACEHOLDER: Insert USt-ID -->
    DE XXX XXX XXX
</p>

<h3>Verantwortlich f&uuml;r den Inhalt nach &sect; 55 Abs. 2 RSt</h3>
<p>
    Robin Trummer<br>
    Musterstra&szlig;e 1<br>
    20095 Hamburg
</p>

<h3>Haftungsausschluss</h3>

<h4>Haftung f&uuml;r Inhalte</h4>
<p>Als Diensteanbieter sind wir gem&auml;&szlig; &sect; 7 Abs.1 TMG f&uuml;r eigene Inhalte auf diesen Seiten nach den allgemeinen Gesetzen verantwortlich. Nach &sect;&sect; 8 bis 10 TMG sind wir als Diensteanbieter jedoch nicht verpflichtet, &uuml;bermittelte oder gespeicherte fremde Informationen zu &uuml;berwachen oder nach Umst&auml;nden zu forschen, die auf eine rechtswidrige T&auml;tigkeit hinweisen.</p>

<h4>Haftung f&uuml;r Links</h4>
<p>Unser Angebot enth&auml;lt Links zu externen Websites Dritter, auf deren Inhalte wir keinen Einfluss haben. Deshalb k&ouml;nnen wir f&uuml;r diese fremden Inhalte auch keine Gew&auml;hr &uuml;bernehmen. F&uuml;r die Inhalte der verlinkten Seiten ist stets der jeweilige Anbieter oder Betreiber der Seiten verantwortlich.</p>

<h4>Urheberrecht</h4>
<p>Die durch die Seitenbetreiber erstellten Inhalte und Werke auf diesen Seiten unterliegen dem deutschen Urheberrecht. Die Vervielf&auml;ltigung, Bearbeitung, Verbreitung und jede Art der Verwertung au&szlig;erhalb der Grenzen des Urheberrechtes bed&uuml;rfen der schriftlichen Zustimmung des jeweiligen Autors bzw. Erstellers.</p>
HTML;
}

function datenschutzContent(): string
{
    return <<<'HTML'
<!-- PLACEHOLDER: Replace with real privacy policy data -->
<h2>Datenschutzerkl&auml;rung</h2>

<h3>1. Datenschutz auf einen Blick</h3>

<h4>Allgemeine Hinweise</h4>
<p>Die folgenden Hinweise geben einen einfachen &Uuml;berblick dar&uuml;ber, was mit Ihren personenbezogenen Daten passiert, wenn Sie diese Website besuchen. Personenbezogene Daten sind alle Daten, mit denen Sie pers&ouml;nlich identifiziert werden k&ouml;nnen.</p>

<h4>Datenerfassung auf dieser Website</h4>
<p><strong>Wer ist verantwortlich f&uuml;r die Datenerfassung auf dieser Website?</strong><br>
Die Datenverarbeitung auf dieser Website erfolgt durch den Websitebetreiber. Dessen Kontaktdaten k&ouml;nnen Sie dem Abschnitt &bdquo;Hinweis zur Verantwortlichen Stelle&ldquo; in dieser Datenschutzerkl&auml;rung entnehmen.</p>

<h3>2. Hosting</h3>
<p>Wir hosten die Inhalte unserer Website bei folgendem Anbieter:</p>
<p><!-- PLACEHOLDER: Insert hosting provider details --></p>

<h3>3. Allgemeine Hinweise und Pflichtinformationen</h3>

<h4>Datenschutz</h4>
<p>Die Betreiber dieser Seiten nehmen den Schutz Ihrer pers&ouml;nlichen Daten sehr ernst. Wir behandeln Ihre personenbezogenen Daten vertraulich und entsprechend den gesetzlichen Datenschutzvorschriften sowie dieser Datenschutzerkl&auml;rung.</p>

<h4>Hinweis zur verantwortlichen Stelle</h4>
<p>
    Robin Trummer / TTAGS<br>
    Musterstra&szlig;e 1<br>
    20095 Hamburg<br>
    E-Mail: info@example.com
</p>

<h3>4. Datenerfassung auf dieser Website</h3>

<h4>Kontaktformular</h4>
<p>Wenn Sie uns per Kontaktformular Anfragen zukommen lassen, werden Ihre Angaben aus dem Anfrageformular inklusive der von Ihnen dort angegebenen Kontaktdaten zwecks Bearbeitung der Anfrage und f&uuml;r den Fall von Anschlussfragen bei uns gespeichert. Diese Daten geben wir nicht ohne Ihre Einwilligung weiter.</p>

<h3>5. Ihre Rechte</h3>
<p>Sie haben jederzeit das Recht, unentgeltlich Auskunft &uuml;ber Herkunft, Empf&auml;nger und Zweck Ihrer gespeicherten personenbezogenen Daten zu erhalten. Sie haben au&szlig;erdem ein Recht, die Berichtigung oder L&ouml;schung dieser Daten zu verlangen.</p>

<p><em>Stand: M&auml;rz 2026</em></p>
HTML;
}
