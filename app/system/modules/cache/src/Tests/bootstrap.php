<?php

// Bootstrap for PSR-6 Cache Tests

// Load composer autoloader from app directory
require_once '/workspace/app/vendor/autoload.php';

// Manually register the Cache module classes
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('Pagekit\\Cache\\', __DIR__ . '/..');
$loader->register();
