<?php

namespace Pagekit\Installer;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionException;
use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Installer\Package\PackageManager;
use Pagekit\Installer\Package\PackageScripts;
use Pagekit\Util\Arr;
use Symfony\Component\Console\Output\NullOutput;

class Installer
{

    protected string $configFile = 'config.php';


    /**
     * @var Application Pagekit Application instance
     */
    protected \Pagekit\Application $app;

    /**
     * @var bool
     */
    protected $config;

    public function __construct(Application $app)
    {
        $this->app = $app;

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        $this->config = file_exists($this->configFile);
    }

    public function check($config): array
    {
        $status = 'no-connection';
        $message = '';

        try {
            error_log("check(): Starting database check");

            try {

                if (!$this->config) {
                    error_log("check(): No config file, merging module configs");
                    foreach ($config as $name => $values) {
                        error_log("check(): Processing config for module: $name");
                        if ($module = $this->app->module($name)) {
                            error_log("check(): Module $name found, merging config");
                            $module->config = Arr::merge($module->config, $values);
                        } else {
                            error_log("check(): Module $name not found");
                        }
                    }
                }

                error_log("check(): Attempting database connection");
                $this->app->db()->connect();
                error_log("check(): Database connected successfully");

                error_log("check(): Checking if tables exist");
                if ($this->app->db()->getUtility()->tableExists('@system_config')) {
                    error_log("check(): Tables exist");
                    $status = 'tables-exist';
                    $message = __('Existing Pagekit installation detected. Choose different table prefix?');
                } else {
                    error_log("check(): No tables found");
                    $status = 'no-tables';
                }

            } catch (ConnectionException $e) {

                if ($e->getPrevious()->getCode() == 1049) {
                    $this->createDatabase();
                    $status = 'no-tables';
                } else {
                    throw $e;
                }
            }

        } catch (\Exception $e) {
            
            // Debug: Log the actual error
            error_log("Installer check() exception: " . $e->getMessage());
            error_log("Exception trace: " . $e->getTraceAsString());

            $message = $e->getMessage(); // Show the actual error for debugging
            
            if ($e->getCode() == 1045) {
                $message = __('Database access denied!');
            }
        }

        return ['status' => $status, 'message' => $message];
    }

    public function install($config = [], $option = [], $user = []): array
    {
        error_log("install(): Starting installation");
        $status = $this->check($config);
        error_log("install(): check() returned status: " . $status['status'] . ", message: " . $status['message']);
        $message = $status['message'];
        $status = $status['status'];

        $demo_content =  false;
        if (isset($option['demo_content']) && $option['demo_content']) {
            $demo_content = true;
            unset($option['demo_content']);
        }

        try {
            error_log("install(): Checking status: $status");

            if ('no-connection' == $status) {
                $this->app->abort(400, __('No database connection.'));
            }

            if ('tables-exist' == $status) {
                $this->app->abort(400, $message);
            }

            error_log("install(): Loading package scripts");
            $scripts = new PackageScripts($this->app->path().'/app/system/scripts.php');
            error_log("install(): Running install scripts");
            $scripts->install();

            $this->app->db()->insert('@system_user', [
                'name' => $user['username'],
                'username' => $user['username'],
                'password' => $this->app['auth.password']->hash($user['password']),
                'status' => 1,
                'email' => $user['email'],
                'registered' => date('Y-m-d H:i:s'),
                'roles' => '2,3'
            ]);

            $option['system']['version'] = $this->app->version();

            foreach ($option as $name => $values) {
                $this->app->config()->set($name, $this->app->config($name)->merge($values));
            }

            error_log("install(): Creating PackageManager");
            try {
                $packageManager = new PackageManager(new NullOutput());
            } catch (\Exception $e) {
                error_log("install(): Error creating PackageManager: " . $e->getMessage());
                throw new \Exception("Error creating PackageManager: " . $e->getMessage(), 0, $e);
            }
            
            error_log("install(): Looking for packages in: " . $this->app['path.packages']);
            foreach (glob($this->app['path.packages'] . '/*/*/composer.json') as $package) {
                error_log("install(): Loading package: $package");
                $package = $this->app['package']->load($package);
                if ($package->get('type') === 'pagekit-extension' || $package->get('type') === 'pagekit-theme') {
                    error_log("install(): Enabling package: " . $package->getName());
                    $packageManager->enable($package);
                }
            }

            if (!$demo_content) {
                if (file_exists(__DIR__.'/../install.php')) {
                    require_once __DIR__.'/../install.php';
                }
            } elseif (file_exists(__DIR__.'/../install-demo.php')) {
                require_once __DIR__.'/../install-demo.php';
            }

            if (!$this->config) {

                $configuration = new Config();
                $configuration->set('application.debug', false);

                foreach ($config as $key => $value) {
                    $configuration->set($key, $value);
                }

                $configuration->set('system.secret', $this->app->get('auth.random')->generateString(64));

                if (!file_put_contents($this->configFile, $configuration->dump())) {

                    $status = 'write-failed';

                    $this->app->abort(400, __('Can\'t write config.'));
                }
            }

            $this->app->module('system/cache')->clearCache();

            $status = 'success';

        } catch (DBALException $e) {

            $status = 'db-sql-failed';
            $message = __('Database error: %error%', ['%error%' => $e->getMessage()]);

        } catch (\Exception $e) {

            $message = $e->getMessage();

        }

        return ['status' => $status, 'message' => $message];
    }

    protected function createDatabase(): void
    {
        $module = $this->app->module('database');
        $params = $module->config('connections')[$module->config('default')];

        $name = $params['dbname'];
        unset($params['dbname']);

        $db = DriverManager::getConnection($params);
        $db->getSchemaManager()->createDatabase($db->quoteIdentifier($name));
        $db->close();
    }

}
