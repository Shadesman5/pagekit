<?php
/**
 * Global Error Handler Test
 * This will catch ALL errors including fatal ones
 */

// Set up error handler BEFORE anything else
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $logFile = __DIR__ . '/../tmp/logs/debug.log';
    $message = sprintf(
        "[PHP ERROR] [%s] %s in %s:%d\n",
        date('Y-m-d H:i:s'),
        $errstr,
        $errfile,
        $errline
    );
    error_log($message, 3, $logFile);
    echo "<pre>$message</pre>";
    return false; // Let PHP handle it too
});

// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $logFile = __DIR__ . '/../tmp/logs/debug.log';
        $message = sprintf(
            "[FATAL ERROR] [%s] %s in %s:%d\n",
            date('Y-m-d H:i:s'),
            $error['message'],
            $error['file'],
            $error['line']
        );
        error_log($message, 3, $logFile);
        echo "<h1>Fatal Error Caught!</h1>";
        echo "<pre>$message</pre>";
    }
});

// Now load Pagekit
echo "<h2>Loading Pagekit with Error Tracking...</h2>";
echo "<pre>";

try {
    require __DIR__.'/../app/app.php';
    echo "✓ App loaded successfully\n";
    
    // Try to access admin route handler
    echo "\n✓ Pagekit is running!\n";
    
} catch (Throwable $e) {
    $logFile = __DIR__ . '/../tmp/logs/debug.log';
    $message = sprintf(
        "[EXCEPTION] [%s] %s: %s in %s:%d\nStack:\n%s\n",
        date('Y-m-d H:i:s'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    error_log($message, 3, $logFile);
    
    echo "\n=== EXCEPTION CAUGHT ===\n";
    echo "Type: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nStack Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
