<?php

declare(strict_types=1);

namespace Pagekit\Installer\Controller;

use Pagekit\Application as App;
use Pagekit\Installer\Package\PackageManager;
use Pagekit\Routing\Attribute\Request;
use Pagekit\User\Attribute\Access;

#[Access('system: manage packages', admin: true)]
class PackageController
{
    protected PackageManager $manager;

    public function __construct()
    {
        $this->manager = new PackageManager();
    }

    public function themesAction(): array
    {
        $packages = array_values(App::package()->all('pagekit-theme'));

        foreach ($packages as $package) {
            if ($module = App::module($package->get('module'))) {

                if ($settings = $module->get('settings') and $settings[0] === '@') {
                    $settings = App::url($settings);
                }

                $package->set('enabled', true);
                $package->set('settings', $settings);
                $package->set('config', $module->config);
            }
        }

        return [
            '$view' => [
                'title' => __('Themes'),
                'name' => 'installer:views/themes.php'
            ],
            '$data' => [
                'api' => App::getInstance() ? App::getInstance()['system.api'] : 'https://pagekit.com',
                'packages' => $packages
            ]
        ];
    }

    public function extensionsAction(): array
    {
        $packages = array_values(App::package()->all('pagekit-extension'));

        foreach ($packages as $package) {
            if ($module = App::module($package->get('module'))) {

                if ($settings = $module->get('settings') and $settings[0] === '@') {
                    $settings = App::url($settings);
                }

                $package->set('enabled', true);
                $package->set('settings', $settings);
                $package->set('config', $module->config);
                $package->set('permissions', (bool) $module->get('permissions'));
            }
        }

        return [
            '$view' => [
                'title' => __('Extensions'),
                'name' => 'installer:views/extensions.php'
            ],
            '$data' => [
                'api' => App::getInstance() ? App::getInstance()['system.api'] : 'https://pagekit.com',
                'packages' => $packages
            ]
        ];
    }

    #[Request(['name' => 'string'])]
    public function enableAction($name): array
    {
        $handler = $this->errorHandler($name);

        try {
            if (!$package = App::package($name)) {
                App::abort(400, __('Unable to find "%name%".', ['%name%' => $name]));
            }

            App::module()->load($package->get('module'));

            if (!$module = App::module($package->get('module'))) {
                App::abort(400, __('Unable to enable "%name%".', ['%name%' => $package->get('title')]));
            }

            $this->manager->enable($package);
            
            // Clear cache only on successful enable
            App::module('system/cache')->clearCache();

            return ['message' => 'success'];
            
        } catch (\Throwable $e) {
            // Log the error
            App::log('error', sprintf(
                'Failed to enable extension "%s": %s',
                $name,
                $e->getMessage()
            ), ['exception' => $e]);
            
            // Return error to UI
            // In debug mode, show full error; otherwise show generic message
            $errorMessage = App::debug() 
                ? sprintf('%s', $e->getMessage())
                : __('Unable to enable "%name%". See error log for details.', ['%name%' => $name]);
            
            return ['error' => $errorMessage];
            
        } finally {
            // Restore original error handlers
            if ($handler) {
                $handler();
            }
        }
    }

    #[Request(['name' => 'string'])]
    public function disableAction($name): array
    {
        if (!$package = App::package($name)) {
            App::abort(400, __('Unable to find "%name%".', ['%name%' => $name]));
        }

        if (!$module = App::module($package->get('module'))) {
            App::abort(400, __('"%name%" has not been loaded.', ['%name%' => $package->get('title')]));
        }

        $this->manager->disable($package);

        App::module('system/cache')->clearCache();

        return ['message' => 'success'];
    }

