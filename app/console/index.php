<?php

return [

    'name' => 'console',

    'autoload' => [

        'Pagekit\\Console\\' => 'src'

    ],

    'events' => [

        // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal + DI Final)
        // Commands are instantiated with parameterless new $class. Once StaticTrait is removed,
        // commands should use constructor injection via a resolver (like ControllerResolver).
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