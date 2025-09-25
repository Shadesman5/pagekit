<?php

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set up web environment
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = 'localhost:8000';

// Include the actual index.php content
$path = __DIR__;

require "$path/app/autoload.php";

$config = require "$path/app/system/config.php";
$app = new Pagekit\Application($config);

try {
    $response = $app->run();
    echo "\n=== Response Status: " . $response->getStatusCode() . " ===\n";
    
    if ($response->getStatusCode() >= 400) {
        echo "\nResponse Headers:\n";
        foreach ($response->headers->all() as $key => $values) {
            foreach ($values as $value) {
                echo "$key: $value\n";
            }
        }
        echo "\nResponse Content (first 1000 chars):\n";
        echo substr($response->getContent(), 0, 1000) . "\n";
    }
} catch (\Throwable $e) {
    echo "\n\n=== ERROR CAUGHT ===\n";
    echo "Type: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack trace:\n";
    
    // Show first 10 stack frames
    $trace = $e->getTrace();
    for ($i = 0; $i < min(10, count($trace)); $i++) {
        $frame = $trace[$i];
        echo "#$i ";
        if (isset($frame['file'])) {
            echo $frame['file'] . ":" . $frame['line'];
        }
        if (isset($frame['function'])) {
            echo " " . $frame['function'] . "()";
        }
        echo "\n";
    }
}