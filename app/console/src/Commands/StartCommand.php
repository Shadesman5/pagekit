<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands;

use Pagekit\Application\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StartCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected ?string $name = 'start';

    /**
     * {@inheritdoc}
     */
    protected string $description = 'Starts the built-in web server';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->addOption('server', 's', InputOption::VALUE_OPTIONAL, 'Server name and port', '127.0.0.1:8080');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $server = $this->option('server');
        if (!is_string($server)) {
            throw new \LogicException('Option "server" must be a string.');
        }

        $this->line(sprintf('Pagekit %s Development Server started', $this->getApplication()?->getVersion() ?? 'dev'));
        $this->line(sprintf('Listening on http://%s', $server));
        $this->line(sprintf('Document root is %s', getcwd()));
        $this->line('Press Ctrl-C to quit');

        exec("php -S $server index.php");

        return Command::SUCCESS;
    }
}
