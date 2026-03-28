<?php
/**
 * Theme Flavor — Demo Content Pack
 *
 * Populates Pagekit with Robin Trummer Personal Trainer content.
 * Called by the Pagekit installer when "Robin Trummer / Flavor" is selected,
 * or via the CLI wrapper: php packages/pagekit/theme-flavor/scripts/insert-data.php
 *
 * Expects $app (Pagekit\Application) and $db ($app->get('db')) in scope.
 */

$db = $app->get('db');
$config = $app->get('config');

// =========================================================================
// Site Config
// =========================================================================
$config->set('system/site', $config('system/site')->merge([
    'frontpage' => 1,
    'view' => ['logo' => ''],
]));

// =========================================================================
// Pages
// =========================================================================
$db->insert('@system_page', [
    'title'   => 'Home',
    'content' => '<p>Homepage</p>',
    'data'    => '{"title":null}',
]);

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

// =========================================================================
// Nodes / Menu
// =========================================================================
$db->insert('@system_node', [
    'priority' => 1, 'status' => 1,
    'title' => 'Home', 'slug' => 'home', 'path' => '/home',
    'link' => '@page/1', 'type' => 'page', 'menu' => 'main',
    'data' => '{"defaults":{"id":1}}',
]);

$db->insert('@system_node', [
    'priority' => 2, 'status' => 1,
    'title' => 'Services', 'slug' => 'services', 'path' => '/services',
    'link' => '/home#tm-top-b', 'type' => 'link', 'menu' => 'main',
    'data' => '{}',
]);

$db->insert('@system_node', [
    'priority' => 3, 'status' => 1,
    'title' => "\xC3\x9Cber mich", 'slug' => 'ueber-mich', 'path' => '/ueber-mich',
    'link' => '/home#tm-top-c', 'type' => 'link', 'menu' => 'main',
    'data' => '{}',
]);

$db->insert('@system_node', [
    'priority' => 4, 'status' => 1,
    'title' => 'Kontakt', 'slug' => 'kontakt', 'path' => '/kontakt',
    'link' => '/home#tm-bottom-c', 'type' => 'link', 'menu' => 'main',
    'data' => '{}',
]);

$db->insert('@system_node', [
    'priority' => 5, 'status' => 1,
    'title' => 'Impressum', 'slug' => 'impressum', 'path' => '/impressum',
    'link' => '@page/2', 'type' => 'page', 'menu' => '',
    'data' => '{"defaults":{"id":2}}',
]);

$db->insert('@system_node', [
    'priority' => 6, 'status' => 1,
    'title' => 'Datenschutz', 'slug' => 'datenschutz', 'path' => '/datenschutz',
    'link' => '@page/3', 'type' => 'page', 'menu' => '',
    'data' => '{"defaults":{"id":3}}',
]);

// =========================================================================
// Widgets — Homepage (node 1 only)
// =========================================================================

