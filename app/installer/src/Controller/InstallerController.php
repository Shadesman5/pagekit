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
        // Simple logging that always works (using relative path)
        $logFile = dirname(__DIR__, 3) . '/installer_debug.log';
        $log = "\n=== checkAction called at " . date('Y-m-d H:i:s') . " ===\n";
        $log .= "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n";
        $log .= "PHP: " . PHP_VERSION . "\n";
        file_put_contents($logFile, $log, FILE_APPEND);
        
        try {
            // Always get params from request directly
            $app = App::getInstance();
            $request = $app['request'];
            
            // Log ALL request details
            $debug = [
                'timestamp' => date('Y-m-d H:i:s'),
                'method' => $request->getMethod(),
                'uri' => $request->getRequestUri(),
                'content_type' => $request->headers->get('Content-Type'),
                'user_agent' => $request->headers->get('User-Agent'),
                'raw_content' => $request->getContent(),
                'content_length' => strlen($request->getContent()),
                'headers' => $request->headers->all()
            ];
            file_put_contents($logFile, "REQUEST DETAILS:\n" . print_r($debug, true), FILE_APPEND);
            
            $data = json_decode($request->getContent(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = "JSON decode error: " . json_last_error_msg();
                file_put_contents($logFile, "ERROR: $error\n", FILE_APPEND);
                throw new \Exception($error);
            }
            
            file_put_contents($logFile, "DECODED DATA:\n" . print_r($data, true), FILE_APPEND);
                
                // Handle both wrapped and unwrapped data
                if (isset($data['config'])) {
                    $config = $data['config'];
                    file_put_contents($logFile, "Using config from data[config]\n", FILE_APPEND);
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
                    file_put_contents($logFile, "Transformed flat structure to nested\n", FILE_APPEND);
                } else {
                    file_put_contents($logFile, "No config or database found in data\n", FILE_APPEND);
                    $config = [];
                }
            
            file_put_contents($logFile, "Final config:\n" . print_r($config, true), FILE_APPEND);
            file_put_contents($logFile, "Calling installer->check...\n", FILE_APPEND);
            
            $result = $this->installer->check($config);
            
            file_put_contents($logFile, "Result from installer->check:\n" . print_r($result, true), FILE_APPEND);
            
            return $result;
        } catch (\Throwable $e) {
            file_put_contents($logFile, "EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
            file_put_contents($logFile, "Stack trace:\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
            
            // Log to Apache/Nginx error log as well
            error_log("InstallerController::checkAction Exception: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            // Return a proper JSON error response
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'error' => true,
                'message' => 'Installation check failed: ' . $e->getMessage()
            ]);
            exit;
        }
    }

    public function installAction(): array
    {
        // Always get params from request directly
        $app = App::getInstance();
        $request = $app['request'];
        $data = json_decode($request->getContent(), true) ?: [];
        
        // Log the received data for debugging
        $logFile = dirname(__DIR__, 3) . '/installer_debug.log';
        file_put_contents($logFile, "\n=== installAction called ===\n", FILE_APPEND);
        file_put_contents($logFile, "Received data: " . json_encode($data, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
        
        // The frontend already sends the correct structure
        $config = $data['config'] ?? [];
        $option = $data['option'] ?? [];
        $user = $data['user'] ?? [];
        
        // Add locale if present
        if (isset($data['locale']) && !isset($config['locale'])) {
            $config['locale'] = $data['locale'];
        }
        
        file_put_contents($logFile, "Config: " . json_encode($config, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
        file_put_contents($logFile, "Option: " . json_encode($option, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
        file_put_contents($logFile, "User: " . json_encode($user, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
        
        try {
            return $this->installer->install($config, $option, $user);
        } catch (\Throwable $e) {
            file_put_contents($logFile, "Install ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
            file_put_contents($logFile, "Stack: " . $e->getTraceAsString() . "\n", FILE_APPEND);
            throw $e;
        }
    }

}
