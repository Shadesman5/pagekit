<?php

// Bootstrap for PSR-6 Cache Tests

// Load composer autoloader from app directory (portable: 5 levels up = repo root)
require_once __DIR__ . '/../../../../../vendor/autoload.php';

// Manually register the Cache module classes
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('Pagekit\\Cache\\', __DIR__ . '/..');
$loader->register();