// Widget 1: Hero Content
$db->insert('@system_widget', ['title' => 'Hero Content', 'type' => 'system/text', 'status' => 1, 'nodes' => 1, 'data' => json_encode(['content' => '<div class="uk-text-center">
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
</div>'])]);

// Widget 2: Hero Profile Image
$db->insert('@system_widget', ['title' => 'Hero Profile', 'type' => 'system/text', 'status' => 1, 'nodes' => 1, 'data' => json_encode(['content' => '<div class="uk-text-center uk-margin-top">
    <img data-src="storage/theme-flavor/robin-profil.jpg" alt="Robin Trummer" class="uk-border-circle" width="120" height="120" uk-img>
    <p class="uk-text-small uk-text-muted uk-margin-small-top">Robin Trummer</p>
</div>'])]);

// Widget 3: Quote 1 (top-a)
$db->insert('@system_widget', ['title' => 'Quote 1', 'type' => 'system/text', 'status' => 1, 'nodes' => 1, 'data' => json_encode(['content' => '<div class="tm-quote uk-text-center">Entwicklung beginnt mit einer Entscheidung. RESET &amp; RISE ist diese Entscheidung.</div>'])]);

// Widget 4: Services / Pricing (top-b)
$db->insert('@system_widget', ['title' => 'Services', 'type' => 'system/text', 'status' => 1, 'nodes' => 1, 'data' => json_encode(['content' => '<div class="uk-text-center uk-margin-large-bottom">
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
            <p><span class="tm-price">1.900 &euro;</span><span class="tm-price-old">2.500 &euro;</span></p>
            <p class="uk-margin-medium-top"><a class="uk-button uk-button-primary" href="#tm-bottom-c" uk-scroll>Jetzt starten</a></p>
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
            <p><span class="tm-price">600 &euro;</span><span class="tm-price-old">900 &euro;</span></p>
            <p class="uk-margin-medium-top"><a class="uk-button uk-button-primary" href="#tm-bottom-c" uk-scroll>Jetzt buchen</a></p>
        </div>
    </div>
</div>'])]);

// Widget 5: About (top-c)
$db->insert('@system_widget', ['title' => 'About', 'type' => 'system/text', 'status' => 1, 'nodes' => 1, 'data' => json_encode(['content' => '<div class="uk-text-center uk-margin-large-bottom">
    <p class="tm-section-label">&Uuml;BER MICH</p>
    <h2 class="uk-heading-small">ROBIN TRUMMER &ndash; DEIN COACH F&Uuml;R GESUNDHEIT &amp; PERFORMANCE</h2>
</div>
<div class="uk-grid-large uk-child-width-1-2@m uk-flex-middle" uk-grid>
    <div>
        <img data-src="storage/theme-flavor/robin-profil.jpg" alt="Robin Trummer" class="uk-width-1-1" style="border-radius: 4px;" uk-img>
    </div>
    <div>
        <p class="uk-text-lead">Seit &uuml;ber 8 Jahren begleite ich Menschen auf ihrem Weg zu mehr Kraft, Gesundheit und mentaler St&auml;rke.</p>
        <p>Mein Ansatz verbindet funktionelles Training, Boxcoaching und ganzheitliche Regeneration. Ich glaube daran, dass echte Ver&auml;nderung mit einer bewussten Entscheidung beginnt &ndash; und mit konsequentem Handeln Realit&auml;t wird.</p>
        <p>Als zertifizierter Personal Trainer und Ern&auml;hrungsberater in Hamburg biete ich dir ein ma&szlig;geschneidertes 1-zu-1 Programm, das auf deine individuellen Ziele und Bed&uuml;rfnisse abgestimmt ist.</p>
        <p class="uk-margin-medium-top"><a class="uk-button uk-button-default" href="#tm-bottom-c" uk-scroll>Meinen Ansatz kennenlernen</a></p>
    </div>
</div>'])]);

// Widget 6: Recommendations (bottom-a)
$db->insert('@system_widget', ['title' => 'Recommendations', 'type' => 'system/text', 'status' => 1, 'nodes' => 1, 'data' => json_encode(['content' => '<div class="uk-text-center uk-margin-large-bottom">
    <p class="tm-section-label">MEINE EMPFEHLUNGEN</p>
    <h2 class="uk-heading-small">QUALIT&Auml;T F&Uuml;R DEINE PERFORMANCE.</h2>
</div>
<div class="uk-grid-large uk-child-width-1-2@m" uk-grid>
    <div>
        <div class="uk-card uk-card-default uk-card-body tm-card-accent">
            <div class="uk-margin-bottom"><img data-src="storage/theme-flavor/ringana.jpg" alt="RINGANA FRESH" class="uk-width-1-1" style="border-radius: 4px;" uk-img></div>
            <h3 class="uk-card-title">RINGANA FRESH</h3>
            <p>Frische, vegane Nahrungserg&auml;nzung und Naturkosmetik f&uuml;r deinen aktiven Lifestyle. Nachhaltig, ethisch und wirkungsvoll.</p>
            <p><a class="uk-button uk-button-default" href="#" target="_blank" rel="noopener">Zum Shop</a></p>
        </div>
    </div>
    <div>
        <div class="uk-card uk-card-default uk-card-body tm-card-accent">
            <div class="uk-margin-bottom"><img data-src="storage/theme-flavor/dynamikplus.jpg" alt="DYNAMIK PLUS" class="uk-width-1-1" style="border-radius: 4px;" uk-img></div>
            <h3 class="uk-card-title">DYNAMIK PLUS</h3>
            <p>Premium Trainings-Equipment und Supplements f&uuml;r maximale Performance. Von Athleten f&uuml;r Athleten entwickelt.</p>
            <p><a class="uk-button uk-button-default" href="#" target="_blank" rel="noopener">Zum Shop</a></p>
        </div>
    </div>
</div>'])]);

// Widget 7: Quote 2 (bottom-b)
$db->insert('@system_widget', ['title' => 'Quote 2', 'type' => 'system/text', 'status' => 1, 'nodes' => 1, 'data' => json_encode(['content' => '<div class="tm-quote uk-text-center">Der Abstand zwischen deinen Tr&auml;umen und der Realit&auml;t nennt sich Aktion. Verstehen beginnt mit Erleben.</div>'])]);

// Widget 8: Contact (bottom-c)
$db->insert('@system_widget', ['title' => 'Contact', 'type' => 'system/text', 'status' => 1, 'nodes' => 1, 'data' => json_encode(['content' => '<div class="uk-text-center uk-margin-large-bottom">
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

// =========================================================================
// Widgets — Global (all pages)
// =========================================================================

// Widget 9: Footer
$db->insert('@system_widget', ['title' => 'Footer', 'type' => 'system/text', 'status' => 1, 'data' => json_encode(['content' => '<div class="uk-text-center">
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
</div>'])]);

// =========================================================================
// Theme Config (theme-flavor)
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
        'hero' => [1, 2], 'top-a' => [3], 'top-b' => [4], 'top-c' => [5],
        'bottom-a' => [6], 'bottom-b' => [7], 'bottom-c' => [8], 'footer' => [9],
        'navbar' => [], 'header' => [], 'sidebar' => [],
    ],
    '_widgets' => [
        '1' => $widgetOpts(true),  '2' => $widgetOpts(true),  '3' => $widgetOpts(true),
        '4' => $widgetOpts(false), '5' => $widgetOpts(false), '6' => $widgetOpts(false),
        '7' => $widgetOpts(true),  '8' => $widgetOpts(false), '9' => $widgetOpts(true),
    ],
    '_nodes' => [
        '1' => [
            'title_hide' => true, 'title_large' => false, 'alignment' => true,
            'html_class' => '', 'content_hide' => true, 'sidebar_first' => false,
            'positions' => [
                'hero'     => array_merge($posDefaults('uk-section-secondary', ''), ['height' => 'full', 'header_transparent' => true, 'header_transparent_noplaceholder' => true]),
                'top-a'    => $posDefaults('uk-section-secondary', 'uk-section-large'),
                'top-b'    => $posDefaults('uk-section-secondary', 'uk-section-large'),
                'top-c'    => $posDefaults('uk-section-default', 'uk-section-large'),
                'main'     => $posDefaults('uk-section-default', 'uk-section-large'),
                'bottom-a' => $posDefaults('uk-section-secondary', 'uk-section-large'),
                'bottom-b' => $posDefaults('uk-section-secondary', ''),
                'bottom-c' => $posDefaults('uk-section-secondary', 'uk-section-large'),
            ],
        ],
        '5' => [
            'title_hide' => false, 'title_large' => false, 'alignment' => false,
            'html_class' => '', 'content_hide' => false, 'sidebar_first' => false,
            'positions' => [
                'hero' => array_merge($posDefaults('uk-section-secondary', ''), ['height' => '']),
                'main' => $posDefaults('uk-section-default', 'uk-section-large'),
            ],
        ],
        '6' => [
            'title_hide' => false, 'title_large' => false, 'alignment' => false,
            'html_class' => '', 'content_hide' => false, 'sidebar_first' => false,
            'positions' => [
                'hero' => array_merge($posDefaults('uk-section-secondary', ''), ['height' => '']),
                'main' => $posDefaults('uk-section-default', 'uk-section-large'),
            ],
        ],
    ],
];

$db->insert('@system_config', ['name' => 'theme-flavor', 'value' => json_encode($themeConfig)]);

// Create placeholder image directory
$storageDir = $app->get('path') . '/storage/theme-flavor';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0755, true);
}
