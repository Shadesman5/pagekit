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
        $request = $this->app['request'];
        $data = json_decode($request->getContent(), true) ?: [];
        
        // Extract config from request data
        $config = [
            'host' => $data['host'] ?? '',
            'user' => $data['user'] ?? '',
            'password' => $data['password'] ?? '',
            'dbname' => $data['dbname'] ?? '',
            'prefix' => $data['prefix'] ?? 'pk_',
            'locale' => $data['locale'] ?? 'en_GB',
            'database' => $data['database'] ?? 'mysql'
        ];
        
        return $this->installer->check($config);
    }

    public function installAction(): array
    {
        $request = $this->app['request'];
        $data = json_decode($request->getContent(), true) ?: [];
        
        // Extract database config
        $config = [];
        $configKeys = ['host', 'user', 'password', 'dbname', 'prefix', 'locale', 'database'];
        foreach ($configKeys as $key) {
            if (isset($data[$key])) {
                $config[$key] = $data[$key];
            }
        }
        
        // Extract options
        $option = [];
        $optionKeys = ['title'];
        foreach ($optionKeys as $key) {
            if (isset($data[$key])) {
                $option[$key] = $data[$key];
            }
        }
        
        // Extract user data
        $user = [];
        $userKeys = ['username', 'password', 'email'];
        foreach ($userKeys as $key) {
            if (isset($data[$key])) {
                $user[$key] = $data[$key];
            }
        }
        
        return $this->installer->install($config, $option, $user);
    }

}
