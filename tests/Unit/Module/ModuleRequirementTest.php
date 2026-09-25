<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Module;

use Pagekit\Application;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Module\UnsatisfiedRequirementException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A requirement that cannot be loaded refuses the module that named it.
 */
final class ModuleRequirementTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_module_requirement_' . getmypid() . '_' . uniqid();

        if (!mkdir($this->workspace, 0755, true) && !is_dir($this->workspace)) {
            self::fail('The fixture workspace could not be created.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testAnUnregisteredRequirementNamesTheDependerAndDoesNotLoadIt(): void
    {
        $manager = $this->manager();
        $manager->register([
            $this->declareModule('alpha', ['beta']),
            $this->declareModule('beta', ['missing']),
        ]);

        try {
            $manager->load('alpha');
            self::fail('A module whose requirement is not registered has to be refused.');
        } catch (UnsatisfiedRequirementException $e) {
            self::assertFalse($e->registered);
            self::assertSame('beta', $e->depender);
            self::assertSame('missing', $e->requirement);
            self::assertSame(
                'Module "%depender%" requires "%required%", which is not registered.',
                $e->messageId(),
            );
            self::assertSame(
                'Module "beta" requires "missing", which is not registered.',
                $e->getMessage(),
            );
        }

        self::assertNull($manager->get('alpha'));
        self::assertNull($manager->get('beta'));
    }

    public function testADisabledRequirementNamesBothModulesAndDoesNotLoadEither(): void
    {
        $manager = $this->manager();
        $manager->register([
            $this->declareModule('blog', ['comments']),
            $this->declareModule('comments'),
        ]);
        $manager->setActivityPolicy(['blog'], 'system');

        try {
            $manager->load('blog');
            self::fail('A requirement that is switched off has to be refused.');
        } catch (UnsatisfiedRequirementException $e) {
            self::assertTrue($e->registered);
            self::assertSame('blog', $e->depender);
            self::assertSame('comments', $e->requirement);
            self::assertSame(
                'Module "%depender%" requires "%required%", which is registered but disabled.',
                $e->messageId(),
            );
            self::assertSame(
                'Module "blog" requires "comments", which is registered but disabled.',
                $e->getMessage(),
            );
        }

        self::assertNull($manager->get('blog'));
        self::assertNull($manager->get('comments'));
    }

    public function testARegisteredRequirementLoadsBeforeAnActivityPolicyExists(): void
    {
        $manager = $this->manager();
        $manager->register([
            $this->declareModule('system', ['user']),
            $this->declareModule('user'),
        ]);

        $manager->load('system');

        self::assertInstanceOf(Module::class, $manager->get('system'));
        self::assertInstanceOf(Module::class, $manager->get('user'));
    }

    public function testLoadingTheBootModuleStillThrowsWhenACoreRequirementIsNotRegistered(): void
    {
        $manager = $this->manager();
        $manager->register([$this->declareModule('system', ['missing-core'])]);

        try {
            $manager->load('system');
            self::fail('A core requirement that is not registered has to fail the load.');
        } catch (UnsatisfiedRequirementException $e) {
            self::assertSame('system', $e->depender);
            self::assertSame('missing-core', $e->requirement);
            self::assertFalse($e->registered);
            self::assertSame(
                'Module "system" requires "missing-core", which is not registered.',
                $e->getMessage(),
            );
        }

        self::assertNull($manager->get('system'));
    }

    public function testARequirementOfTheBootModuleStaysActiveWhenItIsNotEnabled(): void
    {
        $manager = $this->manager();
        $manager->register([
            $this->declareModule('system', ['user']),
            $this->declareModule('user'),
            $this->declareModule('blog', ['user']),
        ]);
        $manager->setActivityPolicy(['blog'], 'system');

        $manager->load('blog');

        self::assertInstanceOf(Module::class, $manager->get('user'));
        self::assertInstanceOf(Module::class, $manager->get('blog'));
        self::assertNull($manager->get('system'));
    }

    public function testAnActiveCycleThrowsTheCircularRequirementAndLoadsNothing(): void
    {
        $manager = $this->manager();
        $manager->register([
            $this->declareModule('alpha', ['beta']),
            $this->declareModule('beta', ['alpha']),
            $this->declareModule('gamma'),
        ]);
        $manager->setActivityPolicy(['alpha', 'beta'], 'system');

        try {
            $manager->load('alpha');
            self::fail('A cycle among active modules has to be refused.');
        } catch (\RuntimeException $e) {
            self::assertSame(\RuntimeException::class, $e::class);
            self::assertSame('Circular requirement "beta > alpha" detected.', $e->getMessage());
        }

        self::assertNull($manager->get('alpha'));
        self::assertNull($manager->get('beta'));
        self::assertNull($manager->get('gamma'));
    }

    public function testACycleBehindADisabledModuleIsReportedAsDisabled(): void
    {
        $manager = $this->manager();
        $manager->register([
            $this->declareModule('alpha', ['beta']),
            $this->declareModule('beta', ['gamma']),
            $this->declareModule('gamma', ['beta']),
        ]);
        $manager->setActivityPolicy(['alpha'], 'system');

        try {
            $manager->load('alpha');
            self::fail('A disabled module has to be refused without being walked.');
        } catch (UnsatisfiedRequirementException $e) {
            self::assertTrue($e->registered);
            self::assertSame('alpha', $e->depender);
            self::assertSame('beta', $e->requirement);
            self::assertSame(
                'Module "alpha" requires "beta", which is registered but disabled.',
                $e->getMessage(),
            );
        }

        self::assertNull($manager->get('alpha'));
        self::assertNull($manager->get('beta'));
        self::assertNull($manager->get('gamma'));
    }

    public function testLoadOfAnUnknownNameThrowsUndefinedModule(): void
    {
        $manager = $this->manager();
        $manager->setActivityPolicy([], 'system');

        try {
            $manager->load('missing');
            self::fail('A name that was asked for and is not registered has to throw.');
        } catch (\RuntimeException $e) {
            self::assertSame(\RuntimeException::class, $e::class);
            self::assertSame('Undefined module: missing', $e->getMessage());
        }
    }

    public function testCheckingAnUnknownNameDoesNotThrowUndefinedModule(): void
    {
        $manager = $this->manager();
        $manager->register([$this->declareModule('alpha')]);
        $manager->setActivityPolicy(['alpha'], 'system');

        $manager->assertRequirements('missing');
        $manager->load('alpha');

        self::assertInstanceOf(Module::class, $manager->get('alpha'));
        self::assertNull($manager->get('missing'));
    }

    public function testRequiredByListsEachDirectDependerOnceInRegistrationOrder(): void
    {
        $manager = $this->manager();
        $manager->register([
            $this->declareModule('alpha', ['shared']),
            $this->declareModule('beta', ['shared', 'shared']),
            $this->declareModule('gamma', ['beta']),
            $this->declareModule('orphan', ['missing']),
            $this->declareModule('shared'),
        ]);
        // alpha is enabled and beta is not; both still count.
        $manager->setActivityPolicy(['alpha'], 'system');

        self::assertSame(['alpha', 'beta'], $manager->requiredBy('shared'));
        self::assertSame(['gamma'], $manager->requiredBy('beta'));
        self::assertSame(['orphan'], $manager->requiredBy('missing'));
        self::assertSame([], $manager->requiredBy('nobody'));

        $manager->register([$this->declareModule('delta', ['shared'])]);

        self::assertSame(['alpha', 'beta', 'delta'], $manager->requiredBy('shared'));
        self::assertSame(['orphan'], $manager->requiredBy('missing'));
    }

    public function testRegisteringAgainRebuildsTheAlwaysLoadedClosure(): void
    {
        $manager = $this->manager();
        $system = $this->declareModule('system');
        $manager->register([
            $system,
            $this->declareModule('user'),
            $this->declareModule('needs-user', ['user']),
        ]);
        $manager->setActivityPolicy(['needs-user'], 'system');

        try {
            $manager->load('needs-user');
            self::fail('user is not in the boot module closure yet.');
        } catch (UnsatisfiedRequirementException $e) {
            self::assertTrue($e->registered);
            self::assertSame('user', $e->requirement);
        }

        self::assertNull($manager->get('needs-user'));
        self::assertNull($manager->get('user'));

        $this->declareModule('system', ['user']);
        $manager->register([$system]);
        $manager->load('needs-user');

        self::assertInstanceOf(Module::class, $manager->get('user'));
        self::assertInstanceOf(Module::class, $manager->get('needs-user'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function requirementsThatAreNotModuleNames(): iterable
    {
        yield 'empty string' => ["['']", ''];
        yield 'integer' => ['[42]', 'int'];
    }

    #[DataProvider('requirementsThatAreNotModuleNames')]
    public function testARequirementThatIsNotAModuleNameIsRefused(string $requirePhp, string $requirement): void
    {
        $manager = $this->manager();
        $manager->register([$this->writeModule('counted', $requirePhp)]);

        try {
            $manager->load('counted');
            self::fail('A requirement that is not a module name has to be refused.');
        } catch (UnsatisfiedRequirementException $e) {
            self::assertFalse($e->registered);
            self::assertSame('counted', $e->depender);
            self::assertSame($requirement, $e->requirement);
            self::assertSame(
                'Module "%depender%" requires "%required%", which is not registered.',
                $e->messageId(),
            );
            self::assertSame(strtr($e->messageId(), [
                '%depender%' => 'counted',
                '%required%' => $requirement,
            ]), $e->getMessage());
        }

        self::assertNull($manager->get('counted'));
    }

    public function testAnOverlaidRequireIsRestoredAndTheRegisteredListIsWhatLoads(): void
    {
        $manager = $this->manager();
        $manager->register([
            $this->declareModule('alpha', ['beta']),
            $this->declareModule('beta'),
            $this->declareModule('gamma'),
        ]);
        // gamma is active, so the overlay can succeed. The registered list still names beta.
        $manager->setActivityPolicy(['alpha', 'beta', 'gamma'], 'system');

        self::assertSame(['alpha'], $manager->requiredBy('beta'));
        self::assertSame([], $manager->requiredBy('gamma'));
        self::assertFalse($manager->isRegistered('ghost'));

        $manager->assertRequirementsUsing('alpha', ['gamma']);

        self::assertTrue($manager->isRegistered('alpha'));
        self::assertTrue($manager->isRegistered('beta'));
        self::assertTrue($manager->isRegistered('gamma'));
        self::assertFalse($manager->isRegistered('ghost'));
        self::assertSame(['alpha'], $manager->requiredBy('beta'));
        self::assertSame([], $manager->requiredBy('gamma'));

        $manager->load('alpha');

        self::assertInstanceOf(Module::class, $manager->get('alpha'));
        self::assertInstanceOf(Module::class, $manager->get('beta'));
        self::assertNull($manager->get('gamma'));
    }

    public function testADisabledOverlayIsRestoredAfterItThrows(): void
    {
        $manager = $this->manager();
        $manager->register([
            $this->declareModule('alpha'),
            $this->declareModule('comments'),
        ]);
        $manager->setActivityPolicy(['alpha'], 'system');

        self::assertSame([], $manager->requiredBy('comments'));

        try {
            $manager->assertRequirementsUsing('alpha', ['comments']);
            self::fail('A disabled requirement has to be refused.');
        } catch (UnsatisfiedRequirementException $e) {
            self::assertTrue($e->registered);
            self::assertSame('alpha', $e->depender);
            self::assertSame('comments', $e->requirement);
            self::assertSame(
                'Module "alpha" requires "comments", which is registered but disabled.',
                $e->getMessage(),
            );
        }

        self::assertTrue($manager->isRegistered('alpha'));
        self::assertTrue($manager->isRegistered('comments'));
        self::assertSame([], $manager->requiredBy('comments'));

        $manager->assertRequirements('alpha');
        $manager->load('alpha');

        self::assertInstanceOf(Module::class, $manager->get('alpha'));
        self::assertNull($manager->get('comments'));
    }

    public function testAnOverlaidCycleIsRestoredAfterItThrows(): void
    {
        $manager = $this->manager();
        $manager->register([
            $this->declareModule('alpha'),
            $this->declareModule('beta', ['alpha']),
        ]);
        $manager->setActivityPolicy(['alpha', 'beta'], 'system');

        self::assertSame(['beta'], $manager->requiredBy('alpha'));
        self::assertSame([], $manager->requiredBy('beta'));

        try {
            $manager->assertRequirementsUsing('alpha', ['beta']);
            self::fail('A cycle has to be refused.');
        } catch (\RuntimeException $e) {
            self::assertSame(\RuntimeException::class, $e::class);
            self::assertSame('Circular requirement "beta > alpha" detected.', $e->getMessage());
        }

        self::assertTrue($manager->isRegistered('alpha'));
        self::assertTrue($manager->isRegistered('beta'));
        self::assertSame(['beta'], $manager->requiredBy('alpha'));
        self::assertSame([], $manager->requiredBy('beta'));

        $manager->assertRequirements('alpha');
        $manager->load('alpha');

        self::assertInstanceOf(Module::class, $manager->get('alpha'));
        self::assertNull($manager->get('beta'));
    }

    public function testARequireCheckedForAnUnknownNameDoesNotRegisterIt(): void
    {
        $manager = $this->manager();
        $manager->register([
            $this->declareModule('system'),
        ]);
        $manager->setActivityPolicy([], 'system');

        self::assertFalse($manager->isRegistered('ghost'));
        self::assertSame([], $manager->requiredBy('system'));

        $manager->assertRequirementsUsing('ghost', ['system']);

        self::assertFalse($manager->isRegistered('ghost'));
        self::assertTrue($manager->isRegistered('system'));
        self::assertSame([], $manager->requiredBy('system'));
        self::assertSame([], $manager->requiredBy('ghost'));
    }

    public function testAMissingRequireForAnUnknownNameDoesNotRegisterIt(): void
    {
        $manager = $this->manager();
        $manager->register([
            $this->declareModule('system'),
        ]);
        $manager->setActivityPolicy([], 'system');

        try {
            $manager->assertRequirementsUsing('ghost', ['missing']);
            self::fail('A requirement that is not registered has to be refused.');
        } catch (UnsatisfiedRequirementException $e) {
            self::assertFalse($e->registered);
            self::assertSame('ghost', $e->depender);
            self::assertSame('missing', $e->requirement);
            self::assertSame(
                'Module "ghost" requires "missing", which is not registered.',
                $e->getMessage(),
            );
        }

        self::assertFalse($manager->isRegistered('ghost'));
        self::assertTrue($manager->isRegistered('system'));
        self::assertSame([], $manager->requiredBy('missing'));
        self::assertSame([], $manager->requiredBy('system'));
    }

    public function testAnOverlaidCycleForAnUnknownNameDoesNotRegisterIt(): void
    {
        $manager = $this->manager();
        $manager->register([
            $this->declareModule('beta', ['ghost']),
        ]);

        // A policy would mark the temporary name disabled, so the cycle would not be reached.
        self::assertFalse($manager->isRegistered('ghost'));
        self::assertSame(['beta'], $manager->requiredBy('ghost'));
        self::assertSame([], $manager->requiredBy('beta'));

        try {
            $manager->assertRequirementsUsing('ghost', ['beta']);
            self::fail('A cycle has to be refused.');
        } catch (\RuntimeException $e) {
            self::assertSame(\RuntimeException::class, $e::class);
            self::assertSame('Circular requirement "beta > ghost" detected.', $e->getMessage());
        }

        self::assertFalse($manager->isRegistered('ghost'));
        self::assertTrue($manager->isRegistered('beta'));
        self::assertSame(['beta'], $manager->requiredBy('ghost'));
        self::assertSame([], $manager->requiredBy('beta'));
    }

    private function manager(): ModuleManager
    {
        return new ModuleManager(new Application());
    }

    /**
     * @param list<string> $require
     */
    private function declareModule(string $name, array $require = []): string
    {
        return $this->writeModule($name, var_export(array_values($require), true));
    }

    /**
     * Writes a manifest whose require value is the given PHP expression.
     */
    private function writeModule(string $name, string $requirePhp): string
    {
        $directory = $this->workspace . '/' . $name;

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            self::fail('The fixture module directory could not be created.');
        }

        $file = $directory . '/index.php';
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'name' => " . var_export($name, true) . ",\n    'require' => " . $requirePhp . ",\n];\n";

        if (file_put_contents($file, $contents) === false) {
            self::fail('The fixture module could not be written.');
        }

        return $file;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }

        rmdir($path);
    }
}
