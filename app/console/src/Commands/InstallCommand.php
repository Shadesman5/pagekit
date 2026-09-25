<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands;

use Pagekit\Application\Console\Command;
use Pagekit\Package\Archive\PackageArchive;
use Pagekit\Package\PackageManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected ?string $name = 'install';

    /**
     * {@inheritdoc}
     */
    protected string $description = 'Installs a Pagekit package from its ZIP archive';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->addArgument('archive', InputArgument::REQUIRED, 'Path to the package archive');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $this->argument('archive');

        if (!is_string($file)) {
            throw new \LogicException('Argument "archive" must be a string.');
        }

        try {
            $archive = PackageArchive::open($file);

            (new PackageManager($this->container, $output))->install($archive);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->info(sprintf('Installed %s %s.', $archive->name(), $archive->version()));

        return Command::SUCCESS;
    }
}
