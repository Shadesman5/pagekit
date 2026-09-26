<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands;

use Pagekit\Application\Console\Command;
use Pagekit\Package\PackageFactory;
use Pagekit\Package\PackageInterface;
use Pagekit\Package\PackageManager;
use Pagekit\Package\RemovalBlockedException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DisableCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected ?string $name = 'disable';

    /**
     * {@inheritdoc}
     */
    protected string $description = 'Disables a Pagekit package';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->addArgument('packages', InputArgument::IS_ARRAY | InputArgument::REQUIRED, '[Package name]');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $names = array_values(array_filter((array) $this->argument('packages'), 'is_string'));
        $packages = $this->packages($names);

        try {
            (new PackageManager($this->container, $output))->disable($packages);
        } catch (RemovalBlockedException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($packages as $package) {
            $this->line(__('"%title%" disabled.', ['%title%' => $this->title($package)]));
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $names
     *
     * @return list<PackageInterface>
     */
    private function packages(array $names): array
    {
        $factory = $this->container->get('package');

        if (!$factory instanceof PackageFactory) {
            throw new \RuntimeException(__('The package catalogue is not available.'));
        }

        $packages = [];

        foreach ($names as $name) {
            $package = $factory->get($name);

            if (!$package instanceof PackageInterface) {
                throw new \RuntimeException(__('Unable to find "%name%".', ['%name%' => $name]));
            }

            $packages[] = $package;
        }

        return $packages;
    }

    private function title(PackageInterface $package): string
    {
        $title = $package->get('title');

        return is_string($title) && $title !== '' ? $title : $package->getName();
    }
}
