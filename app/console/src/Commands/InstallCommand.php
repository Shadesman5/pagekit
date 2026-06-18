<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands;

use Pagekit\Application\Console\Command;
use Pagekit\Installer\Package\PackageManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $name = 'install';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Installs a Pagekit package';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->addArgument('packages', InputArgument::IS_ARRAY | InputArgument::REQUIRED, '[Package name]:[Version constraint]');
        $this->addOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // TODO: Step 5.6 (Marketplace & Extensions) — `pagekit install <pkg>` installs an
        // extension/theme from the marketplace. Disabled in 2020 when the pagekit.com backend
        // (system.api) was shut down; re-enable once a self-hostable package-distribution API exists.
        $this->error("The 'install' command is disabled: the pagekit.com marketplace backend was discontinued. To be re-enabled in roadmap Step 5.6 (Marketplace & Extensions).");

        return Command::FAILURE;

        // $packages = [];

        // foreach ((array) $this->argument('packages') as $argument) {
        //     $argument = explode(':', $argument);
        //     $packages[$argument[0]] = isset($argument[1]) && $argument[1] ? $argument[1] : '*';
        // }

        // $installer = new PackageManager($output);
        // $installer->install($packages, true, $this->option('prefer-source'));
    }
}
