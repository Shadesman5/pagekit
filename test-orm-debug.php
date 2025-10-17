<?php
/**
 * ORM Debug Test Script
 * Run with: php test-orm-debug.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', 'tmp/logs/debug.log');

echo "=== ORM Debug Test ===" . PHP_EOL;
echo "PHP Version: " . PHP_VERSION . PHP_EOL;
echo "strict_types: Testing WITHOUT declare() in this test file" . PHP_EOL;
echo PHP_EOL;

// Bootstrap
require 'app/vendor/autoload.php';

try {
    echo "[1] Loading User model class..." . PHP_EOL;
    $reflection = new ReflectionClass(Pagekit\User\Model\User::class);
    echo "    ✓ User class loaded" . PHP_EOL;
    echo PHP_EOL;

    echo "[2] Creating User instance..." . PHP_EOL;
    $user = new Pagekit\User\Model\User();
    echo "    ✓ User instance created" . PHP_EOL;
    echo PHP_EOL;

    echo "[3] Checking EntityManager static property..." . PHP_EOL;
    $emReflection = new ReflectionClass(Pagekit\Database\ORM\EntityManager::class);
    $instanceProp = $emReflection->getProperty('instance');
    $instanceProp->setAccessible(true);
    $instanceValue = $instanceProp->getValue();
    echo "    instance value: " . ($instanceValue === null ? 'NULL (OK!)' : 'NOT NULL') . PHP_EOL;
    echo PHP_EOL;

    echo "[4] Testing ModelTrait::getManager() without instance..." . PHP_EOL;
    try {
        $manager = Pagekit\User\Model\User::getManager();
        echo "    ✓ Got manager: " . get_class($manager) . PHP_EOL;
    } catch (Throwable $e) {
        echo "    ✗ ERROR: " . $e->getMessage() . PHP_EOL;
        echo "    File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    }
    echo PHP_EOL;

    echo "[5] All tests completed!" . PHP_EOL;

} catch (Throwable $e) {
    echo PHP_EOL;
    echo "=== FATAL ERROR ===" . PHP_EOL;
    echo "Error: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    echo PHP_EOL;
    echo "Stack trace:" . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    exit(1);
}

echo PHP_EOL;
echo "=== Test completed successfully ===" . PHP_EOL;
