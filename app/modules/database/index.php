<?php

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Loader\AnnotationLoader;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Event\PrefixEventDispatcher;
use Pagekit\Debug\Middleware\DebugMiddleware;
use Pagekit\Debug\Middleware\DebugLogger;
use Symfony\Component\Stopwatch\Stopwatch;

$config = [

    'name' => 'database',

    'main' => function ($app) {

        $default = [
            'wrapperClass' => 'Pagekit\Database\Connection'
        ];

        $app['dbs'] = function ($app) use ($default) {

            $dbs = [];

            foreach ($this->config['connections'] as $name => $params) {
                $connectionParams = array_replace($default, $params);
                
                // Add debug middleware for DBAL 3.x if debug module is enabled
                if (isset($app['db.debug_middleware'])) {
                    $connectionParams['middlewares'] = [$app['db.debug_middleware']];
                }
                
                $dbs[$name] = DriverManager::getConnection($connectionParams);
            }

            return $dbs;
        };

        $app['db'] = fn ($app) => $app['dbs'][$this->config['default']];

        $app['db.em'] = fn ($app) => new EntityManager($app['db'], $app['db.metas'], $app['db.events']);

        $app['db.metas'] = function ($app) {

            $manager = new MetadataManager($app['db'], $app['db.events']);
            $manager->setLoader(new AnnotationLoader);
            $manager->setCache($app['cache.phpfile']);

            return $manager;
        };

        $app['db.events'] = fn ($app) => new PrefixEventDispatcher('model.', $app['events']);

        // DBAL 3.x: Use middleware instead of DebugStack for SQL logging
        $app['db.debug_middleware'] = function ($app) {
            $stopwatch = isset($app['debugbar.stopwatch']) ? $app['debugbar.stopwatch'] : null;
            $logger = new DebugLogger($stopwatch);
            return new DebugMiddleware($logger);
        };

        Type::overrideType(Types::SIMPLE_ARRAY, '\Pagekit\Database\Types\SimpleArrayType');
        Type::overrideType(Types::JSON, '\Pagekit\Database\Types\JsonArrayType');
    },

    'autoload' => [

        'Pagekit\\Database\\' => 'src'

    ],

    'config' => [

        'default' => 'sqlite',

        'connections' => [

            'mysql' => [

                'driver'   => 'pdo_mysql',
                'dbname'   => '',
                'host'     => 'localhost',
                'user'     => 'root',
                'password' => '',
                'engine'   => 'InnoDB',
                'charset'  => 'utf8',
                'collate'  => 'utf8_unicode_ci',
                'prefix'   => ''

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
                            'numArgs' => 2
                        ]
                    ]
                ]

            ]

        ]

    ]

];

if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
    $config['config']['connections']['mysql']['driverOptions'] = [
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8 COLLATE utf8_unicode_ci'
    ];
}

return $config;
