<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands\Migration;

use Pagekit\Application\Console\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migration Status Command
 *
 * Shows the current status of database migrations.
 *
 * Usage:
 *   php pagekit migration:status
 */
class StatusCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected ?string $name = 'migration:status';

    /**
     * {@inheritdoc}
     */
    protected string $description = 'Show migration status';

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Pagekit Migration System');
        $io->section('Migration Status');

        try {
            // Get migration service
            $migrationService = $this->container->get('migration');

            // Check if migration system is initialized
            if (!$migrationService->isInitialized()) {
                $io->warning('Migration system is not yet initialized.');
                $io->note('Run "php pagekit migration:migrate" to initialize and execute migrations.');

                return Command::SUCCESS;
            }

            // Get migration status
            $status = $migrationService->status();

            if (!$status['success']) {
                $io->error('Failed to retrieve migration status: ' . $status['error']);

                return Command::FAILURE;
            }

            // Display current version information
            $io->definitionList(
                ['Current Version' => $status['current_version'] ?: 'None'],
                ['Latest Version' => $status['latest_version'] ?: 'None'],
                ['Available Migrations' => count($status['available'])],
                ['Executed Migrations' => count($status['executed'])],
                ['Unavailable Migrations' => count($status['unavailable'])]
            );

            // Show executed migrations
            if (!empty($status['executed'])) {
                $io->section('Executed Migrations');

                $table = new Table($output);
                $table->setHeaders(['Version', 'Executed At', 'Execution Time']);

                foreach ($status['executed'] as $migration) {
                    $table->addRow([
                        $migration['version'],
                        $migration['executed_at'] ?? 'N/A',
                        $migration['execution_time'] ? $migration['execution_time'] . 'ms' : 'N/A',
                    ]);
                }

                $table->render();
            }

            // Show available (pending) migrations
            if (!empty($status['available'])) {
                $io->section('Available Migrations');

                $table = new Table($output);
                $table->setHeaders(['Version', 'Description']);

                foreach ($status['available'] as $migration) {
                    $table->addRow([
                        $migration['version'],
                        $migration['description'],
                    ]);
                }

                $table->render();

                $io->note('Run "php pagekit migration:migrate" to execute pending migrations.');
            } else {
                $io->success('All migrations are up to date!');
            }

            // Show unavailable migrations (executed but not found)
            if (!empty($status['unavailable'])) {
                $io->warning('The following migrations were executed but their files are no longer available:');
                $io->listing($status['unavailable']);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('An error occurred: ' . $e->getMessage());

            if ($output->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}
