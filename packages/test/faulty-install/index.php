<?php

/**
 * Test extension - Faulty Install
 * 
 * This extension has a working module definition but fails during install hook.
 */

return [
    'name' => 'test/faulty-install',
    
    'type' => 'extension',
    
    'main' => function ($app) {
        $app['log']->info('TEST: faulty-install - main() executed (should not reach if install fails)');
    },
    
    'config' => [
        'test_setting' => 'This extension will fail on install'
    ]
];
