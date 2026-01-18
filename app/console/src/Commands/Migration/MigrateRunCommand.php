<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands\Migration;

use Pagekit\Application\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migrate Run Command
 *
 * Executes pending database migrations.
 *
 * Usage:
 *   php pagekit migration:migrate
 *   php pagekit migration:migrate --to=20250101000000
 */
class MigrateRunCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $name = 'migration:migrate';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Execute database migrations';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->addOption(
            'to',
            null,
            InputOption::VALUE_REQUIRED,
            'Migrate up to specific version'
        );

        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show SQL statements without executing'
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Pagekit Migration System');
        $io->section('Executing Migrations');

        try {
            // Get migration service
            $migrationService = $this->container['migration'];
            
            // Check if migration system is initialized
            if (!$migrationService->isInitialized()) {
                $io->note('Migration system not initialized. Initializing now...');
                
                $initResult = $migrationService->initialize();
                
                if (!$initResult['success']) {
                    $io->error('Failed to initialize migration system: ' . $initResult['error']);
                    return Command::FAILURE;
                }
                
                $io->success('Migration system initialized successfully.');
            }
            
            // Get target version (if specified)
            $version = $input->getOption('to');
            $dryRun = $input->getOption('dry-run');
            
            if ($dryRun) {
                $io->note('Dry-run mode: No changes will be made');
            }
            
            // Execute migrations
            $io->text($dryRun ? 'Calculating migrations (dry-run)...' : 'Executing migrations...');
            
            $result = $migrationService->migrate($version, $dryRun);
            
            if (!$result['success']) {
                $io->error('Migration failed: ' . $result['error']);
                return Command::FAILURE;
            }
            
            if ($result['executed'] === 0) {
                $io->success('No pending migrations to execute.');
                return Command::SUCCESS;
            }
            
            // Show results
            if ($dryRun) {
                $io->success(sprintf(
                    'Dry-run complete: %d migration(s) would be executed.',
                    $result['executed']
                ));
                
                if (!empty($result['sql'])) {
                    $io->section('SQL statements that would be executed');
                    foreach ($result['sql'] as $sql) {
                        $io->writeln('  ' . $sql);
                    }
                }
            } else {
                $io->success(sprintf(
                    'Successfully executed %d migration(s) in %.2f seconds.',
                    $result['executed'],
                    $result['time']
                ));
                
                if (!empty($result['sql']) && $output->isVerbose()) {
                    $io->section('Executed SQL');
                    foreach ($result['sql'] as $sql) {
                        $io->writeln('  ' . $sql);
                    }
                }
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
