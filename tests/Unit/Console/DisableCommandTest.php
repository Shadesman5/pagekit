<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Console;

use Pagekit\Application;
use Pagekit\Console\Commands\DisableCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The disable command prints a blocked removal and leaves the extension list unchanged.
 */
final class DisableCommandTest extends TestCase
{
    private ?ActivationCommandInstallation $site = null;

    protected function tearDown(): void
    {
        $this->site?->remove();
    }

    public function testThereIsNoForceOption(): void
    {
        $command = new DisableCommand(new Application());

        self::assertFalse($command->getDefinition()->hasOption('force'));
    }

    public function testABlockedPackageIsPrintedAndExtensionsStay(): void
    {
        $site = $this->open(['blog', 'comments']);
        $site->module('blog');
        $site->module('comments', ['blog']);
        $site->register();
        $site->package('blog', ['title' => 'Blog']);
        $site->package('comments', ['title' => 'Comments']);

        $tester = $this->disable($site, ['pagekit/blog']);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertSame(
            "\"comments\" requires \"blog\", so it cannot be switched off.\n",
            $tester->getDisplay(true),
        );
        self::assertSame(['blog', 'comments'], $site->system->get('extensions'));
        self::assertSame('other', $site->system->get('site.theme'));
    }

    public function testABlockedPairIsRefusedBeforeEitherChanges(): void
    {
        $site = $this->open(['blog', 'comments']);
        $site->module('blog');
        $site->module('comments', ['blog']);
        $site->register();
        $site->package('blog', ['title' => 'Blog']);
        $site->package('comments', ['title' => 'Comments']);

        $tester = $this->disable($site, ['pagekit/blog', 'pagekit/comments']);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertSame(
            "\"comments\" require \"blog, comments\", so nothing was switched off.\n",
            $tester->getDisplay(true),
        );
        self::assertSame(['blog', 'comments'], $site->system->get('extensions'));
        self::assertSame('other', $site->system->get('site.theme'));
    }

    public function testAnUnknownNameIsRefusedBeforeAnyPackageChanges(): void
    {
        $site = $this->open(['blog', 'kept']);
        $site->module('blog');
        $site->register();
        $site->package('blog', ['title' => 'Blog']);

        $failure = $this->refusal(fn () => $this->disable($site, ['pagekit/blog', 'pagekit/absent']));

        self::assertSame('Unable to find "pagekit/absent".', $failure->getMessage());
        self::assertSame(['blog', 'kept'], $site->system->get('extensions'));
        self::assertSame('other', $site->system->get('site.theme'));
    }

    public function testAPackageIsDisabledUnderItsTitle(): void
    {
        $site = $this->open(['blog', 'kept']);
        $site->module('blog');
        $site->register();
        $site->package('blog', ['title' => 'Blog']);

        $tester = $this->disable($site, ['pagekit/blog']);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());
        self::assertSame("\"Blog\" disabled.\n", $tester->getDisplay(true));
        self::assertSame(['kept'], $site->system->get('extensions'));
        self::assertSame('other', $site->system->get('site.theme'));
    }

    public function testAnEmptyTitleIsReplacedByThePackageName(): void
    {
        $site = $this->open(['blog', 'kept']);
        $site->module('blog');
        $site->register();
        $site->package('blog', ['title' => '']);

        $tester = $this->disable($site, ['pagekit/blog']);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());
        self::assertSame("\"pagekit/blog\" disabled.\n", $tester->getDisplay(true));
        self::assertSame(['kept'], $site->system->get('extensions'));
    }

    public function testACatalogueThatIsNotAFactoryIsRefused(): void
    {
        $site = $this->open(['blog']);
        $site->app->set('package', new \stdClass());

        $failure = $this->refusal(fn () => $this->disable($site, ['pagekit/blog']));

        self::assertSame('The package catalogue is not available.', $failure->getMessage());
        self::assertSame(['blog'], $site->system->get('extensions'));
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
    private function disable(ActivationCommandInstallation $site, array $names): CommandTester
    {
        $tester = new CommandTester(new DisableCommand($site->app));
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
