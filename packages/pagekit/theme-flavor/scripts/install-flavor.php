<?php
/**
 * Theme Flavor — Demo Content Pack
 *
 * Populates Pagekit with Robin Trummer Personal Trainer content.
 * Called by the Pagekit installer when "Robin Trummer / Flavor" is selected,
 * or via the CLI wrapper: php packages/pagekit/theme-flavor/scripts/insert-data.php
 *
 * Expects $app (Pagekit\Application) and $db ($app->get('db')) in scope.
 *
 * All IDs are tracked via lastInsertId() — safe for MySQL auto-increment offsets.
 */

$db = $app->get('db');
$config = $app->get('config');

// =========================================================================
// Pages
// =========================================================================
$db->insert('@system_page', [
    'title'   => 'Home',
    'content' => '<p>Homepage</p>',
    'data'    => '{"title":null}',
]);
$pageHome = (int) $db->lastInsertId();

$db->insert('@system_page', [
    'title'   => 'Impressum',
    'content' => implode("\n", [
        '<!-- PLACEHOLDER: Replace with real legal data -->',
        '<h2>Impressum</h2>',
        '',
        '<h3>Angaben gem&auml;&szlig; &sect; 5 TMG</h3>',
        '<p>Robin Trummer / TTAGS<br>Musterstra&szlig;e 1<br>20095 Hamburg</p>',
        '',
        '<h3>Kontakt</h3>',
        '<p>Telefon: +49 (0) 40 123 456 78<br>E-Mail: info@example.com</p>',
        '',
        '<h3>Umsatzsteuer-ID</h3>',
        '<p>Umsatzsteuer-Identifikationsnummer gem&auml;&szlig; &sect; 27 a Umsatzsteuergesetz:<br>',
        '<!-- PLACEHOLDER: Insert USt-ID -->',
        'DE XXX XXX XXX</p>',
        '',
        '<h3>Verantwortlich f&uuml;r den Inhalt nach &sect; 55 Abs. 2 RStV</h3>',
        '<p>Robin Trummer<br>Musterstra&szlig;e 1<br>20095 Hamburg</p>',
        '',
        '<h3>Haftungsausschluss</h3>',
        '<h4>Haftung f&uuml;r Inhalte</h4>',
        '<p>Als Diensteanbieter sind wir gem&auml;&szlig; &sect; 7 Abs.1 TMG f&uuml;r eigene Inhalte auf diesen Seiten nach den allgemeinen Gesetzen verantwortlich. Nach &sect;&sect; 8 bis 10 TMG sind wir als Diensteanbieter jedoch nicht verpflichtet, &uuml;bermittelte oder gespeicherte fremde Informationen zu &uuml;berwachen.</p>',
        '',
        '<h4>Haftung f&uuml;r Links</h4>',
        '<p>Unser Angebot enth&auml;lt Links zu externen Websites Dritter, auf deren Inhalte wir keinen Einfluss haben. Deshalb k&ouml;nnen wir f&uuml;r diese fremden Inhalte auch keine Gew&auml;hr &uuml;bernehmen.</p>',
        '',
        '<h4>Urheberrecht</h4>',
        '<p>Die durch die Seitenbetreiber erstellten Inhalte und Werke auf diesen Seiten unterliegen dem deutschen Urheberrecht.</p>',
    ]),
    'data'    => '{"title":null}',
]);
$pageImpressum = (int) $db->lastInsertId();

