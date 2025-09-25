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
     * @Route("/check", methods={"POST"})
     */
    public function checkAction(): array
    {
        // Log that we reached the controller
        file_put_contents('/workspace/installer_debug.log', "=== checkAction called ===\n", FILE_APPEND);
        
        try {
            // Always get params from request directly
            $app = App::getInstance();
            $request = $app['request'];
                
                // Debug logging to file
                $debug = [
                    'method' => $request->getMethod(),
                    'content_type' => $request->headers->get('Content-Type'),
                    'raw_content' => $request->getContent(),
                    'headers' => $request->headers->all()
                ];
                file_put_contents('/workspace/installer_debug.log', print_r($debug, true), FILE_APPEND);
                
                $data = json_decode($request->getContent(), true) ?: [];
                
                error_log('Decoded data: ' . json_encode($data));
                
                // Handle both wrapped and unwrapped data
                if (isset($data['config'])) {
                    $config = $data['config'];
                    error_log('Using config from data[config]');
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
                    error_log('Transformed flat structure to nested');
                } else {
                    error_log('No config or database found in data');
                    $config = [];
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
     * @Route("/install", methods={"POST"})
     */
    public function installAction(): array
    {
        // Always get params from request directly
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
        
        return $this->installer->install($config, $option, $user);
    }

}
