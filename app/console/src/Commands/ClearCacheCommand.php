<?php

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
        // Clear the PSR-6 cache pool (works for all backends: file, APCu, array)
        if ($this->container->has('cache')) {
            $this->container->get('cache')->clear();
        }

        // Clear compiled cache files (Symfony route/metadata caches, not PSR-6 items)
        foreach ((array) glob($this->container->get('path.cache') . '/*.cache') as $file) {
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
            @unlink($file);
        }

        $this->line('Cache cleared.');

        return SymfonyCommand::SUCCESS;
    }
}
