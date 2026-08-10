<?php

declare(strict_types=1);

namespace Pagekit\Installer\Helper;

use Composer\Installer;
use Composer\Json\JsonFile;
use Composer\Package\Locker;
use Composer\Package\Package;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledFilesystemRepository;
use Composer\Semver\VersionParser;
use Pagekit\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Output\OutputInterface;

class Composer
{
    /** @var array<string, string> */
    public array $paths;

    /** @var array<string, mixed> */
    public array $blueprint;

    protected ?InstallerIO $io = null;

    protected ?OutputInterface $output = null;

    protected Filesystem $files;

    protected LoggerInterface $logger;

    /** @var array<string, string> */
    protected array $packages = [];

    protected string $file = 'packages.php';

    /**
     * @param array<string, string> $config
     * @param Filesystem|null       $files  Writer for the package registry, defaults to a plain local one
     * @param LoggerInterface|null  $logger Defaults to discarding what it is given
     */
    public function __construct(array $config, ?OutputInterface $output = null, ?Filesystem $files = null, ?LoggerInterface $logger = null)
    {
        $this->paths = $config;
        $this->output = $output;
        $this->files = $files ?? new Filesystem();
        $this->logger = $logger ?? new NullLogger();

        $this->file = $config['path.packages'] . '/' . $this->file;
        $this->blueprint = [
            'repositories' => [
                ['type' => 'artifact', 'url' => $config['path.artifact']],
                ['type' => 'composer', 'url' => $config['system.api']],
            ],
        ];
    }

    /**
     * @param array<string, string> $install [name => version, name => version, ...]
     */
    public function install(array $install, bool $packagist = false, bool $writeConfig = true, bool $preferSource = false): void
    {
        $this->addPackages($install);

        $refresh = [];
        $versionParser = new VersionParser();
        foreach ($install as $name => $version) {
            try {
                $normalized = $versionParser->normalize($version);
                $refresh[] = new Package($name, $normalized, $version);
            } catch (\UnexpectedValueException) {
                // Range constraints like ^1.0 or ~2.3 are legitimate input but cannot be
                // normalized to a single version. Dropping the package from $refresh only
                // skips the forced re-download; the install itself proceeds unchanged.
                $this->logger->info(sprintf(
                    'Version constraint for %s is not an exact version (%s); skipping forced refresh.',
                    $name,
                    $version
                ));
            }
        }

        $this->composerUpdate(array_keys($install), $refresh, $packagist, $preferSource);

        if ($writeConfig) {
            $this->writeConfig();
        }
    }


    /**
     * @param array<int, string>|string $uninstall [name, name, ...]
     */
    public function uninstall(array|string $uninstall, bool $writeConfig = true): void
    {
        $uninstall = (array) $uninstall;

        $this->removePackages($uninstall);

        $this->composerUpdate($uninstall);

        if ($writeConfig) {
            $this->writeConfig();
        }
    }

    /**
     * Checks if a package is installed by composer.
     */
    public function isInstalled(string $name): bool
    {
        $installedPath = $this->paths['path.packages'] . '/composer/installed.json';
        $installed = [];

        if (file_exists($installedPath)) {
            $contents = file_get_contents($installedPath);
            if ($contents !== false) {
                $decoded = json_decode($contents, true);
                if (is_array($decoded)) {
                    $installed = $decoded;
                }
            }
        }

        $installed = array_map(fn ($pkg) => $pkg['name'], $installed);

        return in_array($name, $installed);
    }

    /**
     * Runs Composer Update command.
     *
     * @param  array<int, string>|bool $updates
     * @param  array<int, Package>     $refresh
     * @throws \Exception
     */
    protected function composerUpdate(array|bool $updates = false, array $refresh = [], bool $packagist = false, bool $preferSource = false): void
    {
        $installed = new JsonFile($this->paths['path.vendor'] . '/composer/installed.json');
        $internal = new CompositeRepository([]);
        $internal->addRepository(new InstalledFilesystemRepository($installed));

        $composer = $this->getComposer($packagist);
        $composer->getDownloadManager()->setOutputProgress(false);

        $local = $composer->getRepositoryManager()->getLocalRepository();
        foreach ($refresh as $package) {
            $local->removePackage($package);
        }

        $installer = Installer::create($this->getIO(), $composer)
            ->setAdditionalInstalledRepository($internal)
            ->setOptimizeAutoloader(true)
            ->setUpdate(true);

        if ($preferSource) {
            $installer->setPreferSource(true);
        } else {
            $installer->setPreferDist(true);
        }

        if ($updates) {
            $installer->setUpdateWhitelist($updates)->setWhitelistDependencies();
        }

        $installer->run();
    }

    /**
     * Returns composer instance.
     *
     * @return null
     */
    protected function getComposer(bool $packagist = false): \Composer\Composer
    {
        $config = $this->blueprint;
        $config['config'] = ['vendor-dir' => $this->paths['path.packages'], 'cache-files-ttl' => 0];
        $config['require'] = $this->packages;

        if (!$packagist) {
            $config['repositories'][] = ['packagist' => false];
        }

        // set memory limit, if < 512M
        $memory = trim(ini_get('memory_limit'));
        if ($memory != -1 && $this->memoryInBytes($memory) < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }

        Factory::bootstrap([
            'home' => $this->paths['path.temp'] . '/composer',
            'cache-dir' => $this->paths['path.temp'] . '/composer/cache',
        ]);

        $composer = Factory::create($this->getIO(), $config);
        $composer->setLocker(new Locker(
            $this->getIO(),
            new JsonFile(preg_replace('/\.php$/i', '.lock', $this->file) ?? $this->file),
            $composer->getRepositoryManager(),
            $composer->getInstallationManager(),
            json_encode($config)
        ));

        return $composer;
    }


    protected function getIO(): InstallerIO
    {
        return $this->io ?: ($this->io = new InstallerIO(null, $this->output));
    }

    /**
     * @param array<string, string> $packages
     */
    protected function addPackages(array $packages): void
    {
        $this->packages = array_merge($this->readConfig(), $packages);
    }

    /**
     * @param array<int, string> $packages
     */
    protected function removePackages(array $packages): void
    {
        $this->packages = array_diff_key($this->readConfig(), array_flip($packages));
    }

    /**
     * Reads packages from package file.
     *
     * @return array<string, string>
     */
    protected function readConfig(): array
    {
        return file_exists($this->file) ? require $this->file : [];
    }

    /**
     * Writes changes to packages file.
     *
     * The registry is read back with require, so a half-written file would be a fatal
     * error for every later request, and a compiled copy of the previous one would
     * outlive the write wherever opcache does not validate timestamps.
     *
     * @throws \RuntimeException if the registry could not be written
     */
    protected function writeConfig(): void
    {
        $this->files->dumpAtomic($this->file, '<?php return ' . var_export($this->packages, true) . ';');
    }

    /**
     * Converts memory value from 'php.ini' into bytes.
     */
    protected function memoryInBytes(string $value): int
    {
        $unit = strtolower(substr($value, -1, 1));
        $value = (int) $value;

        switch ($unit) {
            case 'g':
                $value *= 1024;
                // no break (cumulative multiplier)
            case 'm':
                $value *= 1024;
                // no break (cumulative multiplier)
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}
