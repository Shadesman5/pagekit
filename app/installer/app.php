<?php

use Pagekit\Application as App;
use Pagekit\Module\Loader\AutoLoader;
use Pagekit\Module\Loader\ConfigLoader;

$loader = require $path.'/autoload.php';
$requirements = require __DIR__.'/requirements.php';

if ($failed = $requirements->getFailedRequirements()) {
    require __DIR__.'/views/requirements.php';
    exit;
}

// Enable debug mode with error logging
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', '/workspace/php_errors.log');
ini_set('log_errors', 1);

$config['application.debug'] = true;

$app = new App($config);
$app['autoloader'] = $loader;

$app['module']->register([
    'app/modules/*/index.php',
    'app/installer/index.php',
    'app/system/index.php'
], $path);

$app['module']->addLoader(new AutoLoader($app['autoloader']));
$app['module']->addLoader(new ConfigLoader(require $path.'/app/system/config.php'));
$app['module']->addLoader(new ConfigLoader(require __DIR__.'/config.php'));
$app['module']->load('installer');

// Add global exception handler for debugging
$app->on('exception', function($event, $request, $exception) {
    $log = "=== INSTALLER EXCEPTION ===\n";
    $log .= "Exception: " . get_class($exception) . "\n";
    $log .= "Message: " . $exception->getMessage() . "\n";
    $log .= "File: " . $exception->getFile() . ":" . $exception->getLine() . "\n";
    $log .= "Stack trace: " . $exception->getTraceAsString() . "\n";
    
    error_log($log);
    file_put_contents('/workspace/installer_exception.log', $log, FILE_APPEND);
});

try {
    $app->run();
} catch (\Throwable $e) {
    error_log("=== INSTALLER FATAL ERROR ===");
    error_log("Error: " . $e->getMessage());
    error_log("File: " . $e->getFile() . ":" . $e->getLine());
    throw $e;
}
