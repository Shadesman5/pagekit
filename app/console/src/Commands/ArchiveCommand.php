<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands;

use Composer\Json\JsonFile;
use Composer\Package\Archiver\PharArchiver;
use Composer\Util\Filesystem;
use Pagekit\Application\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ArchiveCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected ?string $name = 'archive';

    /**
     * {@inheritdoc}
     */
    protected string $description = 'Archives an extension or theme';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Package name');
        $this->addOption('dir', null, InputOption::VALUE_OPTIONAL, 'Write the archive to this directory');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filesystem = new Filesystem();
        $packageName = $this->getPackageFilename($name = $this->argument('name'));

        if (!($targetDir = $this->option('dir'))) {
            $targetDir = $this->container->get('path');
        }

        $sourcePath = $this->container->get('path.packages').'/'.$name;

        $filesystem->ensureDirectoryExists($targetDir);

        $target = realpath($targetDir).'/'.$packageName.'.zip';
        $filesystem->ensureDirectoryExists(dirname($target));

        $excludes = [];
        if (file_exists($composerJsonPath = $sourcePath.'/composer.json')) {
            $jsonFile = new JsonFile($composerJsonPath);
            $jsonData = $jsonFile->read();

            if (!empty($jsonData['archive']['exclude'])) {
                $excludes = ($jsonData['archive']['exclude']);
            }

            if (!empty($jsonData['archive']['scripts'])) {
                system($jsonData['archive']['scripts'], $return);

                if ($return !== 0) {
                    throw new \RuntimeException('Can not executes scripts.');
                }
            }
        }

        $tempTarget = sys_get_temp_dir().'/composer_archive'.uniqid().'.zip';
        $filesystem->ensureDirectoryExists(dirname($tempTarget));

        if (!is_dir($sourcePath)) {
            $this->error(sprintf('Package \'%s\' doesn\'t exist.', $this->argument('name')));

            return 1;
        }

        $this->info(sprintf('Archiving \'%s\'', $this->argument('name')));

        $archivePath = (new PharArchiver())->archive($sourcePath, $tempTarget, 'zip', $excludes);
        rename($archivePath, $target);

        $filesystem->remove($tempTarget);

        $name = basename($target);
        $size = filesize($target) / 1024 / 1024;

        $this->line(sprintf('Archive created: %s (%.2f MB)', $name, $size));

        return Command::SUCCESS;
    }

    protected function getPackageFilename(string $name): string
    {
        $filename = preg_replace('#[^a-z0-9-_]#i', '-', $name) ?? $name;
        $filename = preg_replace('#-+#', '-', $filename) ?? $filename;
        $filename = trim($filename, '-');

        // Guard against a name that sanitises down to nothing (e.g. "///").
        return $filename !== '' ? $filename : 'package';
    }
}
