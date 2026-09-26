<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands;

use Pagekit\Application\Console\Command;
use Pagekit\Module\ModuleManager;
use Pagekit\Module\UnsatisfiedRequirementException;
use Pagekit\Package\PackageFactory;
use Pagekit\Package\PackageInterface;
use Pagekit\Package\PackageManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnableCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected ?string $name = 'enable';

    /**
     * {@inheritdoc}
     */
    protected string $description = 'Enables a Pagekit package';

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
        $manager = new PackageManager($this->container, $output);

        foreach ($packages as $package) {

            try {
                $this->loadRegistered($package);
                $manager->enable($package);
            } catch (\Throwable $e) {
                $refusal = $this->requirementRefusal($e);

                if ($refusal === null) {
                    throw $e;
                }

                $this->error($refusal);

                return Command::FAILURE;
            }

            $this->line(__('"%title%" enabled.', ['%title%' => $this->title($package)]));
        }

        return Command::SUCCESS;
    }

    /**
     * Loads a registered module before enable(); an unregistered name is left to enable(), which does not walk it.
     */
    private function loadRegistered(PackageInterface $package): void
    {
        if (!$this->container->has('module')) {
            return;
        }

        $modules = $this->container->get('module');

        if (!$modules instanceof ModuleManager) {
            return;
        }

        $name = $package->get('module');

        if (!is_string($name) || $name === '' || !$modules->isRegistered($name)) {
            return;
        }

        $modules->load($name);
    }

    /**
     * The named requirement sentence, or null when this failure is a different one.
     */
    private function requirementRefusal(\Throwable $error): ?string
    {
        $refusal = $error instanceof UnsatisfiedRequirementException ? $error : $error->getPrevious();

        if (!$refusal instanceof UnsatisfiedRequirementException) {
            return null;
        }

        return __($refusal->messageId(), [
            '%depender%' => $refusal->depender,
            '%required%' => $refusal->requirement,
        ]);
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
