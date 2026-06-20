<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands;

use Pagekit\Application\Console\Command;
use Pagekit\Installer\SelfUpdater;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SelfupdateCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected ?string $name = 'self-update';

    /**
     * {@inheritdoc}
     */
    protected string $description = 'Checks for newer Pagekit versions and installs the latest';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->addOption('url', 'u', InputOption::VALUE_REQUIRED, '');
        $this->addOption('shasum', 's', InputOption::VALUE_REQUIRED, '');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // TODO: Step 5.6 (Marketplace & Extensions) — `pagekit self-update` updates the Pagekit
        // core itself. Needs the `GET /api/update` endpoint that the discontinued pagekit.com
        // backend (system.api) served; re-enable once that backend is rebuilt.
        $this->error("The 'self-update' command is disabled: the pagekit.com update endpoint was discontinued. To be re-enabled in roadmap Step 5.6 (Marketplace & Extensions).");

        return Command::FAILURE;
        // try {
        //     if (!$this->option('url')) {
        //         $output->write('Requesting Version...');
        //         $versions = $this->getVersions();
        //         $output->writeln('<info>done.</info>');
        //
        //         $output->writeln('');
        //         $output->writeln('<comment>Latest Version: ' . $versions['latest']['version'] . '</comment> ');
        //         $output->writeln('');
        //
        //         if (!$this->confirm('Update to Version ' . $versions['latest']['version'] . '? [y/n]')) {
        //             return 0;
        //         }
        //
        //         $output->writeln('');
        //
        //         $url = $versions['latest']['url'];
        //     } else {
        //         $url = $this->option('url');
        //     }
        //
        //     $tmpFile = tempnam($this->container->get('path.temp'), 'update_');
        //
        //     $output->write('Downloading...');
        //     $this->download($url, $tmpFile);
        //     $output->writeln('<info>done.</info>');
        //
        //     $updater = new SelfUpdater($this->container->get('path'), $output);
        //     $updater->update($tmpFile);
        //
        //     $output->write('Migrating...');
        //     system(sprintf('php %s migrate', $_SERVER['PHP_SELF']));
        //
        // } catch (\Exception $e) {
        //
        //     if (isset($tmpFile) && file_exists($tmpFile)) {
        //         unlink($tmpFile);
        //     }
        //
        //     throw $e;
        // }

    }

    /**
     * @return mixed
     * @throws \Exception
     */
    protected function getVersions()
    {
        if (!($res = file_get_contents($this->container->get('system.api') . '/api/update'))) {
            throw new \RuntimeException('Could not obtain latest Version.');
        }

        return json_decode($res, true);
    }

    /**
     * @throws \Exception
     */
    public function download(string $url, string $file): void
    {
        if (!$url) {
            throw new \RuntimeException('Package url is missing.');
        }

        if (!file_put_contents($file, @fopen($url, 'r'))) {
            throw new \RuntimeException('Download failed or path not writable.');
        }
    }
}
