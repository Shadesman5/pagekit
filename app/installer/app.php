<?php

declare(strict_types=1);

use Pagekit\Application as App;
use Pagekit\Module\Loader\AutoLoader;
use Pagekit\Module\Loader\ConfigLoader;
use Pagekit\Module\Loader\EnvConfigLoader;

$loader = require $path.'/autoload.php';
$requirements = require __DIR__.'/requirements.php';

if ($failed = $requirements->getFailedRequirements()) {
    require __DIR__.'/views/requirements.php';
    exit;
}

// A directory this process cannot write is already a row above. One it can
// write but cannot leave owner-only must still not serve the installer.
require_once $path.'/app/modules/filesystem/src/RuntimeDirectories.php';

\Pagekit\Filesystem\RuntimeDirectories::ensure($config['path.data']);
\Pagekit\Filesystem\RuntimeDirectories::ensure($config['path.snapshots']);
\Pagekit\Filesystem\RuntimeDirectories::ensure($config['path.system']);

$app = new App($config);
$app->set('autoloader', $loader);

$app->get('module')->register([
    'app/modules/*/module.json',
    'app/package/module.json',
    'app/installer/module.json',
    'app/system/module.json',
], $path);

$app->get('module')->addLoader(new AutoLoader($app->get('autoloader')));
$app->get('module')->addLoader(new ConfigLoader(require $path.'/app/system/config.php'));
$app->get('module')->addLoader(new ConfigLoader(require __DIR__.'/config.php'));

// Last loader wins, so an installation configured through the environment is
// offered its own database connection instead of the defaults.
$app->get('module')->addLoader(new EnvConfigLoader());

$app->get('module')->load('installer');

$app->run();
