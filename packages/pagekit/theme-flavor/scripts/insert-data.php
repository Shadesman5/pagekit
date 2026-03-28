#!/usr/bin/env php
<?php
/**
 * Theme Flavor — CLI Content Installer
 *
 * Thin wrapper that bootstraps Pagekit and delegates to install-flavor.php.
 * Run: php packages/pagekit/theme-flavor/scripts/insert-data.php [--force]
 *
 * This is functionally identical to choosing "Robin Trummer / Flavor"
 * in the Pagekit installer's demo content dropdown.
 */

if (PHP_SAPI !== 'cli') {
    exit('This script must be run from the command line.');
}

$rootDir = realpath(__DIR__ . '/../../../../');

if (!$rootDir || !file_exists($rootDir . '/config.php')) {
    exit("Error: config.php not found. Run the Pagekit installer first.\n");
}

$force = in_array('--force', $argv, true);

echo "=== Theme Flavor: Content Installer ===\n\n";

// Minimal Pagekit bootstrap (without Symfony Console runner)
$path = $rootDir;
$configFile = $rootDir . '/config.php';
$config = [
    'path'          => $path,
    'path.packages' => $path . '/packages',
    'path.storage'  => $path . '/storage',
    'path.temp'     => $path . '/tmp/temp',
    'path.cache'    => $path . '/tmp/cache',
    'path.logs'     => $path . '/tmp/logs',
    'path.vendor'   => $path . '/app/vendor',
    'path.artifact' => $path . '/tmp/packages',
    'config.file'   => $configFile,
];

$loader = require $path . '/autoload.php';

$app = new Pagekit\Application($config);
$app->set('autoloader', $loader);

$app->get('module')->register([
    'packages/*/*/index.php',
    'app/modules/*/index.php',
    'app/installer/index.php',
    'app/system/index.php',
], $path);

$app->get('module')->addLoader(new Pagekit\Module\Loader\AutoLoader($app->get('autoloader')));
$app->get('module')->addLoader(new Pagekit\Module\Loader\ConfigLoader(require $path . '/app/system/config.php'));
$app->get('module')->addLoader(new Pagekit\Module\Loader\ConfigLoader(require $configFile));
$app->get('module')->load('system');

$db = $app->get('db');

// Safety check
$existingPages = (int) $db->fetchAssociative("SELECT COUNT(*) AS cnt FROM @system_page")['cnt'];
if ($existingPages > 0 && !$force) {
    echo "WARNING: Database already contains {$existingPages} page(s).\n";
    echo "Run with --force to truncate and replace all content.\n\n";
    exit(1);
}

if ($force && $existingPages > 0) {
    echo "[CLEAN] Truncating existing content... ";
    $db->executeQuery("DELETE FROM @system_widget");
    $db->executeQuery("DELETE FROM @system_node");
    $db->executeQuery("DELETE FROM @system_page");
    $db->executeQuery("DELETE FROM @system_config WHERE name = 'theme-flavor'");
    echo "OK\n";
}

echo "[INSERT] Running install-flavor.php... ";
require __DIR__ . '/install-flavor.php';
echo "OK\n";

echo "\n=== Installation Complete ===\n\n";
echo "Summary:\n";
echo "  Pages:   3 (Home, Impressum, Datenschutz)\n";
echo "  Nodes:   6 (Home, Services, Ueber mich, Kontakt, Impressum, Datenschutz)\n";
echo "  Widgets: 9 (Hero, Profile, Quote1, Services, About, Recommendations, Quote2, Contact, Footer)\n\n";
echo "Next steps:\n";
echo "  1. Activate theme-flavor in admin: Site > Settings > Theme\n";
echo "  2. Upload images to storage/theme-flavor/\n";
echo "  3. Set logo text in admin settings\n\n";
