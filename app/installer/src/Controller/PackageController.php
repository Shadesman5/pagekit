<?php

declare(strict_types=1);

namespace Pagekit\Installer\Controller;

use Pagekit\Application\Response as PagekitResponse;
use Pagekit\Application\UrlProvider;
use Pagekit\Installer\Package\PackageFactory;
use Pagekit\Installer\Package\PackageInterface;
use Pagekit\Installer\Package\PackageManager;
use Pagekit\Installer\Package\Snapshot\PackageSnapshotter;
use Pagekit\Log\Logger;
use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Attribute\Request as RequestAttribute;
use Pagekit\User\Attribute\Access;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[Access('system: manage packages', admin: true)]
class PackageController
{
    /**
     * @param PackageSnapshotter|null $snapshotter what a removed package can be restored
     *                                             from, and null in an installation that
     *                                             keeps no snapshots at all
     */
    public function __construct(
        protected PackageManager $manager,
        private readonly PackageFactory $package,
        private readonly ModuleManager $module,
        private readonly UrlProvider $url,
        private readonly Request $request,
        private readonly PagekitResponse $response,
        private readonly string $path,
        private readonly bool $debug,
        private readonly Logger $log,
        private readonly string $systemApi = 'https://pagekit.com',
        private readonly ?PackageSnapshotter $snapshotter = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
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
                'name' => 'installer:views/themes.php',
            ],
            '$data' => [
                'api' => $this->systemApi,
                'packages' => $packages,
                'keepsSnapshots' => $this->keepsSnapshots(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function extensionsAction(): array
    {
        $packages = array_values($this->package->all('pagekit-extension'));
        $failed = $this->manager->getFailedModules();

        foreach ($packages as $package) {
            $name = $package->get('module');

            // An extension a failure switched off and one an administrator
            // switched off both read as simply not enabled here, and only one of
            // the two is waiting for someone to look at the log.
            if (is_string($name) && in_array($name, $failed, true)) {
                $package->set('failure', true);
            }

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
                'name' => 'installer:views/extensions.php',
            ],
            '$data' => [
                'api' => $this->systemApi,
                'packages' => $packages,
                'keepsSnapshots' => $this->keepsSnapshots(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[RequestAttribute(['name' => 'string'], csrf: true)]
    public function enableAction(string $name): array
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

    /**
     * @return array<string, mixed>
     */
    #[RequestAttribute(['name' => 'string'], csrf: true)]
    public function disableAction(string $name): array
    {
        if (!$package = $this->package->get($name)) {
            throw new BadRequestHttpException(__('Unable to find "%name%".', ['%name%' => $name]));
        }

        if (!$module = $this->module->get($package->get('module'))) {
            throw new BadRequestHttpException(__('"%name%" has not been loaded.', ['%name%' => $package->get('title')]));
        }

        $this->manager->disable($package);

        $this->module->get('system/cache')->clearCache();

        // The package is off either way; a step of its own that did not finish
        // is something the administrator hears about rather than a failure.
        return ['message' => 'success', 'warnings' => $this->manager->takeHookWarnings()];
    }

    /**
     * @return array<string, mixed>
     */
    #[RequestAttribute(['type' => 'string'], csrf: true)]
    public function uploadAction(string $type): array
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

    /**
     * @param array<string, mixed> $package
     */
    #[RequestAttribute(['package' => 'array', 'packagist' => 'boolean'], csrf: true)]
    public function installAction(array $package = [], bool $packagist = false): StreamedResponse
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

    /**
     * Takes a package out of the installation, retaining it in a snapshot where
     * this installation keeps them ({@see keepsSnapshots()}).
     *
     * What the administrator confirmed before this ran says what the removal
     * does to the package itself; it cannot yet say what else in the
     * installation was counting on it.
     *
     * TODO: Must be refactored in Step 2.7.2 (Module Dependency Integrity)
     */
    #[RequestAttribute(['name' => 'string'], csrf: true)]
    public function uninstallAction(string $name): StreamedResponse
    {
        return $this->response->stream(function () use ($name) {

            $failure = null;

            // Every throwable, not only the ones the removal raises itself. An
            // Error out of a package's own code, or out of the snapshot and file
            // handling this goes through, is the one failure that may not skip
            // what follows: the rebuild below, the warnings and the status line
            // are what the page is waiting for, and without them the modal sits
            // on a progress bar over an installation the removal had already
            // changed.
            try {
                $this->manager->uninstall($name);
            } catch (\Throwable $e) {
                $failure = $e;

                echo $this->removalFailure($name, $e);
            }

            // Either way, and before the outcome is reported. A removal breaks
            // off in one of two places: before it has touched anything, where no
            // snapshot could be taken and nothing was removed, or after the
            // package was switched off and taken out of the system
            // configuration, which is what the panel and the site are built from
            // and cached. Nothing here tells those apart, and they are not the
            // same mistake to make: rebuilding what the first one left alone
            // costs a rebuild, while leaving the second is a panel that goes on
            // offering a package the installation no longer has.
            $this->clearCache();

            // A removal that broke off has usually run some of the package's own
            // steps first, and the failure that stopped it says nothing about the
            // ones that did not finish. Held back here, they would be lost with
            // the manager at the end of the request.
            $this->streamHookWarnings();

            echo $failure === null ? "\nstatus=success" : "\nstatus=error";

        });
    }

    /**
     * Whether a removal from these pages can be undone.
     *
     * The page says what removing a package does before it does it, and in an
     * installation with nowhere to keep a snapshot - or no database to dump into
     * one - what it does is final. That is a promise the confirm has to get
     * right, so it is answered by the same thing the removal itself asks:
     * whether this installation has a snapshotter at all.
     */
    private function keepsSnapshots(): bool
    {
        return $this->snapshotter !== null;
    }

    /**
     * What the page is told about a removal that broke off.
     *
     * A removal refuses in words written for an administrator - no snapshot
     * could be taken, the files would not go - and those are passed on as they
     * stand. An Error is none of that: it is a fault in the code that was
     * running, its text names classes and paths that belong in a log rather than
     * in a panel, and nothing has written it down yet. So it is written down
     * here, and the page is told that much.
     */
    private function removalFailure(string $name, \Throwable $e): string
    {
        if ($e instanceof \Exception) {
            return $e->getMessage();
        }

        $this->logError(sprintf('Failed to remove package "%s"', $name), $e);

        return __('The removal could not be completed. See error log for details.');
    }

    /**
     * Rebuilds what the installation had cached, the way enabling and disabling
     * do.
     *
     * A clear that could not be asked for is not a removal that did not happen,
     * so it does not get to be the answer: what the page is waiting to hear is
     * whether the package is out of the installation. What it costs instead is a
     * panel serving what it had cached until the next clear, which is worth the
     * line in the log that says so.
     */
    private function clearCache(): void
    {
        try {
            $this->module->get('system/cache')->clearCache();
        } catch (\Throwable $e) {
            $this->logError('Failed to clear the cache after removing a package', $e);
        }
    }

    /**
     * Puts one line in the error log, where there is a log able to take it.
     *
     * Read from inside a streamed response, where the status line the page waits
     * for is still to be written: a log that cannot take the line does not get
     * to be the reason the page never hears how the removal ended.
     *
     * @param string $context what was being attempted, for the log
     */
    private function logError(string $context, \Throwable $e): void
    {
        try {
            $this->log->error(sprintf('%s: %s', $context, $e->getMessage()), ['exception' => $e]);
        } catch (\Throwable) {
            // Nothing left to report it to.
        }
    }

    /**
     * Writes the steps of the package's own that did not finish.
     *
     * One line each, folded onto that line because the reader takes this stream
     * apart by line, and written where nothing but the status line can follow
     * them, so a warning arrives whole.
     */
    private function streamHookWarnings(): void
    {
        foreach ($this->manager->takeHookWarnings() as $warning) {
            printf("\nwarning=%s", str_replace(["\r\n", "\r", "\n"], ' ', $warning));
        }
    }

    protected function loadPackage(string $file): PackageInterface
    {
        if (is_file($file)) {

            $zip = new \ZipArchive();

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

    protected function errorHandler(string $name): ?callable
    {
        $originalErrorReporting = error_reporting();

        ini_set('display_errors', 0);

        $originalErrorHandler = set_error_handler(function ($severity, $message, $file, $line) use ($name) {
            if ($severity & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR)) {
                while (ob_get_level()) {
                    ob_get_clean();
                }

                $errorMessage = __('Unable to activate "%name%". A fatal error occurred.', ['%name%' => $name]);

                if ($this->debug) {
                    $errorMessage .= ' ' . sprintf('%s in %s on line %d', $message, $file, $line);
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

            $message = __('Unable to activate "%name%". A fatal error occurred.', ['%name%' => $name]);

            if ($this->debug) {
                $message .= ' ' . $exception->getMessage();
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
