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

$app->run();
