<?php

declare(strict_types=1);

namespace Pagekit\Application\Console;

use Pagekit\Application as Container;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class Command extends BaseCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = null;

    /**
     * The console command description.
     */
    protected string $description = '';

    /**
     * The console command input.
     */
    protected ?InputInterface $input = null;

    /**
     * The console command output.
     */
    protected ?OutputInterface $output = null;

    /**
     * The Container instance.
     */
    protected Container $container;

    /**
     * The Pagekit config.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $config = null;

    /**
     * Create a new console command instance.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
        parent::__construct($this->name);
        $this->setDescription($this->description);
    }

    /**
     * Set the Pagekit config.
     *
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->input = $input;
        $this->output = $output;
    }

    /**
     * Get the value of a command argument.
     *
     * @return string|array<string, mixed>|null
     */
    public function argument(?string $key = null): string|array|null
    {
        if ($this->input === null) {
            throw new \LogicException('Input is not initialized. This method can only be called during command execution.');
        }

        if ($key === null) {
            return $this->input->getArguments();
        }

        return $this->input->getArgument($key);
    }

    /**
     * Get the value of a command option.
     *
     * @return string|array<string, mixed>|bool|null
     */
    public function option(?string $key = null): string|array|bool|null
    {
        if ($this->input === null) {
            throw new \LogicException('Input is not initialized. This method can only be called during command execution.');
        }

        if ($key === null) {
            return $this->input->getOptions();
        }

        return $this->input->getOption($key);
    }

    public function confirm(string $question, bool $default = true): bool
    {
        if ($this->input === null || $this->output === null) {
            throw new \LogicException('Input/Output is not initialized. This method can only be called during command execution.');
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $q = new ConfirmationQuestion("<question>$question</question>", $default);

        return (bool) $helper->ask($this->input, $this->output, $q);
    }

    public function ask(string $question, ?string $default = null): string
    {
        if ($this->input === null || $this->output === null) {
            throw new \LogicException('Input/Output is not initialized. This method can only be called during command execution.');
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $q = new Question("<question>$question</question>", $default);

        return (string) $helper->ask($this->input, $this->output, $q);
    }

    public function secret(string $question, bool $fallback = true): string
    {
        if ($this->input === null || $this->output === null) {
            throw new \LogicException('Input/Output is not initialized. This method can only be called during command execution.');
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $q = new Question("<question>$question</question>");
        $q->setHidden(true);
        $q->setHiddenFallback($fallback);

        return (string) $helper->ask($this->input, $this->output, $q);
    }

    public function line(string $string): void
    {
        if ($this->output === null) {
            throw new \LogicException('Output is not initialized. This method can only be called during command execution.');
        }

        $this->output->writeln($string);
    }

    public function info(string $string): void
    {
        if ($this->output === null) {
            throw new \LogicException('Output is not initialized. This method can only be called during command execution.');
        }

        $this->output->writeln("<info>$string</info>");
    }

    public function comment(string $string): void
    {
        if ($this->output === null) {
            throw new \LogicException('Output is not initialized. This method can only be called during command execution.');
        }

        $this->output->writeln("<comment>$string</comment>");
    }

    public function question(string $string): void
    {
        if ($this->output === null) {
            throw new \LogicException('Output is not initialized. This method can only be called during command execution.');
        }

        $this->output->writeln("<question>$string</question>");
    }

    public function error(string $string): void
    {
        if ($this->output === null) {
            throw new \LogicException('Output is not initialized. This method can only be called during command execution.');
        }

        $this->output->writeln("<error>$string</error>");
    }

    public function abort(string $string): void
    {
        $this->error($string);
        exit;
    }
}
