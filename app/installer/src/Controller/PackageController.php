<?php

declare(strict_types=1);

namespace Pagekit\Installer\Controller;

use Pagekit\Application as App;
use Pagekit\Installer\Package\PackageManager;
use Pagekit\Routing\Attribute\Request;
use Pagekit\User\Attribute\Access;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[Access('system: manage packages', admin: true)]
class PackageController
{
    protected PackageManager $manager;

    public function __construct(
        private readonly mixed $package, // TODO: Must be refactored in Step 2.1.4 (PHPStan Level 5→6)
        private readonly mixed $module, // TODO: Must be refactored in Step 2.1.4 (PHPStan Level 5→6)
        private readonly mixed $url, // TODO: Must be refactored in Step 2.1.4 (PHPStan Level 5→6)
        private readonly mixed $request, // TODO: Must be refactored in Step 2.1.4 (PHPStan Level 5→6)
        private readonly mixed $response, // TODO: Must be refactored in Step 2.1.4 (PHPStan Level 5→6)
        private readonly mixed $path, // TODO: Must be refactored in Step 2.1.4 (PHPStan Level 5→6)
        private readonly bool $debug,
        private readonly mixed $log,
    ) {
        $this->manager = new PackageManager();
    }

    public function themesAction(): array
    {
        $packages = array_values($this->package->all('pagekit-theme'));

        foreach ($packages as $package) {
            if ($module = $this->module->get($package->get('module'))) {

                if ($settings = $module->get('settings') and $settings[0] === '@') {
                    $settings = $this->url->get($settings);
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
                'api' => App::getInstance() ? App::getInstance()->get('system.api') : 'https://pagekit.com', // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
                'packages' => $packages
            ]
        ];
    }

    public function extensionsAction(): array
    {
        $packages = array_values($this->package->all('pagekit-extension'));

        foreach ($packages as $package) {
            if ($module = $this->module->get($package->get('module'))) {

                if ($settings = $module->get('settings') and $settings[0] === '@') {
                    $settings = $this->url->get($settings);
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
                'api' => App::getInstance() ? App::getInstance()->get('system.api') : 'https://pagekit.com', // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
                'packages' => $packages
            ]
        ];
    }

    #[Request(['name' => 'string'], csrf: true)]
    public function enableAction($name): array
    {
        $handler = $this->errorHandler($name);

        try {
            if (!$package = $this->package->get($name)) {
                throw new BadRequestHttpException(__('Unable to find "%name%".', ['%name%' => $name]));
            }

            $this->module->load($package->get('module'));

            if (!$module = $this->module->get($package->get('module'))) {
                throw new BadRequestHttpException(__('Unable to enable "%name%".', ['%name%' => $package->get('title')]));
            }

            $this->manager->enable($package);

            $this->module->get('system/cache')->clearCache();

            return ['message' => 'success'];

        } catch (\Throwable $e) {
            $this->log->error(sprintf(
                'Failed to enable extension "%s": %s',
                $name,
                $e->getMessage()
            ), ['exception' => $e]);

            $errorMessage = $this->debug
                ? sprintf('%s', $e->getMessage())
                : __('Unable to enable "%name%". See error log for details.', ['%name%' => $name]);

            return ['error' => $errorMessage];

        } finally {
            if ($handler) {
                $handler();
            }
        }
    }

    #[Request(['name' => 'string'], csrf: true)]
    public function disableAction($name): array
    {
        if (!$package = $this->package->get($name)) {
            throw new BadRequestHttpException(__('Unable to find "%name%".', ['%name%' => $name]));
        }

        if (!$module = $this->module->get($package->get('module'))) {
            throw new BadRequestHttpException(__('"%name%" has not been loaded.', ['%name%' => $package->get('title')]));
        }

        $this->manager->disable($package);

        $this->module->get('system/cache')->clearCache();

        return ['message' => 'success'];
    }

    #[Request(['type' => 'string'], csrf: true)]
    public function uploadAction($type): array
    {
        $file = $this->request->files->get('file');

        if ($file === null || !$file->isValid()) {
            throw new BadRequestHttpException(__('No file uploaded.'));
        }

        $package = $this->loadPackage($file->getPathname());

        if (!$package->getName() || !$package->get('title') || !$package->get('version')) {
            throw new BadRequestHttpException(__('"composer.json" file not valid.'));
        }

        if ($package->get('type') !== 'pagekit-' . $type) {
            throw new BadRequestHttpException(__('No Pagekit %type%', ['%type%' => $type]));
        }

        $filename = str_replace('/', '-', $package->getName()) . '-' . $package->get('version') . '.zip';

        $file->move($this->path . '/tmp/packages', $filename);

        return compact('package');
    }

    #[Request(['package' => 'array', 'packagist' => 'boolean'], csrf: true)]
    public function installAction($package = [], $packagist = false)
    {
        $file = $this->path . '/tmp/temp/composer/composer.json';

        if (!file_exists(dirname($file))) {
            mkdir(dirname($file), 0755, true);
            file_put_contents($file, '{}');
        }

        return $this->response->stream(function () use ($package, $packagist) {

            try {

                $package = $this->package->load($package);

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

    #[Request(['name' => 'string'], csrf: true)]
    public function uninstallAction($name)
    {
        return $this->response->stream(function () use ($name) {

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

                if ($json && $package = $this->package->load($json)) {
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

        throw new BadRequestHttpException(__('Can\'t load json file from package.'));
    }

    protected function errorHandler($name): ?callable
    {
        $originalErrorReporting = error_reporting();

        ini_set('display_errors', 0);

        $originalErrorHandler = set_error_handler(function ($severity, $message, $file, $line) use ($name) {
            if ($severity & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR)) {
                while (ob_get_level()) {
                    ob_get_clean();
                }

                $errorMessage = __('Unable to activate "%name%".<br>A fatal error occured.', ['%name%' => $name]);

                if ($this->debug) {
                    $errorMessage .= '<br><br>' . sprintf('%s in %s on line %d', $message, $file, $line);
                }

                $this->response->json($errorMessage, 500)->send();
                exit;
            }

            return false;
        });

        $originalExceptionHandler = set_exception_handler(function ($exception) use ($name) {
            while (ob_get_level()) {
                ob_get_clean();
            }

            $message = __('Unable to activate "%name%".<br>A fatal error occured.', ['%name%' => $name]);

            if ($this->debug) {
                $message .= '<br><br>' . $exception->getMessage();
            }

            $this->response->json($message, 500)->send();
            exit;
        });

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
