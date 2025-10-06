<?php

/**
 * Test extension that deliberately fails during enable hook.
 * 
 * Expected behavior:
 * - BEFORE FIX: Extension is added to enabled list, then error occurs
 * - AFTER FIX: Enable fails, extension is NOT added to enabled list, rollback occurs
 */

return [
    'install' => [
        function ($app) {
            $app['log']->info('TEST: faulty-enable - install hook executed successfully');
        }
    ],
    
    'enable' => [
        function ($app) {
            $app['log']->info('TEST: faulty-enable - enable hook started');
            
            // Deliberately throw exception
            throw new \RuntimeException('TEST ERROR: Enable hook deliberately failed to test error handling');
        }
    ],
    
    'disable' => [
        function ($app) {
            $app['log']->info('TEST: faulty-enable - disable hook executed');
        }
    ],
    
    'uninstall' => [
        function ($app) {
            $app['log']->info('TEST: faulty-enable - uninstall hook executed');
        }
    ]
];
