<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands;

use Pagekit\Application\Console\Command;
use Pagekit\Installer\Package\PackageManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UninstallCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected ?string $name = 'uninstall';

    /**
     * {@inheritdoc}
     */
    protected string $description = 'Uninstalls a Pagekit package';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->addArgument('packages', InputArgument::IS_ARRAY | InputArgument::REQUIRED, '[Package name]');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $packages = (array) $this->argument('packages');
        $packages = array_values(array_filter($packages, 'is_string'));

        $manager = new PackageManager($this->container, $output);
        $manager->uninstall($packages);

        return Command::SUCCESS;
    }
}
