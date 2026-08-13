<?php

declare(strict_types=1);

/**
 * Bootstrap for the theme-one tests.
 *
 * The theme's template helpers (image(), attrs(), isImage()) are plain functions
 * in a file the theme requires from its boot definition, and composer declares
 * no autoload entry for them, so the tests load the file themselves. Loading it
 * once keeps a second test class in the same process from redeclaring them.
 *
 * Mirrors tests/Unit/Blog/bootstrap.php, which pulls in the runtime-loaded
 * classes of the blog package the same way.
 */

require_once dirname(__DIR__, 3) . '/packages/pagekit/theme-one/functions.php';
