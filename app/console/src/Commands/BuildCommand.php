<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands;

use Pagekit\Application\Console\Command;
use Pagekit\Installer\Helper\Composer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class BuildCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected ?string $name = 'build';

    /**
     * {@inheritdoc}
     */
    protected string $description = 'Builds a .zip release file';

    /**
     * @var string[]
     */
    protected array $excludes = [
        '^(tmp|config\.php|pagekit.+\.zip|pagekit.db|.+\.map)',
        '^app\/assets\/[^\/]+\/(dist\/vue-.+\.js|dist\/jquery\.js|lodash\.js)',
        '^app\/assets\/(jquery|vue)\/(src|perf|external)',
        '^app\/vendor\/lusitanian\/oauth\/examples',
        '^app\/vendor\/maximebf\/debugbar\/src\/DebugBar\/Resources',
        '^app\/vendor\/nickic\/php-parser\/(grammar|test_old)',
        '^app\/vendor\/(phpdocumentor|phpspec|sebastian|symfony\/yaml)',
        '^app\/vendor\/[^\/]+\/[^\/]+\/(build|docs?|tests?|changelog|phpunit|upgrade?)',
        'node_modules',
    ];

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->container->get('path');
        $vers = $this->container->get('version');
        $filter = '/' . implode('|', $this->excludes) . '/i';
        $packages = [
            'pagekit/blog' => '*',
            'pagekit/theme-one' => '*',
        ];

        $config = [];
        foreach (['path.temp', 'path.cache', 'path.vendor', 'path.artifact', 'path.packages', 'system.api'] as $key) {
            $config[$key] = $this->container->get($key);
        }

        // TODO: Step 5.6 (Marketplace & Extensions) — optional release step: bundle the published
        // marketplace versions of the first-party packages into the ZIP instead of the in-repo
        // sources. Disabled in 2020 when the pagekit.com backend (system.api) was shut down;
        // re-enable only after a self-hostable package-distribution API exists.
        // $composer = new Composer($config, $output);
        // $composer->install($packages);

        $this->line('Starting: build');

        // The bundles, the copied assets and the compiled stylesheets are all
        // untracked, so the release has to build them before packing the ZIP.
        // Absolute path: the release may be built from any working directory.
        // Both streams are captured - the build reports its failures on stderr,
        // so a stdout-only capture would report everything but the cause.
        exec('node ' . escapeshellarg("{$path}/scripts/build.mjs") . ' 2>&1', $buildOutput, $buildStatus);

        $buildLog = implode(PHP_EOL, $buildOutput);

        if ($buildStatus !== 0) {
            $this->error(sprintf("Build failed:\n%s", $buildLog));

            return Command::FAILURE;
        }

        $this->line($buildLog);

        $this->line(sprintf('Building Package.'));

        $finder = Finder::create()->files()->in($path)->ignoreVCS(true)->filter(fn ($file) => !preg_match($filter, $file->getRelativePathname()));

        $zip = new \ZipArchive();

        if (true !== $zip->open($zipFile = "{$path}/pagekit-{$vers}.zip", \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            $this->abort("Can't open ZIP extension in '{$zipFile}'");
        }

        foreach ($finder as $file) {
            // Normalize path separators for cross-platform compatibility
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());
            $zip->addFile($file->getPathname(), $relativePath);
        }

        $zip->addFile("{$path}/.bowerrc", '.bowerrc');
        $zip->addFile("{$path}/.htaccess", '.htaccess');

        $zip->addEmptyDir('tmp/');
        $zip->addEmptyDir('tmp/cache/');
        $zip->addEmptyDir('tmp/temp/');
        $zip->addEmptyDir('tmp/logs/');
        $zip->addEmptyDir('tmp/sessions/');
        $zip->addEmptyDir('tmp/packages/');

        $zip->close();

        $name = basename($zipFile);
        $size = filesize($zipFile) / 1024 / 1024;

        $this->line(sprintf('Build: %s (%.2f MB)', $name, $size));

        return Command::SUCCESS;
    }
}
