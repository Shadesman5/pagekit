<?php

declare(strict_types=1);

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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class Installer
{
    protected string $configFile;


    /**
     * @var Application Pagekit Application instance
     */
    protected \Pagekit\Application $app;

    protected bool $config;

    public function __construct(Application $app)
    {
        $this->app = $app;

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        // The configuration belongs next to the application, not in the webroot
        // the front controller happens to run from.
        $this->configFile = $app->get('path').'/config.php';
        $this->config = file_exists($this->configFile);
    }

    /**
     * @param  array<string, mixed> $config
     * @return array<string, string>
     */
    public function check(array $config): array
    {
        $status = 'no-connection';
        $message = '';

        try {

            try {

                if (!$this->config) {
                    foreach ($config as $name => $values) {
                        if ($module = $this->app->get('module')->get($name)) {
                            $module->config = Arr::merge($module->config, $values);
                        } else {
                        }
                    }
                }

                $this->app->get('db')->connect();

                if ($this->app->get('db')->getUtility()->tableExists('@system_config')) {
                    $status = 'tables-exist';
                    $message = __('Existing Pagekit installation detected. Choose different table prefix?');
                } else {
                    $status = 'no-tables';
                }

            } catch (ConnectionException $e) {

                if ($e->getPrevious() !== null && $e->getPrevious()->getCode() == 1049) {
                    $this->createDatabase();
                    $status = 'no-tables';
                } else {
                    throw $e;
                }
            }

        } catch (\Exception $e) {

            $message = $e->getMessage();

            if ($e->getCode() == 1045) {
                $message = __('Database access denied!');
            }
        }

        return ['status' => $status, 'message' => $message];
    }

    /**
     * @param  array<string, mixed> $config
     * @param  array<string, mixed> $option
     * @param  array<string, mixed> $user
     * @return array<string, string>
     */
    public function install(array $config = [], array $option = [], array $user = []): array
    {
        $status = $this->check($config);
        $message = $status['message'];
        $status = $status['status'];

        $demo_content = false;
        if (isset($option['demo_content']) && $option['demo_content']) {
            $demo_content = true;
            unset($option['demo_content']);
        }

        try {

            if ('no-connection' == $status) {
                // Whatever check() has to say about it is the whole of what there
                // is to go on: the file SQLite could not open, the host that
                // refused. Answering with the sentence alone leaves an
                // installation that failed without saying what of, and a
                // container that ended its start with nothing in its log but the
                // sentence.
                throw new BadRequestHttpException(
                    '' !== $message
                        ? __('No database connection: %error%', ['%error%' => $message])
                        : __('No database connection.')
                );
            }

            if ('tables-exist' == $status) {
                throw new BadRequestHttpException($message);
            }

            // Execute database migrations to create schema
            $this->runMigrations();

            // Execute additional setup (config initialization, etc.)
            // NOTE: scripts.php 'install' hook is executed AFTER migrations
            $scripts = new PackageScripts($this->app->get('path').'/app/system/scripts.php', null, $this->app);
            $scripts->install();

            $this->app->get('db')->insert('@system_user', [
                'name' => $user['username'],
                'username' => $user['username'],
                'password' => $this->app->get('auth.password')->hash($user['password']),
                'status' => 1,
                'email' => $user['email'],
                'registered' => date('Y-m-d H:i:s'),
                'roles' => '2,3',
            ]);

            $option['system']['version'] = $this->app->get('version');

            foreach ($option as $name => $values) {
                $this->app->get('config')->set($name, $this->app->get('config')($name)->merge($values));
            }

            try {
                $packageManager = new PackageManager($this->app, new NullOutput());
            } catch (\Exception $e) {
                throw new \Exception("Error creating PackageManager: " . $e->getMessage(), 0, $e);
            }

            foreach (glob($this->app->get('path.packages') . '/*/*/composer.json') ?: [] as $package) {
                try {
                    $package = $this->app->get('package')->load($package);
                } catch (\Exception $e) {
                    // Log package loading error and continue with next package
                    $this->app->get('log')->warning(
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
                        $this->app->get('log')->error(
                            sprintf(
                                'Failed to enable package "%s" during installation: %s',
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

            $this->runContentScript(__DIR__.'/../'.($demo_content ? 'install-demo.php' : 'install.php'), $user);

            if (!$this->config) {

                $configuration = new Config();
                $configuration->set('application.debug', false);

                foreach ($config as $key => $value) {
                    $configuration->set($key, $value);
                }

                $configuration->set('system.secret', bin2hex(random_bytes(32)));

                try {
                    $this->app->get('file')->dumpAtomic($this->configFile, $configuration->dump());
                } catch (\RuntimeException $e) {

                    $status = 'write-failed';

                    throw new BadRequestHttpException(__('Can\'t write config.'), $e);
                }
            }

            $this->linkStorage();

            $this->app->get('module')->get('system/cache')->clearCache();

            $status = 'success';

        } catch (DBALException $e) {

            $status = 'db-sql-failed';
            $message = __('Database error: %error%', ['%error%' => $e->getMessage()]);

        } catch (\Exception $e) {

            $message = $e->getMessage();

        }

        return ['status' => $status, 'message' => $message];
    }

    /**
     * Fills a fresh installation with its initial content.
     *
     * The script is included rather than called, and declares variables of its
     * own - $db and $config among them. A scope of its own is what keeps those
     * from landing in the caller's, whose $config is the configuration still to
     * be written to config.php. What the script may read is therefore only what
     * this scope hands it: the application as $app and, because the demo content
     * signs one of its comments as the site owner, that account as $user. A
     * second call cannot insert the content twice.
     *
     * @param array<string, mixed> $user The administrator the installation created.
     */
    protected function runContentScript(string $file, array $user): void
    {
        if (!file_exists($file)) {
            return;
        }

        $app = $this->app;

        require_once $file;
    }

    protected function createDatabase(): void
    {
        $module = $this->app->get('module')->get('database');
        $params = $module->config('connections')[$module->config('default')];

        $name = $params['dbname'];
        unset($params['dbname']);

        $db = DriverManager::getConnection($params);
        $db->createSchemaManager()->createDatabase($db->quoteIdentifier($name));
        $db->close();
    }

    /**
     * Run database migrations
     *
     * Executes Doctrine Migrations to create the database schema.
     * This is the modern approach replacing the legacy scripts.php method.
     *
     * @throws \Exception If migration fails
     */
    protected function runMigrations(): void
    {
        /** @var \Pagekit\Migration\MigrationService $migrationService */
        $migrationService = $this->app->get('migration');

        // Initialize migration system if needed
        if (!$migrationService->isInitialized()) {
            $initResult = $migrationService->initialize();

            if (!$initResult['success']) {
                throw new \RuntimeException(
                    'Failed to initialize migration system: ' . ($initResult['error'] ?? 'Unknown error')
                );
            }
        }

        // Execute all migrations to create schema
        $result = $migrationService->migrate();

        if (!$result['success']) {
            throw new \RuntimeException(
                'Migration failed: ' . ($result['error'] ?? 'Unknown error')
            );
        }
    }

    /**
     * Link the media library into the webroot.
     *
     * An installation unpacked from an archive starts without that link. Where it
     * cannot be created the media loses its URLs while the site itself works, so
     * the problem is reported instead of failing the installation.
     */
    protected function linkStorage(): void
    {
        $link = new StorageLink(
            $this->app->get('path'),
            $this->app->get('path.public'),
            $this->app->get('path.storage')
        );

        if (!$link->ensure()) {
            $this->app->get('log')->warning($link->getProblem());
        }
    }

}
