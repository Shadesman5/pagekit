<?php

/**
 * Test extension - Faulty Bootstrap
 * 
 * This extension fails during main() execution (module bootstrap).
 * This simulates a common scenario: module loads fine initially, but when
 * actually enabled and loaded, it throws an exception.
 */

return [
    'name' => 'test/faulty-bootstrap',
    
    'type' => 'extension',
    
    'main' => function ($app) {
        // This will be called when the module is loaded via App::module()->load()
        $app['log']->info('TEST: faulty-bootstrap - main() started');
        
        // Simulate a common error: calling undefined function
        // This represents missing dependencies, class not found, etc.
        if (!function_exists('this_function_does_not_exist_for_testing')) {
            throw new \RuntimeException('TEST ERROR: Required function not found - simulating bootstrap failure');
        }
        
        // This line should never be reached
        this_function_does_not_exist_for_testing();
    },
    
    'config' => [
        'test_setting' => 'This extension will fail on bootstrap'
    ]
];
