<?php

use Pagekit\Application as App;
use Pagekit\Module\Loader\AutoLoader;
use Pagekit\Module\Loader\ConfigLoader;

$loader = require $path.'/autoload.php';

$app = new App($config);
$app->set('autoloader', $loader);

$app->get('module')->register([
    'packages/*/*/index.php',
    'app/modules/*/index.php',
    'app/installer/index.php',
    'app/system/index.php',
], $path);

$app->get('module')->addLoader(new AutoLoader($app->get('autoloader')));
$app->get('module')->addLoader(new ConfigLoader(require __DIR__.'/config.php'));

if ($app->get('config.file') && file_exists($app->get('config.file'))) {
    $app->get('module')->addLoader(new ConfigLoader(require $app->get('config.file')));
}

$app->get('module')->load('system');

$app->run();
