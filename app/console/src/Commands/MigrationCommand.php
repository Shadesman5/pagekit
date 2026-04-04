<?php declare(strict_types=1);

namespace Pagekit\Console\Commands;

use Pagekit\Application\Console\Command;
use Pagekit\Installer\Package\PackageScripts;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrationCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $name = 'migrate';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Migrates Pagekit';

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var \Pagekit\Migration\MigrationService $migration */
        $migration = $this->container->get('migration');

        try {
            $result = $migration->migrate();
        } catch (\Throwable $e) {
            $this->line(sprintf('<error>Doctrine Migration failed: %s</error>', $e->getMessage()));
            return SymfonyCommand::FAILURE;
        }

        $executed = $result['executed'] ?? 0;
        if ($executed > 0) {
            $this->line(sprintf('<info>Executed %d Doctrine migration(s).</info>', $executed));
        }

        $config = $this->container->get('config')('system');

        $scripts = new PackageScripts($this->container->get('path').'/app/system/scripts.php', $config->get('version'), $this->container);
        if ($scripts->hasUpdates()) {
            $scripts->update();
        }

        $config->set('version', $this->container->get('version'));

        $this->line(sprintf('<info>%s</info>', __('Your Pagekit database has been updated successfully.')));

        return SymfonyCommand::SUCCESS;
    }
}
