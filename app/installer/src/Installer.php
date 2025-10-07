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

            try {

                if (!$this->config) {
                    foreach ($config as $name => $values) {
                        if ($module = $this->app->module($name)) {
                            $module->config = Arr::merge($module->config, $values);
                        } else {
                        }
                    }
                }

                $this->app->db()->connect();

                if ($this->app->db()->getUtility()->tableExists('@system_config')) {
                    $status = 'tables-exist';
                    $message = __('Existing Pagekit installation detected. Choose different table prefix?');
                } else {
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

            $message = $e->getMessage(); // Show the actual error for debugging
            
            if ($e->getCode() == 1045) {
                $message = __('Database access denied!');
            }
        }

        return ['status' => $status, 'message' => $message];
    }

    public function install($config = [], $option = [], $user = []): array
    {
        $status = $this->check($config);
        $message = $status['message'];
        $status = $status['status'];

        $demo_content =  false;
        if (isset($option['demo_content']) && $option['demo_content']) {
            $demo_content = true;
            unset($option['demo_content']);
        }

        try {

            if ('no-connection' == $status) {
                $this->app->abort(400, __('No database connection.'));
            }

            if ('tables-exist' == $status) {
                $this->app->abort(400, $message);
            }

            $scripts = new PackageScripts($this->app->path().'/app/system/scripts.php');
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

            try {
                $packageManager = new PackageManager(new NullOutput());
            } catch (\Exception $e) {
                throw new \Exception("Error creating PackageManager: " . $e->getMessage(), 0, $e);
            }
            
            foreach (glob($this->app['path.packages'] . '/*/*/composer.json') as $package) {
                try {
                    $package = $this->app['package']->load($package);
                } catch (\Exception $e) {
                    // Log package loading error and continue with next package
                    $this->app['log']->warning(
                        sprintf('Failed to load package from "%s": %s', basename(dirname($package)), $e->getMessage()),
                        ['exception' => $e]
                    );
                    continue;
                }
                if ($package->get('type') === 'pagekit-extension' || $package->get('type') === 'pagekit-theme') {
                    try {
                        $packageManager->enable($package);
                    } catch (\Exception $e) {
                        // Log the error but continue with other packages during installation
                        // The package will NOT be marked as enabled due to rollback in PackageManager
                        $this->app['log']->error(
                            sprintf('Failed to enable package "%s" during installation: %s', 
                                $package->get('name'), 
                                $e->getMessage()
                            ),
                            ['exception' => $e]
                        );
                        // Continue with next package - installation should not fail completely
                        // if one extension has issues
                    }
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

                $configuration->set('system.secret', $this->app['auth.random']->generateString(64));

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
