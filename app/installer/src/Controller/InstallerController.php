<?php

namespace Pagekit\Installer\Controller;

use Pagekit\Application as App;
use Pagekit\Installer\Installer;

class InstallerController
{
    protected Installer $installer;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $app = App::getInstance();
        $this->installer = new Installer($app);
    }

    public function indexAction(): array
    {
        $intl = App::module('system/intl');

        return [
            '$view' => [
                'title' => __('Pagekit Installer'),
                'name' => 'app/installer/views/installer.php',
            ],
            '$installer' => [
                'locale' => $intl->getLocale(),
                'locales' => $intl->getAvailableLanguages(),
                'sqlite' => class_exists('SQLite3') || (class_exists('PDO') && in_array('sqlite', \PDO::getAvailableDrivers(), true))
            ]
        ];
    }

    public function checkAction(): array
    {
        
        try {
            // Always get params from request directly
            $app = App::getInstance();
            $request = $app['request'];
            
            $data = json_decode($request->getContent(), true);
                
                // Handle both wrapped and unwrapped data
                if (isset($data['config'])) {
                    $config = $data['config'];
                } else if (isset($data['database'])) {
                    // Convert flat structure to expected nested structure
                    $database = $data['database'] ?? 'mysql';
                    unset($data['database']);
                    
                    $config = [
                        'database' => [
                            'default' => $database,
                            'connections' => [
                                $database => $data
                            ]
                        ]
                    ];
                    
                    if (isset($data['locale'])) {
                        $config['locale'] = $data['locale'];
                    }
                } else {
                    $config = [];
                }
            
            return $this->installer->check($config);
        } catch (\Throwable $e) {
            // Return error for debugging
            return [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }
    }

    public function installAction(): array
    {
        // Always get params from request directly
        $app = App::getInstance();
        $request = $app['request'];
        $data = json_decode($request->getContent(), true) ?: [];
        
        // The frontend already sends the correct structure
        $config = $data['config'] ?? [];
        $option = $data['option'] ?? [];
        $user = $data['user'] ?? [];
        
        // Add locale if present
        if (isset($data['locale']) && !isset($config['locale'])) {
            $config['locale'] = $data['locale'];
        }
        
        return $this->installer->install($config, $option, $user);
    }

}
