<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Loader\AttributeLoader;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Event\PrefixEventDispatcher;

$config = [

    'name' => 'database',

    'main' => function ($app) {

        $default = [
            'wrapperClass' => 'Pagekit\Database\Connection',
        ];

        $app->set('dbs', function ($app) use ($default) {

            $dbs = [];

            foreach ($this->config['connections'] as $name => $params) {
                $connectionParams = array_replace($default, $params);

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

        // Register json_array as a custom type for backward compatibility
        if (!Type::hasType('json_array')) {
            Type::addType('json_array', '\Pagekit\Database\Types\JsonArrayType');
        }
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
                'path' => "pagekit.db",
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

if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
    $config['config']['connections']['mysql']['driverOptions'] = [
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8 COLLATE utf8_unicode_ci',
    ];
}

return $config;
