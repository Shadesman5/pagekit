<?php

declare(strict_types=1);

namespace Pagekit\Installer;

use Composer\Console\HtmlOutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

class SelfUpdater
{
    /** @var array<int, string> */
    protected array $cleanFolder = ['app'];

    /** @var array<int, string> */
    protected array $ignoreFolder = ['packages', 'storage'];

    protected string $path;

    protected OutputInterface $output;

    public function __construct(string $path, ?OutputInterface $output = null)
    {
        $this->path = $path;
        $this->output = $output ?: new StreamOutput(fopen('php://output', 'w'));

        if (PHP_SAPI != 'cli') {

            ob_implicit_flush(true);
            @ob_end_flush();

            $this->output->setFormatter(new HtmlOutputFormatter());
        }
    }

    /**
     * Runs Pagekit self update.
     *
     * @throws \Exception
     */
    public function update(string $file): void
    {
        try {
            $path = $this->path;

            if (!file_exists($file)) {
                throw new \RuntimeException('File not found.');
            }

            $this->output->write('Preparing update...');
            $fileList = $this->getFileList($file);
            unset($fileList[array_search('.htaccess', $fileList)]);

            $fileList = array_values(array_filter($fileList, function ($file) {
                foreach ($this->ignoreFolder as $ignore) {
                    if (strpos($file, $ignore) === 0) {
                        return false;
                    }
                }

                return true;
            }));

            if ($this->isWritable($fileList, $path) !== true) {
                throw new \RuntimeException(array_reduce($fileList, fn ($carry, $file) => $carry . sprintf("'%s' not writable\n", $file)));
            }

            $requirements = include "zip://{$file}#app/installer/requirements.php";
            if ($failed = $requirements->getFailedRequirements()) {

                throw new \RuntimeException(array_reduce($failed, fn ($carry, $problem) => $carry . "\n" . $problem->getHelpText()));

            }

            $this->output->writeln('<info>done.</info>');
            $this->output->write('Entering update mode...');

            $this->setUpdateMode(true);
            $this->output->writeln('<info>done.</info>');

            $this->output->write('Extracting files...');
            $this->extract($file, $fileList, $path);
            $this->output->writeln('<info>done.</info>');

            $this->output->write('Removing old files...');
            foreach ($this->cleanup($fileList, $path) as $file) {
                $this->writeln(sprintf('<error>\'%s\’ could not be removed</error>', $file));
            }

            unlink($file);
            $this->output->writeln('<info>done.</info>');

            $this->output->write('Deactivating update mode...');
            $this->setUpdateMode(false);
            $this->output->writeln('<info>done.</info>');

            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

        } catch (\Exception $e) {
            @unlink($file);

            throw $e;
        }

    }

    /**
     * Generates file list for given archive.
     *
     * @return array<int, string>
     */
    protected function getFileList(string $file): array
    {
        $list = [];

        $zip = new \ZipArchive();
        if ($zip->open($file) === true) {

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $list[] = $zip->getNameIndex($i);
            }
            $zip->close();

            return $list;
        } else {
            throw new \RuntimeException('Can not build file list.');
        }
    }

    /**
     * Checks if directory is writable.
     *
     * @param  array<int, string>     $fileList
     * @return bool|array<int, string>
     */
    protected function isWritable(array $fileList, string $path): bool|array
    {
        $notWritable = [];

        if (!file_exists($path)) {
            throw new \RuntimeException(sprintf('"%s" not writable', $path));
        }

        foreach ($fileList as $file) {
            $file = $path . '/' . $file;

            while (!file_exists($file)) {
                $file = dirname($file);
            }

            if (!is_writable($file)) {
                $notWritable[] = $file;
            }
        }

        return $notWritable ?: true;
    }


    /**
     * Extracts an archive.
     *
     * @param array<int, string> $fileList
     */
    protected function extract(string $file, array $fileList, string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($file) === true) {

            $zip->extractTo($path, $fileList);
            $zip->close();
        } else {
            throw new \RuntimeException('Package extraction failed.');
        }
    }

    /**
     * Scans directory for old files.
     *
     * @param  array<int, string> $fileList
     * @return array<int, string>
     */
    protected function cleanup(array $fileList, string $path): array
    {
        $errorList = [];

        foreach ($this->cleanFolder as $dir) {
            array_merge($errorList, $this->doCleanup($fileList, $dir, $path));
        }

        return $errorList;
    }

    /**
     * @param  array<int, string> $fileList
     * @return array<int, string>
     */
    protected function doCleanup(array $fileList, string $dir, string $path): array
    {
        $errorList = [];

        foreach (array_diff(@scandir($path . '/' . $dir) ?: [], ['..', '.']) as $file) {
            $file = ($dir ? $dir . '/' : '') . $file;
            $realPath = $path . '/' . $file;

            if (is_dir($realPath)) {
                array_merge($errorList, $this->doCleanup($fileList, $file, $path));
                if (!in_array($file, $fileList)) {
                    @rmdir($realPath);
                }
            } elseif (!in_array($file, $fileList) && !unlink($realPath)) {
                $errorList[] = $file;
            }
        }

        return $errorList;
    }

    /**
     * Toggles update mode without booting Pagekit application.
     */
    protected function setUpdateMode(bool $active): void
    {
        // TODO: Step 5.6 (Marketplace & Extensions) — implement the maintenance-mode toggle for the
        // self-update flow (never finished). Part of rebuilding the self-update infrastructure that
        // depends on the discontinued pagekit.com backend.
    }
}
