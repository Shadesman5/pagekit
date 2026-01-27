<?php

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
            'wrapperClass' => 'Pagekit\Database\Connection'
        ];

        $app['dbs'] = function ($app) use ($default) {

            $dbs = [];

            foreach ($this->config['connections'] as $name => $params) {
                $connectionParams = array_replace($default, $params);
                
                // DBAL 3.x: Always create debug middleware - it will collect queries when enabled
                if (class_exists('Pagekit\Debug\Middleware\DebugMiddleware') && 
                    class_exists('Pagekit\Debug\Middleware\DebugLogger')) {
                    
                    try {
                        // Create logger - ALWAYS ENABLED to ensure queries are captured
                        $stopwatch = null; // Will be set later if debugbar is active
                        $logger = new \Pagekit\Debug\Middleware\DebugLogger($stopwatch);
                        
                        // ALWAYS enable logging - we'll filter later when displaying
                        $logger->enabled = true;
                        
                        
                        // Create middleware with logger
                        $middleware = new \Pagekit\Debug\Middleware\DebugMiddleware($logger);
                        
                        // Add to connection params (required for DBAL 3.x)
                        $connectionParams['middlewares'] = [$middleware];
                        
                        // Store reference for later use by debugbar
                        $app['db.debug_middleware'] = $middleware;
                        $app['db.debug_logger'] = $logger;
                    } catch (\Exception $e) {
                        // If middleware creation fails, continue without it
                    }
                }
                
                // DBAL 3.x Bug: Middlewares are ignored when using wrapperClass
                // We need to manually wrap the driver before creating the connection
                if (isset($connectionParams['middlewares']) && !empty($connectionParams['middlewares'])) {
                    // First create the connection normally to get the driver
                    $tempConnection = DriverManager::getConnection($connectionParams);
                    
                    // Get the driver from the connection
                    $driver = $tempConnection->getDriver();
                    
                    // Apply middlewares manually
                    foreach ($connectionParams['middlewares'] as $middleware) {
                        $driver = $middleware->wrap($driver);
                    }
                    
                    // Get configuration from temp connection
                    $config = $tempConnection->getConfiguration();
                    
                    // Create new connection with wrapped driver
                    $connection = new $connectionParams['wrapperClass'](
                        $connectionParams,
                        $driver,
                        $config
                    );
                    
                    // Close temp connection
                    $tempConnection->close();
                    
                    $dbs[$name] = $connection;
                } else {
                    // Fallback to standard creation
                    $dbs[$name] = DriverManager::getConnection($connectionParams);
                }
            }

            return $dbs;
        };

        $app['db'] = fn ($app) => $app['dbs'][$this->config['default']];

        $app['db.em'] = fn ($app) => new EntityManager($app['db'], $app['db.metas'], $app['db.events']);

        $app['db.metas'] = function ($app) {

            $manager = new MetadataManager($app['db'], $app['db.events']);
            $manager->setLoader(new AttributeLoader());
            // Cache now supports both doctrine/cache and PSR-6 interfaces
            $manager->setCache($app['cache.phpfile']);

            return $manager;
        };

        $app['db.events'] = fn ($app) => new PrefixEventDispatcher('model.', $app['events']);

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