$db->insert('@system_page', [
    'title'   => 'Datenschutz',
    'content' => implode("\n", [
        '<!-- PLACEHOLDER: Replace with real privacy policy data -->',
        '<h2>Datenschutzerkl&auml;rung</h2>',
        '',
        '<h3>1. Datenschutz auf einen Blick</h3>',
        '<h4>Allgemeine Hinweise</h4>',
        '<p>Die folgenden Hinweise geben einen einfachen &Uuml;berblick dar&uuml;ber, was mit Ihren personenbezogenen Daten passiert, wenn Sie diese Website besuchen.</p>',
        '',
        '<h3>2. Hosting</h3>',
        '<p>Wir hosten die Inhalte unserer Website bei folgendem Anbieter:</p>',
        '<p><!-- PLACEHOLDER: Insert hosting provider details --></p>',
        '',
        '<h3>3. Allgemeine Hinweise und Pflichtinformationen</h3>',
        '<h4>Datenschutz</h4>',
        '<p>Die Betreiber dieser Seiten nehmen den Schutz Ihrer pers&ouml;nlichen Daten sehr ernst.</p>',
        '',
        '<h4>Hinweis zur verantwortlichen Stelle</h4>',
        '<p>Robin Trummer / TTAGS<br>Musterstra&szlig;e 1<br>20095 Hamburg<br>E-Mail: info@example.com</p>',
        '',
        '<h3>4. Datenerfassung auf dieser Website</h3>',
        '<h4>Kontaktformular</h4>',
        '<p>Wenn Sie uns per Kontaktformular Anfragen zukommen lassen, werden Ihre Angaben aus dem Anfrageformular inklusive der von Ihnen dort angegebenen Kontaktdaten zwecks Bearbeitung der Anfrage bei uns gespeichert.</p>',
        '',
        '<h3>5. Ihre Rechte</h3>',
        '<p>Sie haben jederzeit das Recht, unentgeltlich Auskunft &uuml;ber Herkunft, Empf&auml;nger und Zweck Ihrer gespeicherten personenbezogenen Daten zu erhalten.</p>',
        '',
        '<p><em>Stand: M&auml;rz 2026</em></p>',
    ]),
    'data'    => '{"title":null}',
]);
$pageDatenschutz = (int) $db->lastInsertId();

// =========================================================================
// Nodes / Menu
// =========================================================================
$db->insert('@system_node', [
    'priority' => 1, 'status' => 1,
    'title' => 'Home', 'slug' => 'home', 'path' => '/home',
    'link' => '@page/' . $pageHome, 'type' => 'page', 'menu' => 'main',
    'data' => json_encode(['defaults' => ['id' => $pageHome]]),
]);
$nodeHome = (int) $db->lastInsertId();

$db->insert('@system_node', [
    'priority' => 2, 'status' => 1,
    'title' => 'Services', 'slug' => 'services', 'path' => '/services',
    'link' => '/#tm-top-b', 'type' => 'link', 'menu' => 'main',
    'data' => '{}',
]);

$db->insert('@system_node', [
    'priority' => 3, 'status' => 1,
    'title' => "\xC3\x9Cber mich", 'slug' => 'ueber-mich', 'path' => '/ueber-mich',
    'link' => '/#tm-top-c', 'type' => 'link', 'menu' => 'main',
    'data' => '{}',
]);

$db->insert('@system_node', [
    'priority' => 4, 'status' => 1,
    'title' => 'Kontakt', 'slug' => 'kontakt', 'path' => '/kontakt',
    'link' => '/#tm-bottom-c', 'type' => 'link', 'menu' => 'main',
    'data' => '{}',
]);

$db->insert('@system_node', [
    'priority' => 5, 'status' => 1,
    'title' => 'Impressum', 'slug' => 'impressum', 'path' => '/impressum',
    'link' => '@page/' . $pageImpressum, 'type' => 'page', 'menu' => '',
    'data' => json_encode(['defaults' => ['id' => $pageImpressum]]),
]);
$nodeImpressum = (int) $db->lastInsertId();

$db->insert('@system_node', [
    'priority' => 6, 'status' => 1,
    'title' => 'Datenschutz', 'slug' => 'datenschutz', 'path' => '/datenschutz',
    'link' => '@page/' . $pageDatenschutz, 'type' => 'page', 'menu' => '',
    'data' => json_encode(['defaults' => ['id' => $pageDatenschutz]]),
]);
$nodeDatenschutz = (int) $db->lastInsertId();

// =========================================================================
// Site Config — set frontpage to home node and logo
// =========================================================================
$config->set('system/site', $config('system/site')->merge([
    'frontpage' => $nodeHome,
    'view' => ['logo' => 'storage/theme-flavor/robin-profil.jpg'],
]));

