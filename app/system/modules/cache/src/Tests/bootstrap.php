<?php

declare(strict_types=1);

// Bootstrap for PSR-6 Cache Tests

require_once __DIR__ . '/../../../../../../vendor/autoload.php';

// Manually register the Cache module classes
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('Pagekit\\Cache\\', __DIR__ . '/..');
$loader->register();
