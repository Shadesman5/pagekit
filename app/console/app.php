<?php

declare(strict_types=1);

use Pagekit\Application as App;
use Pagekit\Application\Console\Application as Console;
use Pagekit\Module\Loader\AutoLoader;
use Pagekit\Module\Loader\ConfigLoader;
use Pagekit\Module\Loader\EnvConfigLoader;

$loader = require $path.'/autoload.php';

$app = new App($config);
$app->set('autoloader', $loader);

$app->get('module')->register([
    'packages/*/*/index.php',
    'app/modules/*/index.php',
    'app/installer/index.php',
    'app/system/index.php',
    'app/console/index.php',
], $path);

$app->get('module')->addLoader(new AutoLoader($app->get('autoloader')));
$app->get('module')->addLoader(new ConfigLoader(require $path.'/app/system/config.php'));

$configFile = $app->get('config.file');

if ($configFile) {
    $app->get('module')->addLoader(new ConfigLoader(require $configFile));
}

// Last loader wins, so the environment overrides config.php.
$app->get('module')->addLoader(new EnvConfigLoader());

// An installation without a configuration has no system to talk to; its console
// is limited to the commands that set one up.
if ($configFile) {
    $app->get('module')->load('system');
}

$app->get('module')->load('console');

$console = new Console($app, 'Pagekit', $app->get('version'));
$console->run();
