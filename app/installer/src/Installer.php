<?php

declare(strict_types=1);

namespace Pagekit\Installer;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionException;
use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Installer\Package\Lifecycle\LifecycleRunner;
use Pagekit\Installer\Package\PackageManager;
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
            // NOTE: the system install hook is executed AFTER migrations
            $lifecycle = new LifecycleRunner($this->app->get('path').'/app/system/scripts.php', null, $this->app);
            $lifecycle->install();

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

                // The installer form sends only what the user typed (e.g. SQLite
                // prefix). Fill in scalar connection defaults (driver, path, …)
                // from the merged module config — never dump closures such as
                // SQLite user-defined functions into config.php.
                if (isset($config['database']) && is_array($config['database'])) {
                    $database = $this->app->get('module')->get('database');
                    if (is_object($database) && isset($database->config) && is_array($database->config)) {
                        $configuration->set(
                            'database',
                            $this->persistableDatabaseConfig($config['database'], $database->config)
                        );
                    }
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

        } catch (BadRequestHttpException $e) {

            $message = $e->getMessage();

        } catch (DBALException $e) {

            $status = 'db-sql-failed';
            $message = __('Database error: %error%', ['%error%' => $e->getMessage()]);

        } catch (\Exception $e) {

            // Keep check() outcomes (no-connection / tables-exist); only mark a
            // failure that happened after the connection was accepted.
            if ($status === 'no-tables') {
                $status = 'failed';
            }

            $message = $this->formatInstallError($e);

        }

        return ['status' => $status, 'message' => $message];
    }

    /**
     * Builds a user-facing install error from a throwable chain.
     *
     * Container lookups wrap the real fault as "Error while retrieving …";
     * the installer must surface the previous messages or the person installing
     * only sees the wrapper.
     */
    private function formatInstallError(\Throwable $e): string
    {
        $parts = [];
        $current = $e;

        while ($current !== null) {
            $part = trim($current->getMessage());

            if ($part !== '' && !in_array($part, $parts, true)) {
                $parts[] = $part;
            }

            $current = $current->getPrevious();
        }

        return $parts !== [] ? implode(': ', $parts) : __('Installation failed.');
    }

    /**
     * Merges form database settings with resolved scalar defaults for config.php.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $resolved
     *
     * @return array<string, mixed>
     */
    private function persistableDatabaseConfig(array $form, array $resolved): array
    {
        $out = $form;

        if (isset($resolved['default']) && is_string($resolved['default'])) {
            $out['default'] = $resolved['default'];
        }

        $keys = ['driver', 'path', 'dbname', 'host', 'port', 'user', 'password', 'prefix', 'charset', 'collate', 'engine'];

        foreach ($out['connections'] ?? [] as $name => $params) {
            if (!is_array($params)) {
                continue;
            }

            $resolvedParams = $resolved['connections'][$name] ?? [];
            if (!is_array($resolvedParams)) {
                continue;
            }

            foreach ($keys as $key) {
                if (array_key_exists($key, $params)) {
                    continue;
                }

                if (!array_key_exists($key, $resolvedParams)) {
                    continue;
                }

                $value = $resolvedParams[$key];
                if (is_scalar($value) || $value === null) {
                    $out['connections'][$name][$key] = $value;
                }
            }
        }

        return $out;
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
