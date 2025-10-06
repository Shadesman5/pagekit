<?php

/**
 * Test extension that deliberately fails during install hook.
 * 
 * Expected behavior:
 * - BEFORE FIX: Extension might be marked as partially installed
 * - AFTER FIX: Install fails cleanly, no config changes persist
 */

return [
    'install' => [
        function ($app) {
            $app['log']->info('TEST: faulty-install - install hook started');
            
            // Deliberately throw exception during installation
            throw new \RuntimeException('TEST ERROR: Install hook deliberately failed to test error handling');
        }
    ],
    
    'enable' => [
        function ($app) {
            // This should never be called if install fails
            $app['log']->warning('TEST: faulty-install - enable hook called (should not happen!)');
        }
    ]
];
