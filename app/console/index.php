<?php

return [

    'name' => 'console',

    'autoload' => [

        'Pagekit\\Console\\' => 'src'

    ],

    'events' => [

        'console.init' => function ($event, $console) {

            $namespace = 'Pagekit\\Console\\Commands\\';

            foreach (glob(__DIR__ . '/src/Commands/*Command.php') as $file) {
                $class = $namespace . basename($file, '.php');
                $console->add(new $class);
            }

            foreach (glob(__DIR__ . '/src/Commands/Migration/*Command.php') as $file) {
                $class = $namespace . 'Migration\\' . basename($file, '.php');
                $console->add(new $class);
            }

        }

    ],

    'require' => ['application', 'migration']

];