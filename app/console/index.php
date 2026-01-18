<?php

return [

    'name' => 'console',

    'autoload' => [

        'Pagekit\\Console\\' => 'src'

    ],

    'events' => [

        'console.init' => function ($event, $console) {

            $namespace = 'Pagekit\\Console\\Commands\\';

            // Load commands from root Commands directory
            foreach (glob(__DIR__ . '/src/Commands/*Command.php') as $file) {
                $class = $namespace . basename($file, '.php');
                $console->add(new $class);
            }

            // Load commands from Migration subdirectory
            foreach (glob(__DIR__ . '/src/Commands/Migration/*Command.php') as $file) {
                $class = $namespace . 'Migration\\' . basename($file, '.php');
                $console->add(new $class);
            }

        }

    ],

    'require' => ['application', 'migration']

];