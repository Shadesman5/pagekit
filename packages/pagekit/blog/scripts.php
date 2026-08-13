<?php

declare(strict_types=1);

use Pagekit\Blog\BlogLifecycle;

// The extension's autoloading is registered when its module is loaded, and a
// package is installed and enabled long before that happens: the class this
// file hands back has to be required by name.
require_once __DIR__ . '/src/BlogLifecycle.php';

return new BlogLifecycle();