    #[Request(['type' => 'string'])]
    public function uploadAction($type): array
    {
        $file = App::request()->files->get('file');

        if ($file === null || !$file->isValid()) {
            App::abort(400, __('No file uploaded.'));
        }

        $package = $this->loadPackage($file->getPathname());

        if (!$package->getName() || !$package->get('title') || !$package->get('version')) {
            App::abort(400, __('"composer.json" file not valid.'));
        }

        if ($package->get('type') !== 'pagekit-' . $type) {
            App::abort(400, __('No Pagekit %type%', ['%type%' => $type]));
        }

        $filename = str_replace('/', '-', $package->getName()) . '-' . $package->get('version') . '.zip';

        $path = App::getInstance() ? App::getInstance()['path'] : realpath(__DIR__ . '/../../../..');
        $file->move($path . '/tmp/packages', $filename);

        return compact('package');
    }

    #[Request(['package' => 'array', 'packagist' => 'boolean'])]
    public function installAction($package = [], $packagist = false)
    {

        // TODO
        $file = App::path().'/tmp/temp/composer/composer.json';

        if (!file_exists(dirname($file))) {
            mkdir(dirname($file), 0755, true);
            file_put_contents($file, '{}');
        }

        return App::response()->stream(function () use ($package, $packagist) {

            try {

                $package = App::package()->load($package);

                if (!$package) {
                    throw new \RuntimeException('Invalid parameters.');
                }

                $this->manager->install([(string) $package->getName() => $package->get('version')], $packagist);

                echo "\nstatus=success";

            } catch (\Exception $e) {

                printf("%s\nstatus=error", $e->getMessage());
            }

        });
    }

    #[Request(['name' => 'string'])]
    public function uninstallAction($name)
    {
        return App::response()->stream(function () use ($name) {

            try {

                $this->manager->uninstall($name);

                echo "\nstatus=success";

            } catch (\Exception $e) {

                printf("%s\nstatus=error", $e->getMessage());
            }

        });
    }

    protected function loadPackage($file)
    {
        if (is_file($file)) {

            $zip = new \ZipArchive;

            if ($zip->open($file) === true) {
                $json = $zip->getFromName('composer.json');

                if ($json && $package = App::package()->load($json)) {
                    $extra = $package->get('extra');

                    if (isset($extra['icon']) || isset($extra['image'])) {
                        unset($extra['icon']);
                        unset($extra['image']);
                        $package->set('extra', $extra);
                    }

                    $package->set('shasum', sha1_file($file));
                }

                $zip->close();
            }
        }

        if (isset($package) && $package) {
            return $package;
        }

        App::abort(400, __('Can\'t load json file from package.'));
    }

    protected function errorHandler($name): ?callable
    {
        // Store original error reporting level
        $originalErrorReporting = error_reporting();
        
        // Disable error display temporarily
        ini_set('display_errors', 0);
        
        // Set error handler that converts errors to exceptions
        $originalErrorHandler = set_error_handler(function ($severity, $message, $file, $line) use ($name) {
            // Only handle errors that would normally be fatal
            if ($severity & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR)) {
                // Clean output buffer
                while (ob_get_level()) {
                    ob_get_clean();
                }

                $errorMessage = __('Unable to activate "%name%".<br>A fatal error occured.', ['%name%' => $name]);
                
                if (App::debug()) {
                    $errorMessage .= '<br><br>' . sprintf('%s in %s on line %d', $message, $file, $line);
                }

                // Send JSON response
                App::response()->json($errorMessage, 500)->send();
                exit;
            }
            
            // For other errors, return false to let PHP handle them normally
            return false;
        });

        // Set exception handler for uncaught exceptions
        $originalExceptionHandler = set_exception_handler(function ($exception) use ($name) {
            while (ob_get_level()) {
                ob_get_clean();
            }

            $message = __('Unable to activate "%name%".<br>A fatal error occured.', ['%name%' => $name]);

            if (App::debug()) {
                $message .= '<br><br>' . $exception->getMessage();
            }

            App::response()->json($message, 500)->send();
            exit;
        });

        // Return a function to restore original handlers
        return function () use ($originalErrorHandler, $originalExceptionHandler, $originalErrorReporting) {
            if ($originalErrorHandler !== null) {
                set_error_handler($originalErrorHandler);
            }
            if ($originalExceptionHandler !== null) {
                set_exception_handler($originalExceptionHandler);
            }
            error_reporting($originalErrorReporting);
            ini_set('display_errors', 1);
        };
    }
}
