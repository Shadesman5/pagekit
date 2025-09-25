<?php

if (version_compare($ver = PHP_VERSION, $req = '8.2', '<')) {
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
$path = __DIR__;
$config = array(
    'path'          => $path,
    'path.packages' => $path.'/packages',
    'path.storage'  => $path.'/storage',
    'path.temp'     => $path.'/tmp/temp',
    'path.cache'    => $path.'/tmp/cache',
    'path.logs'     => $path.'/tmp/logs',
    'path.vendor'   => $path.'/vendor',
    'path.artifact' => $path.'/tmp/packages',
    'config.file'   => realpath($path.'/config.php'),
    'system.api'    => 'https://pagekit.com'
);

if (!$config['config.file'] || !file_exists($config['config.file'])) {
    $env = 'installer';
}

if (PHP_SAPI == 'cli') {
    $env = 'console';
}

// Debug logging for installer
if ($env === 'installer' && isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/installer') !== false) {
    $log = date('Y-m-d H:i:s') . " - Installer request detected\n";
    $log .= "  ENV: $env\n";
    $log .= "  URI: " . $_SERVER['REQUEST_URI'] . "\n";
    $log .= "  Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
    $log .= "  User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'not set') . "\n";
    file_put_contents(__DIR__ . '/installer_index.log', $log, FILE_APPEND);
}

try {
    file_put_contents(__DIR__ . '/installer_index.log', "About to require: $path/app/$env/app.php\n", FILE_APPEND);
    require_once "$path/app/$env/app.php";
    file_put_contents(__DIR__ . '/installer_index.log', "Successfully required app.php\n", FILE_APPEND);
} catch (\Throwable $e) {
    file_put_contents(__DIR__ . '/installer_index.log', "FATAL ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    file_put_contents(__DIR__ . '/installer_index.log', "Stack: " . $e->getTraceAsString() . "\n", FILE_APPEND);
    
    // Show error in browser
    header('Content-Type: text/plain');
    echo "FATAL ERROR in index.php:\n";
    echo $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit;
}
