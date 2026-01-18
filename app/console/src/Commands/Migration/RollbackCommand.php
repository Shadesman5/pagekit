<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands\Migration;

use Pagekit\Application\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migration Rollback Command
 *
 * Rolls back database migrations.
 *
 * Usage:
 *   php pagekit migration:rollback                  # Rollback to previous version
 *   php pagekit migration:rollback --to=VERSION     # Rollback to specific version
 *   php pagekit migration:rollback --to=0           # Rollback all migrations
 */
class RollbackCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $name = 'migration:rollback';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Rollback database migrations';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->addOption(
            'to',
            null,
            InputOption::VALUE_REQUIRED,
            'Rollback to specific version (use "0" to rollback all)'
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
        $io->section('Rolling Back Migrations');

        try {
            // Get migration service
            $migrationService = $this->container['migration'];
            
            // Check if migration system is initialized
            if (!$migrationService->isInitialized()) {
                $io->warning('Migration system is not yet initialized. Nothing to rollback.');
                return Command::SUCCESS;
            }
            
            // Get target version (if specified)
            $version = $input->getOption('to');
            $dryRun = $input->getOption('dry-run');
            
            if ($dryRun) {
                $io->note('Dry-run mode: No changes will be made');
            }
            
            // Confirm rollback (especially for "rollback all")
            if ($version === '0') {
                $io->warning('This will rollback ALL migrations!');
                
                if (!$io->confirm('Are you sure you want to continue?', false)) {
                    $io->note('Rollback cancelled.');
                    return Command::SUCCESS;
                }
            }
            
            // Execute rollback
            $io->text($dryRun ? 'Calculating rollback (dry-run)...' : 'Rolling back migrations...');
            
            $result = $migrationService->rollback($version, $dryRun);
            
            if (!$result['success']) {
                $io->error('Rollback failed: ' . $result['error']);
                return Command::FAILURE;
            }
            
            if ($result['executed'] === 0) {
                $io->success('No migrations to rollback.');
                return Command::SUCCESS;
            }
            
            // Show results
            if ($dryRun) {
                $io->success(sprintf(
                    'Dry-run complete: %d migration(s) would be rolled back.',
                    $result['executed']
                ));
            } else {
                $io->success(sprintf(
                    'Successfully rolled back %d migration(s) in %.2f seconds.',
                    $result['executed'],
                    $result['time']
                ));
            }
            
            if ($output->isVerbose()) {
                $io->note('Run "php pagekit migration:status" to see current migration status.');
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
