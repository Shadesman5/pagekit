<?php

/**
 * Test extension - Faulty Enable
 * 
 * This extension has a working module definition but fails during enable hook.
 */

return [
    'name' => 'test/faulty-enable',
    
    'type' => 'extension',
    
    'main' => function ($app) {
        // Main bootstrap - this executes when module is loaded
        $app['log']->info('TEST: faulty-enable - main() executed, module loaded');
    },
    
    'autoload' => [
        'Pagekit\\FaultyEnable\\' => 'src'
    ],
    
    'config' => [
        'test_setting' => 'This extension will fail on enable'
    ]
];
