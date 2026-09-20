<?php

declare(strict_types=1);

if (version_compare($ver = PHP_VERSION, $req = '8.5', '<')) {
    exit(sprintf('You are running PHP %s, but Pagekit needs at least <strong>PHP %s</strong> to run.', $ver, $req));
}

if (PHP_SAPI == 'cli-server' && is_file(__DIR__.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

if (!isset($_SERVER['HTTP_MOD_REWRITE']) && !isset($_SERVER['REDIRECT_HTTP_MOD_REWRITE'])) {
    $_SERVER['HTTP_MOD_REWRITE'] = 'Off';
} else {
    $_SERVER['HTTP_MOD_REWRITE'] = 'On';
}

date_default_timezone_set('UTC');

$env = 'system';
$path = dirname(__DIR__);

// A throwable escaping the application leaves a blank page and nothing else -
// the one failure nobody can diagnose. The handler is registered before the
// boot, so a fault in the boot itself is written down as well, and the log
// directory is created the first time a failure needs it: an install that
// never fails never grows one. Neither the directory nor the write reports
// its own trouble, because recording a failure must not add a second one.
set_exception_handler(function (\Throwable $e) use ($path): void {
    $logs = $path.'/tmp/logs';

    if (!is_dir($logs) && !@mkdir($logs, 0755, true) && !is_dir($logs)) {
        return;
    }

    $message = sprintf(
        "\n[UNCAUGHT EXCEPTION] [%s]\nType: %s\nMessage: %s\nFile: %s:%d\nTrace:\n%s\n\n",
        date('Y-m-d H:i:s'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );

    @error_log($message, 3, $logs.'/debug.log');
});

$config = [
    'path' => $path,
    'path.public' => __DIR__,
    'path.packages' => $path.'/packages',
    'path.storage' => $path.'/storage',
    'path.temp' => $path.'/tmp/temp',
    'path.cache' => $path.'/tmp/cache',
    'path.logs' => $path.'/tmp/logs',
    // State the application has to find again after a fault, kept apart from the
    // temp and cache directories because clearing the cache empties those - and
    // outside storage/, which the webroot links to.
    'path.system' => $path.'/tmp/system',
    // What a removed package can be restored from. Same posture as the state
    // above and for a stronger reason: a snapshot has to survive weeks of cache
    // clears, and it carries a database dump that may not be reachable over HTTP.
    'path.snapshots' => $path.'/tmp/snapshots',
    'path.vendor' => $path.'/app/vendor',
    'path.artifact' => $path.'/tmp/packages',
    'config.file' => realpath($path.'/config.php'),
    'system.api' => 'https://pagekit.com',
];

if (!$config['config.file'] || !file_exists($config['config.file'])) {
    $env = 'installer';
}

if (PHP_SAPI == 'cli') {
    $env = 'console';
}

require_once "$path/app/$env/app.php";
