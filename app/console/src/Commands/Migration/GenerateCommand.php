<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands\Migration;

use Pagekit\Application\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migration Generate Command
 *
 * Generates a new empty migration file.
 *
 * Usage:
 *   php pagekit migration:generate CreateUserTable
 *   php pagekit migration:generate AddEmailToUsers
 */
class GenerateCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $name = 'migration:generate';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Generate a new migration file';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->addArgument(
            'name',
            InputArgument::REQUIRED,
            'Migration name (e.g., CreateUserTable, AddEmailColumn)'
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Pagekit Migration System');
        $io->section('Generate Migration');

        try {
            // Get migration service
            $migrationService = $this->container['migration'];
            
            // Get migration name
            $name = $input->getArgument('name');
            
            // Validate name
            if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                $io->error('Migration name can only contain letters, numbers, and underscores.');
                return Command::FAILURE;
            }
            
            $io->text(sprintf('Generating migration: <info>%s</info>', $name));
            
            // Generate migration
            $result = $migrationService->generate($name);
            
            if (!$result['success']) {
                $io->error('Failed to generate migration: ' . $result['error']);
                return Command::FAILURE;
            }
            
            // Show success message
            $io->success('Migration generated successfully!');
            
            $io->definitionList(
                ['Name' => $result['name'] ?? $name],
                ['Version' => $result['version']],
                ['Class' => $result['class']],
                ['Path' => $result['path']],
                ['Namespace' => $result['namespace']]
            );
            
            $io->note('Edit the generated migration file to add your database changes.');
            $io->text('Then run: <comment>php pagekit migration:migrate</comment>');
            
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
