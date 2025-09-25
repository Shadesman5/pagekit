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

    /**
     * @Request({"config": "array"})
     */
    public function checkAction($config = []): array
    {
        try {
            // Fallback if annotation doesn't work
            if (empty($config)) {
                $app = App::getInstance();
                $request = $app['request'];
                $data = json_decode($request->getContent(), true) ?: [];
                
                // Handle both wrapped and unwrapped data
                if (isset($data['config'])) {
                    $config = $data['config'];
                } else {
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
                }
            }
            
            return $this->installer->check($config);
        } catch (\Throwable $e) {
            // Return error for debugging
            return [
                'error' => true,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }

    /**
     * @Request({"config": "array", "option": "array", "user": "array"})
     */
    public function installAction($config = [], $option = [], $user = []): array
    {
        // Fallback if annotation doesn't work
        if (empty($config) && empty($option) && empty($user)) {
            $app = App::getInstance();
            $request = $app['request'];
            $data = json_decode($request->getContent(), true) ?: [];
            
            // Extract database config
            $database = $data['database'] ?? 'mysql';
            $dbConfig = [
                'host' => $data['host'] ?? '',
                'user' => $data['user'] ?? '',
                'password' => $data['password'] ?? '',
                'dbname' => $data['dbname'] ?? '',
                'prefix' => $data['prefix'] ?? 'pk_'
            ];
            
            $config = [
                'database' => [
                    'default' => $database,
                    'connections' => [
                        $database => $dbConfig
                    ]
                ]
            ];
            
            if (isset($data['locale'])) {
                $config['locale'] = $data['locale'];
            }
            
            // Extract options
            $option = [
                'title' => $data['title'] ?? 'Pagekit'
            ];
            
            // Extract user data
            $user = [
                'username' => $data['username'] ?? '',
                'password' => $data['password'] ?? '',
                'email' => $data['email'] ?? ''
            ];
        }
        
        return $this->installer->install($config, $option, $user);
    }

}