// =========================================================================
// Widgets — Homepage (restricted to home node)
// =========================================================================
$widgetIds = [];

$db->insert('@system_widget', ['title' => 'Hero Content', 'type' => 'system/text', 'status' => 1, 'nodes' => (string) $nodeHome, 'data' => json_encode(['content' => '<p class="tm-section-label">RESET &amp; RISE &ndash; Ma&szlig;geschneidertes 1-zu-1 Personal Training in Hamburg.</p>
<h1 class="uk-heading-medium uk-margin-remove-top">DEIN WEG ZU MEHR KRAFT, FOKUS UND REGENERATION.</h1>
<p class="uk-text-lead uk-text-muted">Starte jetzt deine Transformation.</p>
<p class="uk-margin-medium-top">
    <a class="uk-button uk-button-primary uk-button-large" href="/#tm-bottom-c" uk-scroll>Erstgespr&auml;ch sichern</a>
</p>
<div class="tm-hero-badges">
    <div class="tm-hero-badge">Ganzheitlicher Ansatz</div>
    <div class="tm-hero-badge">Individuelle Betreuung</div>
    <div class="tm-hero-badge">Technik- &amp; Boxcoaching</div>
</div>'])]);
$widgetIds[] = (int) $db->lastInsertId();

$db->insert('@system_widget', ['title' => 'Hero Profile', 'type' => 'system/text', 'status' => 1, 'nodes' => (string) $nodeHome, 'data' => json_encode(['content' => '<div class="uk-flex uk-flex-center uk-flex-middle" style="min-height: 100%;">
    <img data-src="storage/theme-flavor/robin-profil.jpg" alt="Robin Trummer" class="tm-hero-image" uk-img>
</div>'])]);
$widgetIds[] = (int) $db->lastInsertId();

$db->insert('@system_widget', ['title' => 'Quote 1', 'type' => 'system/text', 'status' => 1, 'nodes' => (string) $nodeHome, 'data' => json_encode(['content' => '<div class="tm-quote uk-text-center">Entwicklung beginnt mit einer Entscheidung. RESET &amp; RISE ist diese Entscheidung.</div>'])]);
$widgetIds[] = (int) $db->lastInsertId();

$db->insert('@system_widget', ['title' => 'Services', 'type' => 'system/text', 'status' => 1, 'nodes' => (string) $nodeHome, 'data' => json_encode(['content' => '<div class="uk-text-center uk-margin-large-bottom">
    <p class="tm-section-label">RESET &amp; RISE 12-WOCHEN-PROGRAMM</p>
    <h2 class="uk-heading-small">DEINE 3 MONATE TRANSFORMATION.</h2>
</div>
<div class="uk-grid-large uk-child-width-1-2@m" uk-grid>
    <div>
        <div class="uk-card uk-card-default uk-card-body tm-card-accent">
            <h3 class="uk-card-title">12-WOCHEN FOUNDATION</h3>
            <p class="uk-text-muted">Dein Einstieg in deine Gesundheit und Performance-Training.</p>
            <ul class="tm-feature-list">
                <li>1x w&ouml;chentliches 1 zu 1 Personal-Training</li>
                <li>Kraft-, Technik- &amp; Funktionaltraining</li>
                <li>WhatsApp-Support</li>
                <li>Fokus: Mobility, Core, Nacken- &amp; R&uuml;ckenbalance</li>
                <li>Dein Starterpaket (Equipment &amp; Supplements)</li>
            </ul>
            <p><span class="tm-price">1.900 &euro;</span><span class="tm-price-old">2.500 &euro;</span></p>
            <p class="uk-margin-medium-top"><a class="uk-button uk-button-primary" href="/#tm-bottom-c" uk-scroll>Jetzt starten</a></p>
        </div>
    </div>
    <div>
        <div class="uk-card uk-card-default uk-card-body tm-card-accent">
            <h3 class="uk-card-title">ERN&Auml;HRUNGSMODUL (6 Einheiten)</h3>
            <p class="uk-text-muted">Die Begleitung f&uuml;r deine gesamten 12 Wochen.</p>
            <ul class="tm-feature-list">
                <li>Ern&auml;hrungsanalyse &amp; aktuelles Essverhalten</li>
                <li>Sofort umsetzbare Optimierungen</li>
                <li>Begleitetes Einkaufen</li>
                <li>Monatschecks &amp; Abschlussanalyse</li>
                <li>Kein Tracking, kein App-Zwang</li>
            </ul>
            <p><span class="tm-price">600 &euro;</span><span class="tm-price-old">900 &euro;</span></p>
            <p class="uk-margin-medium-top"><a class="uk-button uk-button-primary" href="/#tm-bottom-c" uk-scroll>Jetzt buchen</a></p>
        </div>
    </div>
</div>'])]);
$widgetIds[] = (int) $db->lastInsertId();

$db->insert('@system_widget', ['title' => 'About', 'type' => 'system/text', 'status' => 1, 'nodes' => (string) $nodeHome, 'data' => json_encode(['content' => '<div class="uk-text-center uk-margin-large-bottom">
    <p class="tm-section-label">&Uuml;BER MICH</p>
    <h2 class="uk-heading-small">ROBIN TRUMMER &ndash; DEIN COACH F&Uuml;R GESUNDHEIT &amp; PERFORMANCE</h2>
</div>
<div class="uk-grid-large uk-child-width-1-2@m uk-flex-middle" uk-grid>
    <div>
        <img data-src="storage/theme-flavor/robin-profil.jpg" alt="Robin Trummer" class="uk-width-1-1" style="border-radius: 4px;" uk-img>
    </div>
    <div>
        <p class="uk-text-lead">Verstehen beginnt mit Erleben.</p>
        <p>Ich begleite dich nicht nur physisch, sondern auch mental. Kampfsport st&auml;rkt den Fokus und das Mindset &ndash; eine St&auml;rke, die sich im gesamten Alltag zeigt.</p>
        <p>Drei Monate konsequentes Training k&ouml;nnen mehr ver&auml;ndern, als du erwartest. Lass uns gemeinsam den Fokus setzen.</p>
        <p class="uk-margin-medium-top"><a class="uk-button uk-button-default" href="/#tm-bottom-c" uk-scroll>Meinen Ansatz kennenlernen</a></p>
    </div>
</div>'])]);
$widgetIds[] = (int) $db->lastInsertId();

$db->insert('@system_widget', ['title' => 'Recommendations', 'type' => 'system/text', 'status' => 1, 'nodes' => (string) $nodeHome, 'data' => json_encode(['content' => '<div class="uk-text-center uk-margin-large-bottom">
    <p class="tm-section-label">MEINE EMPFEHLUNGEN</p>
    <h2 class="uk-heading-small">QUALIT&Auml;T F&Uuml;R DEINE PERFORMANCE.</h2>
</div>
<div class="uk-grid-large uk-child-width-1-2@m" uk-grid>
    <div>
        <div class="uk-card uk-card-default uk-card-body tm-card-accent uk-text-center">
            <h3 class="uk-card-title">RINGANA FRESH</h3>
            <p class="uk-text-muted">Nat&uuml;rliche Frische und Reinheit f&uuml;r deine Gesundheit.</p>
            <div class="uk-margin"><img data-src="storage/theme-flavor/ringana-partner.png" alt="Ringana Partner" style="max-height: 120px; opacity: 0.9;" uk-img></div>
            <p><a class="uk-button uk-button-default" href="https://robintrummer.ringana.com" target="_blank" rel="noopener">Zum Shop</a></p>
        </div>
    </div>
    <div>
        <div class="uk-card uk-card-default uk-card-body tm-card-accent uk-text-center">
            <h3 class="uk-card-title">DYNAMIK PLUS</h3>
            <p class="uk-text-muted">Performance-Supplements f&uuml;r Kraft und Regeneration.</p>
            <div class="uk-margin"><img data-src="storage/theme-flavor/dynamikplus.png" alt="Dynamik Plus" style="max-height: 120px;" uk-img></div>
            <p><a class="uk-button uk-button-default" href="https://dynamikplus.de/discount/FOKUS10" target="_blank" rel="noopener">Zum Shop</a></p>
        </div>
    </div>
</div>'])]);
$widgetIds[] = (int) $db->lastInsertId();

$db->insert('@system_widget', ['title' => 'Quote 2', 'type' => 'system/text', 'status' => 1, 'nodes' => (string) $nodeHome, 'data' => json_encode(['content' => '<div class="tm-quote uk-text-center">Der Abstand zwischen deinen Tr&auml;umen und der Realit&auml;t nennt sich Aktion. Verstehen beginnt mit Erleben.</div>'])]);
$widgetIds[] = (int) $db->lastInsertId();

$db->insert('@system_widget', ['title' => 'Contact', 'type' => 'system/text', 'status' => 1, 'nodes' => (string) $nodeHome, 'data' => json_encode(['content' => '<div class="uk-text-center uk-margin-large-bottom">
    <p class="tm-section-label">KONTAKT</p>
    <h2 class="uk-heading-small">LASS UNS DEIN RESET STARTEN.</h2>
</div>
<form class="uk-form-stacked uk-width-2-3@m uk-align-center" action="#" method="post">
    <div class="uk-margin">
        <label class="uk-form-label" for="contact-name">Name</label>
        <div class="uk-form-controls"><input class="uk-input" id="contact-name" type="text" placeholder="Dein Name" required></div>
    </div>
    <div class="uk-margin">
        <label class="uk-form-label" for="contact-email">E-Mail</label>
        <div class="uk-form-controls"><input class="uk-input" id="contact-email" type="email" placeholder="Deine E-Mail Adresse" required></div>
    </div>
    <div class="uk-margin">
        <label class="uk-form-label" for="contact-message">Nachricht</label>
        <div class="uk-form-controls"><textarea class="uk-textarea" id="contact-message" rows="6" placeholder="Deine Nachricht..." required></textarea></div>
    </div>
    <div class="uk-margin uk-text-center"><button class="uk-button uk-button-primary uk-button-large" type="submit">Nachricht senden</button></div>
</form>'])]);
$widgetIds[] = (int) $db->lastInsertId();

// Widget 9: Footer (global, all pages — nodes empty)
$db->insert('@system_widget', ['title' => 'Footer', 'type' => 'system/text', 'status' => 1, 'data' => json_encode(['content' => '<div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap" uk-grid>
    <div class="uk-width-auto@m">
        <div class="uk-flex uk-flex-middle">
            <img data-src="storage/theme-flavor/robin-logo.png" alt="Robin Trummer" class="uk-border-circle" width="36" height="36" uk-img>
            <span class="uk-margin-small-left uk-text-bold">Robin Trummer</span>
        </div>
    </div>
    <div class="uk-width-expand@m uk-text-center">
        <ul class="uk-subnav uk-subnav-divider uk-flex-center uk-margin-remove">
            <li><a href="/#tm-top-b">Services</a></li>
            <li><a href="/#tm-top-c">&Uuml;ber mich</a></li>
            <li><a href="/#tm-bottom-c">Kontakt</a></li>
        </ul>
    </div>
    <div class="uk-width-auto@m uk-text-right">
        <p class="uk-text-small uk-text-muted uk-margin-remove">&copy; 2026 TTAGS</p>
    </div>
</div>
<div class="uk-text-center uk-margin-small-top">
    <ul class="uk-subnav uk-flex-center uk-margin-remove uk-text-small">
        <li><a href="/impressum" class="uk-text-muted">Impressum</a></li>
        <li><a href="/datenschutz" class="uk-text-muted">Datenschutz</a></li>
    </ul>
</div>'])]);
$widgetIds[] = (int) $db->lastInsertId();

// =========================================================================
// Theme Config (theme-flavor) — all IDs from lastInsertId()
// =========================================================================
$posDefaults = function (string $style = 'uk-section-secondary', string $size = 'uk-section-large'): array {
    return [
        'style' => $style, 'size' => $size,
        'image' => '', 'image_position' => '', 'effect' => '', 'width' => '', 'height' => '',
        'vertical_align' => 'middle',
        'padding_remove_top' => false, 'padding_remove_bottom' => false,
        'preserve_color' => false, 'overlap' => false,
        'header_transparent' => false, 'header_preserve_color' => false, 'header_transparent_noplaceholder' => false,
    ];
};

$widgetOpts = function (bool $centered): array {
    return ['title_hide' => true, 'title_size' => 'uk-h3', 'alignment' => $centered, 'html_class' => '', 'panel' => ''];
};

$themeConfig = [
    '_menus' => ['main' => 'main', 'offcanvas' => 'main'],
    '_positions' => [
        'hero'     => [$widgetIds[0], $widgetIds[1]],
        'top-a'    => [$widgetIds[2]],
        'top-b'    => [$widgetIds[3]],
        'top-c'    => [$widgetIds[4]],
        'bottom-a' => [$widgetIds[5]],
        'bottom-b' => [$widgetIds[6]],
        'bottom-c' => [$widgetIds[7]],
        'footer'   => [$widgetIds[8]],
        'navbar'   => [], 'header' => [], 'sidebar' => [],
    ],
    '_widgets' => [],
    '_nodes' => [],
];

$centeredWidgets = [0, 1, 2, 6, 8];
foreach ($widgetIds as $i => $wid) {
    $themeConfig['_widgets'][(string) $wid] = $widgetOpts(in_array($i, $centeredWidgets, true));
}

$themeConfig['_nodes'][(string) $nodeHome] = [
    'title_hide' => true, 'title_large' => false, 'alignment' => true,
    'html_class' => '', 'content_hide' => true, 'sidebar_first' => false,
    'positions' => [
        'hero'     => array_merge($posDefaults('uk-section-secondary', ''), ['height' => 'full', 'image' => 'storage/theme-flavor/hero-bg.jpg', 'effect' => '', 'header_transparent' => true, 'header_transparent_noplaceholder' => true]),
        'top-a'    => $posDefaults('uk-section-secondary', 'uk-section-large'),
        'top-b'    => array_merge($posDefaults('uk-section-secondary', 'uk-section-large'), ['image' => 'storage/theme-flavor/gym-sunny.jpg']),
        'top-c'    => $posDefaults('uk-section-default', 'uk-section-large'),
        'main'     => $posDefaults('uk-section-default', 'uk-section-large'),
        'bottom-a' => array_merge($posDefaults('uk-section-secondary', 'uk-section-large'), ['image' => 'storage/theme-flavor/gym-sunny.jpg']),
        'bottom-b' => $posDefaults('uk-section-secondary', ''),
        'bottom-c' => array_merge($posDefaults('uk-section-secondary', 'uk-section-large'), ['image' => 'storage/theme-flavor/hero-bg.jpg', 'effect' => 'fixed']),
    ],
];

$subpagePositions = [
    'hero' => array_merge($posDefaults('uk-section-secondary', ''), ['height' => '']),
    'main' => $posDefaults('uk-section-default', 'uk-section-large'),
];

$themeConfig['_nodes'][(string) $nodeImpressum] = [
    'title_hide' => false, 'title_large' => false, 'alignment' => false,
    'html_class' => '', 'content_hide' => false, 'sidebar_first' => false,
    'positions' => $subpagePositions,
];

$themeConfig['_nodes'][(string) $nodeDatenschutz] = [
    'title_hide' => false, 'title_large' => false, 'alignment' => false,
    'html_class' => '', 'content_hide' => false, 'sidebar_first' => false,
    'positions' => $subpagePositions,
];

$db->insert('@system_config', ['name' => 'theme-flavor', 'value' => json_encode($themeConfig)]);

// Create placeholder image directory
$storageDir = $app->get('path') . '/storage/theme-flavor';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0755, true);
}
