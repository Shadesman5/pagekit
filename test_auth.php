<?php
// Test authentication issue
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/autoload.php';

echo "=== Authentication Test ===\n\n";

// Check if config exists
if (!file_exists(__DIR__ . '/config.php')) {
    echo "ERROR: config.php does not exist!\n";
    echo "The installation seems incomplete.\n";
    
    // Check if database exists
    if (file_exists(__DIR__ . '/pagekit.db')) {
        echo "But pagekit.db exists.\n";
    } else {
        echo "And pagekit.db does not exist either.\n";
    }
    
    exit(1);
}

echo "config.php exists ✓\n";

// Load application
try {
    $config = require __DIR__ . '/config.php';
    $app = new Pagekit\Application($config);
    $app->boot();
    echo "Application loaded ✓\n";
    
    // Check database connection
    try {
        $app['db']->connect();
        echo "Database connected ✓\n";
    } catch (Exception $e) {
        echo "Database connection failed: " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}