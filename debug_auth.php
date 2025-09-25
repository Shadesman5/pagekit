<?php
// Debug authentication error
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set up environment
$_SERVER['REQUEST_URI'] = '/user/authenticate';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'pagekit.test';
$_SERVER['SCRIPT_NAME'] = '/index.php';

// Capture any errors
try {
    require_once __DIR__ . '/index.php';
} catch (\Throwable $e) {
    echo "\n=== AUTHENTICATION ERROR ===\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}