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

// Debug mode temporarily disabled
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

try {
    file_put_contents(__DIR__ . '/../../installer_app.log', "1. Creating App\n", FILE_APPEND);
    $app = new App($config);
    $app['autoloader'] = $loader;

    file_put_contents(__DIR__ . '/../../installer_app.log', "2. Registering modules\n", FILE_APPEND);
    $app['module']->register([
        'app/modules/*/index.php',
        'app/installer/index.php',
        'app/system/index.php'
    ], $path);

    file_put_contents(__DIR__ . '/../../installer_app.log', "3. Adding loaders\n", FILE_APPEND);
    $app['module']->addLoader(new AutoLoader($app['autoloader']));
    $app['module']->addLoader(new ConfigLoader(require $path.'/app/system/config.php'));
    $app['module']->addLoader(new ConfigLoader(require __DIR__.'/config.php'));
    
    file_put_contents(__DIR__ . '/../../installer_app.log', "4. Loading installer module\n", FILE_APPEND);
    $app['module']->load('installer');

    file_put_contents(__DIR__ . '/../../installer_app.log', "5. Running app\n", FILE_APPEND);
    $app->run();
    file_put_contents(__DIR__ . '/../../installer_app.log', "6. App finished\n", FILE_APPEND);
} catch (\Throwable $e) {
    file_put_contents(__DIR__ . '/../../installer_app.log', "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    file_put_contents(__DIR__ . '/../../installer_app.log', "File: " . $e->getFile() . ":" . $e->getLine() . "\n", FILE_APPEND);
    throw $e;
}
