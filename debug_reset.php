<?php
// Debug reset confirm directly in browser
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once __DIR__ . '/autoload.php';
    
    $config = [];
    if (file_exists(__DIR__ . '/config.php')) {
        $config = require __DIR__ . '/config.php';
    }
    
    $app = new Pagekit\Application($config);
    $app->boot();
    
    echo "<h1>Debug Password Reset Confirm</h1>";
    
    // Check request
    $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
    $key = $request->query->get('key', 'no-key-provided');
    
    echo "<p>Key from URL: <code>$key</code></p>";
    
    // Check if user exists with this key
    $db = $app['db'];
    $user = $db->createQueryBuilder()
        ->select('*')
        ->from('@system_user')
        ->where('activation = :key')
        ->setParameter('key', $key)
        ->execute()
        ->fetch();
    
    if ($user) {
        echo "<p style='color:green'>✓ User found: {$user['username']} ({$user['email']})</p>";
    } else {
        echo "<p style='color:red'>✗ No user with this activation key</p>";
    }
    
    // Test the view rendering
    echo "<h2>Testing View Rendering</h2>";
    
    try {
        // Set request in app
        $app['request'] = $request;
        
        // Test if we can render the view
        $viewParams = [
            'activation' => $key,
            'error' => ''
        ];
        
        $html = $app['view']->render('system/user/reset-confirm.php', $viewParams);
        echo "<p style='color:green'>✓ View can be rendered (length: " . strlen($html) . " bytes)</p>";
        
    } catch (\Exception $e) {
        echo "<p style='color:red'>✗ View error: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
    
    // Test the controller
    echo "<h2>Testing Controller</h2>";
    
    try {
        $controller = new Pagekit\User\Controller\ResetPasswordController();
        $result = $controller->confirmAction();
        
        if (is_array($result)) {
            echo "<p style='color:green'>✓ Controller returned result</p>";
            echo "<pre>" . htmlspecialchars(print_r($result, true)) . "</pre>";
        }
        
    } catch (\Exception $e) {
        echo "<p style='color:red'>✗ Controller error: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p>File: " . $e->getFile() . " Line: " . $e->getLine() . "</p>";
        
        // Show the exact line of code
        if (file_exists($e->getFile())) {
            $lines = file($e->getFile());
            $errorLine = $e->getLine() - 1;
            if (isset($lines[$errorLine])) {
                echo "<p>Error at line " . $e->getLine() . ":</p>";
                echo "<pre style='background:#fee;padding:10px'>";
                for ($i = max(0, $errorLine - 2); $i <= min(count($lines) - 1, $errorLine + 2); $i++) {
                    $lineNum = $i + 1;
                    if ($i == $errorLine) {
                        echo "<strong>&gt;&gt;&gt; Line $lineNum: " . htmlspecialchars($lines[$i]) . "</strong>";
                    } else {
                        echo "    Line $lineNum: " . htmlspecialchars($lines[$i]);
                    }
                }
                echo "</pre>";
            }
        }
    }
    
} catch (\Exception $e) {
    echo "<div style='background:#fee;padding:20px;border:2px solid #c00'>";
    echo "<h2>Fatal Error</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>File: " . $e->getFile() . " Line: " . $e->getLine() . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}