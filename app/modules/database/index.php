<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Loader\AttributeLoader;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Event\PrefixEventDispatcher;
use Pagekit\Filesystem\Path;

/**
 * Resolves a filesystem path for prefix comparison. Prefers realpath; when the
 * file does not exist yet, resolves an existing parent directory and appends
 * the basename so a not-yet-created SQLite file is still comparable.
 */
$canonicalizeFilesystemPath = static function (string $path): string {
    $normalized = Path::parse(strtr($path, '\\', '/'))['pathname'];
    $real = realpath($normalized);
    if ($real !== false) {
        return strtr($real, '\\', '/');
    }

    $dir = dirname($normalized);
    $realDir = realpath($dir);
    if ($realDir !== false) {
        return strtr($realDir, '\\', '/').'/'.basename($normalized);
    }

    return $normalized;
};

$config = [

    'name' => 'database',

    'main' => function ($app) use ($canonicalizeFilesystemPath) {

        $default = [
            'wrapperClass' => 'Pagekit\Database\Connection',
        ];

        $app->set('dbs', function ($app) use ($default, $canonicalizeFilesystemPath) {

            $dbs = [];

            foreach ($this->config['connections'] as $name => $params) {
                $connectionParams = array_replace($default, $params);

                // SQLite file paths are always relative to the application root
                // (next to config.php), never to getcwd() — docroots like public/
                // must not create a world-readable DB under the webroot.
                if (($connectionParams['driver'] ?? '') === 'pdo_sqlite'
                    && empty($connectionParams['memory'])
                    && isset($connectionParams['path'])
                    && is_string($connectionParams['path'])
                    && $connectionParams['path'] !== ''
                ) {
                    if (Path::isRelative($connectionParams['path'])) {
                        $connectionParams['path'] = $app->get('path').'/'.$connectionParams['path'];
                    }

                    // Reject any resolved path under the document root — Apache
                    // .htaccess is not universal (Nginx / php -S).
                    $publicRoot = $app->has('path.public')
                        ? (string) $app->get('path.public')
                        : $app->get('path').'/public';
                    $dbPath = $canonicalizeFilesystemPath($connectionParams['path']);
                    $publicDir = Path::directory($canonicalizeFilesystemPath($publicRoot));
                    if (str_starts_with($dbPath, $publicDir) || $dbPath === rtrim($publicDir, '/')) {
                        throw new \InvalidArgumentException(sprintf(
                            'SQLite database path "%s" must not be under the public webroot "%s".',
                            $connectionParams['path'],
                            $publicRoot,
                        ));
                    }
                }

                // DBAL 3.x: Always create debug middleware - it will collect queries when enabled
                if (class_exists('Pagekit\Debug\Middleware\DebugMiddleware') &&
                    class_exists('Pagekit\Debug\Middleware\DebugLogger')) {

                    try {
                        $stopwatch = null;
                        $logger = new \Pagekit\Debug\Middleware\DebugLogger($stopwatch);

                        $logger->enabled = true;

                        $middleware = new \Pagekit\Debug\Middleware\DebugMiddleware($logger);

                        $connectionParams['middlewares'] = [$middleware];

                        $app->set('db.debug_middleware', $middleware);
                        $app->set('db.debug_logger', $logger);
                    } catch (\Exception $e) {
                        // If middleware creation fails, continue without it
                    }
                }

                // DBAL 3.x Bug: Middlewares are ignored when using wrapperClass
                if (isset($connectionParams['middlewares']) && !empty($connectionParams['middlewares'])) {
                    $tempConnection = DriverManager::getConnection($connectionParams);

                    $driver = $tempConnection->getDriver();

                    foreach ($connectionParams['middlewares'] as $middleware) {
                        $driver = $middleware->wrap($driver);
                    }

                    $config = $tempConnection->getConfiguration();

                    $connection = new $connectionParams['wrapperClass'](
                        $connectionParams,
                        $driver,
                        $config
                    );

                    $tempConnection->close();

                    $dbs[$name] = $connection;
                } else {
                    $dbs[$name] = DriverManager::getConnection($connectionParams);
                }
            }

            return $dbs;
        });

        $app->set('db', fn ($app) => $app->get('dbs')[$this->config['default']]);

        $app->set('db.em', fn ($app) => new EntityManager($app->get('db'), $app->get('db.metas'), $app->get('db.events')));

        $app->set('db.metas', function ($app) {

            $manager = new MetadataManager($app->get('db'), $app->get('db.events'));
            $manager->setLoader(new AttributeLoader());
            $manager->setCache($app->get('cache.phpfile'));

            return $manager;
        });

        $app->set('db.events', fn ($app) => new PrefixEventDispatcher('model.', $app->get('events')));

        // Note: db.debug_middleware is now created inline in the dbs factory above
        // This ensures it's available when the connection is created

        // Override existing types
        Type::overrideType(Types::SIMPLE_ARRAY, '\Pagekit\Database\Types\SimpleArrayType');
        Type::overrideType(Types::JSON, '\Pagekit\Database\Types\JsonArrayType');
    },

    'autoload' => [

        'Pagekit\\Database\\' => 'src',

    ],

    'config' => [

        'default' => 'sqlite',

        'connections' => [

            'mysql' => [

                'driver' => 'pdo_mysql',
                'dbname' => '',
                'host' => 'localhost',
                'user' => 'root',
                'password' => '',
                'engine' => 'InnoDB',
                'charset' => 'utf8',
                'collate' => 'utf8_unicode_ci',
                'prefix' => '',

            ],

            'sqlite' => [

                'driver' => 'pdo_sqlite',
                // Relative to application root (container "path"); resolved at connection time.
                'path' => 'pagekit.db',
                'charset' => 'utf8',
                'prefix' => 'pk_',
                'driverOptions' => [
                    'userDefinedFunctions' => [
                        'REGEXP' => [
                            'callback' => fn ($pattern, $subject) => preg_match("/$pattern/", $subject ?? ''),
                            'numArgs' => 2,
                        ],
                    ],
                ],

            ],

        ],

    ],

];

if (defined('Pdo\Mysql::ATTR_INIT_COMMAND')) {
    $config['config']['connections']['mysql']['driverOptions'] = [
        \Pdo\Mysql::ATTR_INIT_COMMAND => 'SET NAMES utf8 COLLATE utf8_unicode_ci',
    ];
}

return $config;
