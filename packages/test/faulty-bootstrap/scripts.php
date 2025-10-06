<?php

/**
 * Test extension with normal scripts.
 * The error happens in the main() function, not in scripts.
 */

return [
    'install' => [
        function ($app) {
            $app['log']->info('TEST: faulty-bootstrap - install hook executed successfully');
        }
    ],
    
    'enable' => [
        function ($app) {
            $app['log']->info('TEST: faulty-bootstrap - enable hook executed successfully');
        }
    ],
    
    'disable' => [
        function ($app) {
            $app['log']->info('TEST: faulty-bootstrap - disable hook executed');
        }
    ]
];
