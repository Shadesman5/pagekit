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

$app = new App($config);
$app['autoloader'] = $loader; // TODO: Must be refactored in Step 2.0.1d (Packages + ArrayAccess Removal)

$app->get('module')->register([
    'app/modules/*/index.php',
    'app/installer/index.php',
    'app/system/index.php'
], $path);

$app->get('module')->addLoader(new AutoLoader($app->get('autoloader')));
$app->get('module')->addLoader(new ConfigLoader(require $path.'/app/system/config.php'));
$app->get('module')->addLoader(new ConfigLoader(require __DIR__.'/config.php'));
$app->get('module')->load('installer');

$app->run();
