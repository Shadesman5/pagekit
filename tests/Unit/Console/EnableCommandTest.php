<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Console;

use Pagekit\Application;
use Pagekit\Console\Commands\EnableCommand;
use Pagekit\Module\Module;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The enable command refuses an unsatisfied requirement and loads a registered module first.
 */
final class EnableCommandTest extends TestCase
{
    private ?ActivationCommandInstallation $site = null;

    protected function tearDown(): void
    {
        $this->site?->remove();
    }

    public function testThereIsNoForceOption(): void
    {
        $command = new EnableCommand(new Application());

        self::assertFalse($command->getDefinition()->hasOption('force'));
    }

    public function testADisabledRequirementIsPrintedAndExtensionsStay(): void
    {
        $site = $this->open();
        $site->module('alpha', ['beta'], true);
        $site->module('beta');
        $site->register();
        $site->activate(['kept']);
        $site->package('alpha', ['title' => 'Alpha']);

        $tester = $this->enable($site, ['pagekit/alpha']);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertSame(
            "Module \"alpha\" requires \"beta\", which is registered but disabled.\n",
            $tester->getDisplay(true),
        );
        self::assertSame(['kept'], $site->system->get('extensions'));
        self::assertSame('other', $site->system->get('site.theme'));
        self::assertNull($site->system->get('packages.alpha'));
        self::assertNull($site->modules->get('alpha'));
        self::assertNull($site->modules->get('beta'));
        self::assertFalse($site->ran('alpha'));
    }

    public function testAnUnregisteredRequirementIsPrintedAndExtensionsStay(): void
    {
        $site = $this->open();
        $site->module('alpha', ['missing'], true);
        $site->register();
        $site->activate(['kept']);
        $site->package('alpha', ['title' => 'Alpha']);

        $tester = $this->enable($site, ['pagekit/alpha']);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertSame(
            "Module \"alpha\" requires \"missing\", which is not registered.\n",
            $tester->getDisplay(true),
        );
        self::assertSame(['kept'], $site->system->get('extensions'));
        self::assertNull($site->system->get('packages.alpha'));
        self::assertNull($site->modules->get('alpha'));
        self::assertFalse($site->ran('alpha'));
    }

