<?php

require 'app/vendor/autoload.php';

use Pagekit\Cache\Adapter\ArrayAdapter;
use Pagekit\Cache\Adapter\FilesystemAdapter;
use Pagekit\Cache\Adapter\PhpFilesAdapter;

echo "Testing PSR-6 Adapters with doctrine/cache API...\n\n";

$adapters = [
    'ArrayAdapter' => new ArrayAdapter(),
    'FilesystemAdapter' => new FilesystemAdapter('/tmp/test-cache-fs'),
    'PhpFilesAdapter' => new PhpFilesAdapter('/tmp/test-cache-php'),
];

foreach ($adapters as $name => $adapter) {
    echo "Testing $name:\n";
    echo str_repeat('-', 40) . "\n";
    
    // Test basic operations
    $testKey = 'test_key';
    $testValue = ['data' => 'test value', 'number' => 42];
    
    // Save
    $result = $adapter->save($testKey, $testValue);
    echo "save('$testKey', data): " . ($result ? 'OK' : 'FAIL') . "\n";
    
    // Fetch
    $fetched = $adapter->fetch($testKey);
    echo "fetch('$testKey'): " . ($fetched === $testValue ? 'OK' : 'FAIL') . "\n";
    
    // Contains
    $contains = $adapter->contains($testKey);
    echo "contains('$testKey'): " . ($contains ? 'OK' : 'FAIL') . "\n";
    
    // Delete
    $deleted = $adapter->delete($testKey);
    echo "delete('$testKey'): " . ($deleted ? 'OK' : 'FAIL') . "\n";
    
    // Contains after delete
    $containsAfter = $adapter->contains($testKey);
    echo "contains after delete: " . (!$containsAfter ? 'OK' : 'FAIL') . "\n";
    
    // Test namespace
    $adapter->setNamespace('test_ns');
    $adapter->save('ns_key', 'ns_value');
    $nsValue = $adapter->fetch('ns_key');
    echo "namespace support: " . ($nsValue === 'ns_value' ? 'OK' : 'FAIL') . "\n";
    
    // Clear
    $adapter->flushAll();
    $afterFlush = $adapter->fetch('ns_key');
    echo "flushAll(): " . ($afterFlush === false ? 'OK' : 'FAIL') . "\n";
    
    echo "\n";
}

// Clean up
@rmdir('/tmp/test-cache-fs');
@rmdir('/tmp/test-cache-php');

echo "All tests completed!\n";