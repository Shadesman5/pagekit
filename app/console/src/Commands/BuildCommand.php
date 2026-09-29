<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands;

use Pagekit\Application\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class BuildCommand extends Command
{
    /**
     * Deny rules under data/. Finder skips names that start with a dot, so these are added by name.
     *
     * @var list<string>
     */
    private const DATA_GUARDS = [
        'data/.htaccess',
        'data/.gitignore',
        'data/snapshots/.htaccess',
        'data/snapshots/.gitignore',
        'data/state/.htaccess',
        'data/state/.gitignore',
    ];

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
        // Live files under data/ must not enter a release. Guard names stay inside
        // `(?:...)`: these patterns are joined with `|`, and a bare `|` would split one.
        '^data\/(?!(?:\.htaccess|\.gitignore|snapshots\/\.htaccess|'
            . 'snapshots\/\.gitignore|state\/\.htaccess|state\/\.gitignore)$)',
        '(^|\/)db\.dump$',
        '^app\/assets\/[^\/]+\/(dist\/vue-.+\.js|dist\/jquery\.js|lodash\.js)',
        '^app\/assets\/(jquery|vue)\/(src|perf|external)',
        '^vendor\/lusitanian\/oauth\/examples',
        '^vendor\/maximebf\/debugbar\/src\/DebugBar\/Resources',
        '^vendor\/nickic\/php-parser\/(grammar|test_old)',
        '^vendor\/(phpdocumentor|phpspec|sebastian|symfony\/yaml)',
        '^vendor\/[^\/]+\/[^\/]+\/(build|docs?|tests?|changelog|phpunit|upgrade?)',
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

        foreach (self::DATA_GUARDS as $guard) {
            $zip->addFile("{$path}/{$guard}", $guard);
        }

        $zip->addEmptyDir('tmp/');
        $zip->addEmptyDir('tmp/cache/');
        $zip->addEmptyDir('tmp/temp/');
        $zip->addEmptyDir('tmp/logs/');
        $zip->addEmptyDir('tmp/sessions/');

        $zip->close();

        $name = basename($zipFile);
        $size = filesize($zipFile) / 1024 / 1024;

        $this->line(sprintf('Build: %s (%.2f MB)', $name, $size));

        return Command::SUCCESS;
    }
}
