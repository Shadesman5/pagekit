<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands;

use Pagekit\Application\Console\Command;
use Pagekit\Installer\Package\Lifecycle\LifecycleRunner;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrationCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected ?string $name = 'migrate';

    /**
     * {@inheritdoc}
     */
    protected string $description = 'Migrates Pagekit';

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = ['success' => true, 'executed' => 0];

        if ($this->container->has('migration')) {
            /** @var \Pagekit\Migration\MigrationService $migration */
            $migration = $this->container->get('migration');

            $result = $migration->migrate();

            if (!$result['success']) {
                $this->line(sprintf('<error>Doctrine Migration failed: %s</error>', $result['error'] ?? 'unknown error'));

                return SymfonyCommand::FAILURE;
            }

            if ($result['executed'] > 0) {
                $this->line(sprintf('<info>Executed %d Doctrine migration(s).</info>', $result['executed']));
            }
        } else {
            $this->line('<error>Migration service not available — cannot verify Doctrine migration state.</error>');

            return SymfonyCommand::FAILURE;
        }

        $config = $this->container->get('config')('system');

        $lifecycle = new LifecycleRunner($this->container->get('path').'/app/system/scripts.php', $config->get('version'), $this->container);
        $hadScriptUpdates = false;

        // Reading the lifecycle file is part of running its updates: it is
        // loaded on the first question asked of it, so a file that does not
        // deliver a lifecycle fails here rather than in update().
        try {
            $hadScriptUpdates = $lifecycle->hasUpdates();

            if ($hadScriptUpdates) {
                $lifecycle->update();
            }
        } catch (\Throwable $e) {
            $this->line(sprintf('<error>Script update failed: %s</error>', $e->getMessage()));

            return SymfonyCommand::FAILURE;
        }

        $config->set('version', $this->container->get('version'));

        if ($result['executed'] > 0 || $hadScriptUpdates) {
            $this->line(sprintf('<info>%s</info>', __('Your Pagekit database has been updated successfully.')));
        } else {
            $this->line(sprintf('<info>%s</info>', __('Your database is up to date.')));
        }

        return SymfonyCommand::SUCCESS;
    }
}
