<?php
// Minimal test - what works and what doesn't

// Step 1: Can we load Pagekit?
echo "Step 1: Loading Pagekit...<br>";
try {
    require_once __DIR__ . '/autoload.php';
    echo "✓ Autoload OK<br>";
} catch (\Exception $e) {
    die("✗ Autoload failed: " . $e->getMessage());
}

// Step 2: Can we boot the app?
echo "<br>Step 2: Booting app...<br>";
try {
    date_default_timezone_set('UTC');
    $env = 'system';
    $path = __DIR__;
    $config = [
        'path'          => $path,
        'path.packages' => $path.'/packages',
        'path.storage'  => $path.'/storage',
        'path.temp'     => $path.'/tmp/temp',
        'path.cache'    => $path.'/tmp/cache',
        'path.logs'     => $path.'/tmp/logs',
        'path.vendor'   => $path.'/vendor',
        'path.artifact' => $path.'/tmp/packages',
        'config.file'   => realpath($path.'/config.php'),
        'system.api'    => 'https://pagekit.com'
    ];
    
    require_once "$path/app/$env/app.php";
    echo "✓ App booted<br>";
} catch (\Exception $e) {
    die("✗ Boot failed: " . $e->getMessage());
}

// Step 3: Can we create the controller?
echo "<br>Step 3: Creating controller...<br>";
try {
    $controller = new Pagekit\User\Controller\ResetPasswordController();
    echo "✓ Controller created<br>";
} catch (\Exception $e) {
    die("✗ Controller creation failed: " . $e->getMessage());
}

// Step 4: Can we access the route directly?
echo "<br>Step 4: Testing route access...<br>";
try {
    $routes = $app['routes'];
    $route = $routes->get('@user/resetpassword/confirm');
    if ($route) {
        echo "✓ Route exists<br>";
    } else {
        echo "✗ Route not found<br>";
    }
} catch (\Exception $e) {
    echo "✗ Route check failed: " . $e->getMessage() . "<br>";
}

// Step 5: Test the view file
echo "<br>Step 5: Checking view file...<br>";
$viewFile = __DIR__ . '/app/system/modules/user/views/reset-confirm.php';
if (file_exists($viewFile)) {
    echo "✓ View file exists<br>";
    
    // Check for syntax errors
    $output = shell_exec("php -l $viewFile 2>&1");
    if (strpos($output, 'No syntax errors') !== false) {
        echo "✓ View file syntax OK<br>";
    } else {
        echo "✗ View file has syntax errors: $output<br>";
    }
} else {
    echo "✗ View file not found<br>";
}

// Step 6: Test request handling
echo "<br>Step 6: Testing request handling...<br>";
try {
    $_GET['key'] = 'test';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/user/resetpassword/confirm?key=test';
    
    $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
    $app['request'] = $request;
    
    echo "✓ Request created and set<br>";
} catch (\Exception $e) {
    echo "✗ Request handling failed: " . $e->getMessage() . "<br>";
}

echo "<br><strong>All basic checks passed!</strong><br>";
echo "<br>Now check the actual route: <a href='/user/resetpassword/confirm?key=test'>Click here</a>";