<?php

declare(strict_types=1);

namespace Pagekit\Application\Console;

use Pagekit\Application as Container;
use Pagekit\Event\Event;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Application extends BaseApplication
{
    /**
     * The Pagekit application instance.
     */
    protected Container $container;

    public function __construct(Container $container, string $name = 'UNKNOWN', string $version = 'UNKNOWN')
    {
        parent::__construct($name, $version);

        $this->setAutoExit(false);

        $this->container = $container;

        if ($container->has('events')) {
            $container->get('events')->trigger('console.init', [$this]);
        }
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $code = parent::run($input, $output);

        if (($code === 0) && ($this->container->has('events'))) {
            $this->container->get('events')->trigger(new Event('terminate'));
        }

        return $code;
    }

    /**
     * Add a command to the console.
     *
     * @param  BaseCommand $command
     * @return BaseCommand
     */
    // TODO: Must be refactored in Step 2.1.6 (PHPStan Level 7→8 — Strict Typing) —
    // convert console commands from setter injection (Command::setContainer()) to constructor DI / a command factory.
    public function add(BaseCommand $command): ?\Symfony\Component\Console\Command\Command
    {
        if ($command instanceof Command) {
            $command->setContainer($this->container);
        }

        return parent::add($command);
    }
}
