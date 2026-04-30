<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands;

use Pagekit\Application\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ClearCacheCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $name = 'clearcache';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Clears the system cache';

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->container->get('module')->get('system/cache')->doClearCache();

        $this->line('Cache cleared.');

        return SymfonyCommand::SUCCESS;
    }
}
