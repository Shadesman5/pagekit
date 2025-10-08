<?php

require 'autoload.php';

use Pagekit\Application;

// Bootstrap Pagekit
$app = Application::getInstance();
$app['config']->load(__DIR__ . '/config.php');
$app->boot();

// Enable error display
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Testing Menu API</h1>\n";
echo "<pre>\n";

try {
    echo "1. Loading Menu model class...\n";
    $class = 'Pagekit\\Menucards\\Model\\Menu';
    if (!class_exists($class)) {
        die("ERROR: Class $class not found!\n");
    }
    echo "✅ Class exists\n\n";
    
    echo "2. Testing Menu::findAll()...\n";
    $menus = \Pagekit\Menucards\Model\Menu::findAll();
    echo "✅ SUCCESS! Found " . count($menus) . " menus\n\n";
    
    echo "3. Menu data:\n";
    foreach ($menus as $menu) {
        var_dump($menu);
    }
    
} catch (\Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
