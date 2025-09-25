<?php
// Simple test script to check if installer works

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== Installer Test ===\n\n";

// Test 1: Check if we can load the application
echo "1. Loading application...\n";
require_once __DIR__ . '/autoload.php';
$app = new Pagekit\Application([
    'path' => __DIR__,
    'config.file' => false
]);
echo "   OK - Application loaded\n\n";

// Test 2: Check if installer module exists
echo "2. Checking installer module...\n";
$app['module']->load('installer');
echo "   OK - Installer module loaded\n\n";

// Test 3: Check if controller exists
echo "3. Checking controller...\n";
$controller = new Pagekit\Installer\Controller\InstallerController();
echo "   OK - Controller instantiated\n\n";

// Test 4: Simulate a check request
echo "4. Testing check action...\n";
try {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/installer/check';
    
    // Create a fake request
    $request = Symfony\Component\HttpFoundation\Request::create(
        '/installer/check',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{"config":{"database":{"connections":{"sqlite":{}},"default":"sqlite"}}}'
    );
    
    $app['request'] = $request;
    
    $result = $controller->checkAction();
    echo "   OK - Check action returned: " . json_encode($result) . "\n\n";
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "=== Test Complete ===\n";