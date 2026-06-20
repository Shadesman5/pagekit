<?php

declare(strict_types=1);

namespace Pagekit\Application\Console;

use Pagekit\Container;
use Symfony\Component\Console\Command\Command as BaseCommand;
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
    protected ?\Pagekit\Container $container = null;

    /**
     * The Pagekit config.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $config = null;

    /**
     * Create a new console command instance.
     */
    public function __construct()
    {
        parent::__construct($this->name);
        $this->setDescription($this->description);
    }

    /**
     * Set the Pagekit application instance.
     *
     * @param Container $container
     */
    public function setContainer(Container $container): void
    {
        $this->container = $container;
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
        if ($key === null) {
            return $this->input->getOptions();
        }

        return $this->input->getOption($key);
    }

    public function confirm(string $question, bool $default = true): bool
    {
        $helper = $this->getHelperSet()->get('question');
        $question = new ConfirmationQuestion("<question>$question</question>", $default);

        return $helper->ask($this->input, $this->output, $question);
    }

    public function ask(string $question, ?string $default = null): string
    {
        $helper = $this->getHelperSet()->get('question');
        $question = new Question("<question>$question</question>", $default);

        return $helper->ask($this->input, $this->output, $question);
    }

    public function secret(string $question, bool $fallback = true): string
    {
        $helper = $this->getHelperSet()->get('question');
        $question = new Question("<question>$question</question>");
        $question->setHidden(true);
        $question->setHiddenFallback($fallback);

        return $helper->ask($this->input, $this->output, $question);
    }

    public function line(string $string): void
    {
        $this->output->writeln($string);
    }

    public function info(string $string): void
    {
        $this->output->writeln("<info>$string</info>");
    }

    public function comment(string $string): void
    {
        $this->output->writeln("<comment>$string</comment>");
    }

    public function question(string $string): void
    {
        $this->output->writeln("<question>$string</question>");
    }

    public function error(string $string): void
    {
        $this->output->writeln("<error>$string</error>");
    }

    public function abort(string $string): void
    {
        $this->error($string);
        exit;
    }
}
