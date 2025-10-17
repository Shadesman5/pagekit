<?php
/**
 * Pagekit Bootstrap Debug Test
 * Access via: http://yoursite.test/test-pagekit-bootstrap.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<pre>";
echo "=== Pagekit Bootstrap Debug ===" . PHP_EOL;
echo "PHP Version: " . PHP_VERSION . PHP_EOL;
echo PHP_EOL;

try {
    echo "[1] Loading Pagekit application..." . PHP_EOL;
    $app = require __DIR__.'/../app/app.php';
    echo "    ✓ App loaded" . PHP_EOL;
    echo PHP_EOL;

    echo "[2] Checking if app is booted..." . PHP_EOL;
    echo "    App class: " . get_class($app) . PHP_EOL;
    echo PHP_EOL;

    echo "[3] Checking EntityManager..." . PHP_EOL;
    if (isset($app['db.em'])) {
        $em = $app['db.em'];
        echo "    ✓ EntityManager available: " . get_class($em) . PHP_EOL;
    } else {
        echo "    ✗ EntityManager NOT in container!" . PHP_EOL;
    }
    echo PHP_EOL;

    echo "[4] Testing User model access..." . PHP_EOL;
    $userClass = 'Pagekit\\User\\Model\\User';
    echo "    User class: " . $userClass . PHP_EOL;
    $user = new $userClass();
    echo "    ✓ User instance created" . PHP_EOL;
    echo PHP_EOL;

    echo "[5] Testing ModelTrait::getManager()..." . PHP_EOL;
    try {
        $manager = $userClass::getManager();
        echo "    ✓ Got EntityManager: " . get_class($manager) . PHP_EOL;
    } catch (Throwable $e) {
        echo "    ✗ ERROR: " . $e->getMessage() . PHP_EOL;
    }
    echo PHP_EOL;

    echo "=== All checks completed ===" . PHP_EOL;

} catch (Throwable $e) {
    echo PHP_EOL;
    echo "=== FATAL ERROR ===" . PHP_EOL;
    echo "Type: " . get_class($e) . PHP_EOL;
    echo "Error: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    echo PHP_EOL;
    echo "Stack trace:" . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}

echo "</pre>";
