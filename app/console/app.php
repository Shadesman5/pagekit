<?php

use Pagekit\Application as App;
use Pagekit\Application\Console\Application as Console;
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
    'app/console/index.php'
], $path);

$app->get('module')->addLoader(new AutoLoader($app->get('autoloader')));
$app->get('module')->addLoader(new ConfigLoader(require $path.'/app/system/config.php'));

if ($app->get('config.file')) {
    $app->get('module')->addLoader(new ConfigLoader(require $app->get('config.file')));
    $app->get('module')->load('system');
}
$app->get('module')->load('console');

$console = new Console($app, 'Pagekit', $app->get('version'));
$console->run();
