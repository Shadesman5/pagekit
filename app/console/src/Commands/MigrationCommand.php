<?php

namespace Pagekit\Console\Commands;

use Pagekit\Application\Console\Command;
use Pagekit\Installer\Package\PackageScripts;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// TODO: Must be refactored in Step 2.0.4 (Package/Migration System Redesign) —
// This command ('pagekit migrate') only runs scripts.php 'updates', NOT Doctrine Migrations.
// Developers expect 'migrate' to run DB migrations. Unify: run Doctrine Migrations first,
// then scripts.php hooks. Rename or merge with migration:migrate for clarity.
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
        $config = $this->container->get('config')('system');

        $scripts = new PackageScripts($this->container->get('path').'/app/system/scripts.php', $config->get('version'), $this->container);
        if ($scripts->hasUpdates()) {
            $scripts->update();
        }

        $config->set('version', $this->container->get('version'));

        $this->line(sprintf('<info>%s</info>', __('Your Pagekit database has been updated successfully.')));

        return 0;
    }
}