    public function testAnUnregisteredModuleIsEnabledWithoutLoadingIt(): void
    {
        $site = $this->open();
        $site->register();
        $site->activate(['kept']);
        $site->package('ghost', ['title' => 'Ghost']);

        $tester = $this->enable($site, ['pagekit/ghost']);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());
        self::assertSame("\"Ghost\" enabled.\n", $tester->getDisplay(true));
        self::assertSame(['kept', 'ghost'], $site->system->get('extensions'));
        self::assertSame('1.0.0', $site->system->get('packages.ghost'));
        self::assertSame('other', $site->system->get('site.theme'));
        self::assertNull($site->modules->get('ghost'));
        self::assertFalse($site->ran('ghost'));
    }

    public function testARegisteredModuleRunsBeforeItIsEnabled(): void
    {
        $site = $this->open();
        $site->module('alpha', [], true);
        $site->register();
        $site->activate(['kept']);
        $site->package('alpha', ['title' => 'Alpha']);

        $tester = $this->enable($site, ['pagekit/alpha']);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());
        self::assertSame("\"Alpha\" enabled.\n", $tester->getDisplay(true));
        self::assertSame(['kept', 'alpha'], $site->system->get('extensions'));
        self::assertSame('1.0.0', $site->system->get('packages.alpha'));
        self::assertSame('other', $site->system->get('site.theme'));
        self::assertInstanceOf(Module::class, $site->modules->get('alpha'));
        self::assertTrue($site->ran('alpha'));
    }

    public function testAnEmptyTitleIsReplacedByThePackageName(): void
    {
        $site = $this->open();
        $site->module('alpha');
        $site->register();
        $site->activate(['kept']);
        $site->package('alpha', ['title' => '']);

        $tester = $this->enable($site, ['pagekit/alpha']);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());
        self::assertSame("\"pagekit/alpha\" enabled.\n", $tester->getDisplay(true));
        self::assertSame(['kept', 'alpha'], $site->system->get('extensions'));
    }

    public function testACircularRequirementIsNotCaught(): void
    {
        $site = $this->open();
        $site->module('alpha', ['beta'], true);
        $site->module('beta', ['alpha']);
        $site->register();
        $site->activate(['alpha', 'beta']);
        $site->package('alpha', ['title' => 'Alpha']);

        $failure = $this->refusal(fn () => $this->enable($site, ['pagekit/alpha']));

        self::assertSame('Circular requirement "beta > alpha" detected.', $failure->getMessage());
        self::assertSame(['kept'], $site->system->get('extensions'));
        self::assertNull($site->system->get('packages.alpha'));
        self::assertFalse($site->ran('alpha'));
    }

    public function testAnUnknownNameIsRefusedBeforeAnyPackageChanges(): void
    {
        $site = $this->open();
        $site->module('alpha', [], true);
        $site->register();
        $site->activate(['kept']);
        $site->package('alpha', ['title' => 'Alpha']);

        $failure = $this->refusal(fn () => $this->enable($site, ['pagekit/alpha', 'pagekit/absent']));

        self::assertSame('Unable to find "pagekit/absent".', $failure->getMessage());
        self::assertSame(['kept'], $site->system->get('extensions'));
        self::assertNull($site->system->get('packages.alpha'));
        self::assertFalse($site->ran('alpha'));
    }

    public function testACatalogueThatIsNotAFactoryIsRefused(): void
    {
        $site = $this->open();
        $site->app->set('package', new \stdClass());

        $failure = $this->refusal(fn () => $this->enable($site, ['pagekit/alpha']));

        self::assertSame('The package catalogue is not available.', $failure->getMessage());
        self::assertSame(['kept'], $site->system->get('extensions'));
    }

    public function testEnableWithoutAModuleRegistryDoesNotRefuseARequirement(): void
    {
        $site = $this->open();
        $site->app->remove('module');
        $site->package('alpha', ['title' => 'Alpha']);

        $tester = $this->enable($site, ['pagekit/alpha']);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());
        self::assertSame("\"Alpha\" enabled.\n", $tester->getDisplay(true));
        self::assertSame(['kept', 'alpha'], $site->system->get('extensions'));
        self::assertSame('1.0.0', $site->system->get('packages.alpha'));
        self::assertSame('other', $site->system->get('site.theme'));
    }

    public function testEnableIgnoresAModuleServiceThatIsNotTheRegistry(): void
    {
        $site = $this->open();
        $site->module('alpha', ['beta'], true);
        $site->register();
        $site->activate(['kept']);
        $site->app->set('module', new \stdClass());
        $site->package('alpha', ['title' => 'Alpha']);

        $tester = $this->enable($site, ['pagekit/alpha']);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());
        self::assertSame("\"Alpha\" enabled.\n", $tester->getDisplay(true));
        self::assertSame(['kept', 'alpha'], $site->system->get('extensions'));
        self::assertSame('1.0.0', $site->system->get('packages.alpha'));
        self::assertSame('other', $site->system->get('site.theme'));
        self::assertFalse($site->ran('alpha'));
    }

    /**
     * @param list<string> $extensions
     */
    private function open(array $extensions = ['kept']): ActivationCommandInstallation
    {
        return $this->site = new ActivationCommandInstallation($extensions);
    }

    /**
     * @param list<string> $names
     */
    private function enable(ActivationCommandInstallation $site, array $names): CommandTester
    {
        $tester = new CommandTester(new EnableCommand($site->app));
        $tester->execute(['packages' => $names]);

        return $tester;
    }

    private function refusal(callable $call): \RuntimeException
    {
        try {
            $call();
        } catch (\RuntimeException $exception) {
            return $exception;
        }

        self::fail('The command was expected to refuse.');
    }
}
