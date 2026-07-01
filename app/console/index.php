<?php

declare(strict_types=1);

return [

    'name' => 'console',

    'autoload' => [

        'Pagekit\\Console\\' => 'src',

    ],

    'events' => [

        'console.init' => function ($event, $console) {

            $container = $console->getContainer();
            $namespace = 'Pagekit\\Console\\Commands\\';

            foreach (glob(__DIR__ . '/src/Commands/*Command.php') ?: [] as $file) {
                $class = $namespace . basename($file, '.php');
                $console->add(new $class($container));
            }

            foreach (glob(__DIR__ . '/src/Commands/Migration/*Command.php') ?: [] as $file) {
                $class = $namespace . 'Migration\\' . basename($file, '.php');
                $console->add(new $class($container));
            }

        },

    ],

    'require' => ['application', 'migration'],

];
