<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Module;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * An import of a Pagekit class must reach the module that contains the file.
 */
final class ModuleImportEdgeTest extends TestCase
{
    /** @var list<string> */
    private array $workspaces = [];

    private ?Parser $parser = null;

    /**
     * @var array{
     *     modules: array<string, array{dir: string, require: list<string>, manifest: string}>,
     *     references: list<array{importer: string, class: string, file: string}>,
     *     edges: list<array{importer: string, owner: string, class: string, file: string}>,
     *     violations: list<string>
     * }|null
     */
    private ?array $repositoryGraph = null;

    protected function tearDown(): void
    {
        foreach ($this->workspaces as $workspace) {
            $this->removeTree($workspace);
        }
    }

    public function testEveryImportReachesTheOwningModule(): void
    {
        $graph = $this->repository();

        self::assertArrayHasKey('application', $graph['modules']);
        self::assertArrayHasKey('kernel', $graph['modules']);
        self::assertArrayHasKey('database', $graph['modules']);
        self::assertArrayHasKey('routing', $graph['modules']);
        self::assertArrayHasKey('system', $graph['modules']);
        self::assertArrayHasKey('system/site', $graph['modules']);
        self::assertArrayHasKey('system/user', $graph['modules']);
        self::assertArrayHasKey('system/widget', $graph['modules']);
        self::assertArrayHasKey('console', $graph['modules']);
        self::assertArrayHasKey('installer', $graph['modules']);
        self::assertArrayHasKey('package', $graph['modules']);
        self::assertNotEmpty($graph['edges']);

        foreach ($graph['references'] as $reference) {
            self::assertDoesNotMatchRegularExpression('#/(?:Tests|tests)/#', $reference['file']);
        }

        self::assertSame([], $graph['violations']);
    }

    public function testSiteUserAndWidgetRequireRoutingAndDoNotImportSystem(): void
    {
        $graph = $this->repository();

        foreach (['system/site', 'system/user', 'system/widget'] as $name) {
            self::assertContains('routing', $graph['modules'][$name]['require'], $name);

            foreach ($graph['references'] as $reference) {
                if ($reference['importer'] !== $name) {
                    continue;
                }

                self::assertFalse(
                    $reference['class'] === 'Pagekit\\System' || str_starts_with($reference['class'], 'Pagekit\\System\\'),
                    $reference['class'].' in '.$reference['file'],
                );
            }
        }
    }

    public function testSiteUserAndWidgetRequireDatabaseAndDoNotImportSystemModel(): void
    {
        $graph = $this->repository();

        foreach (['system/site', 'system/user', 'system/widget'] as $name) {
            self::assertContains('database', $graph['modules'][$name]['require'], $name);

            foreach ($graph['references'] as $reference) {
                if ($reference['importer'] !== $name) {
                    continue;
                }

                self::assertFalse(
                    $reference['class'] === 'Pagekit\\System\\Model' || str_starts_with($reference['class'], 'Pagekit\\System\\Model\\'),
                    $reference['class'].' in '.$reference['file'],
                );
            }
        }
    }

    public function testNodeTypesAreImportedOnlyBySite(): void
    {
        foreach ($this->repository()['references'] as $reference) {
            if ($reference['importer'] === 'system/site') {
                continue;
            }

            self::assertNotSame('Pagekit\\Site\\Model\\NodeInterface', $reference['class'], $reference['file']);
            self::assertNotSame('Pagekit\\Site\\Model\\NodeTrait', $reference['class'], $reference['file']);
        }
    }

    public function testUserImportsUniqueFromDatabaseWithoutADirectSystemRequire(): void
    {
        $graph = $this->repository();

        self::assertNotContains('database', $graph['modules']['system']['require']);
        self::assertContains('application', $graph['modules']['system']['require']);
        self::assertContains('database', $graph['modules']['application']['require']);
        self::assertArrayHasKey('database', $this->closure('system', $this->requireMap($graph['modules'])));

        self::assertTrue($this->hasEdge(
            $graph,
            'app/system/modules/user/src/Model/User.php',
            'Pagekit\\Database\\Validator\\Constraints\\Unique',
            'database',
        ));
        self::assertTrue($this->hasEdge(
            $graph,
            'app/system/src/ValidatorServiceProvider.php',
            'Pagekit\\Database\\Validator\\Constraints\\UniqueValidator',
            'database',
        ));
    }

    public function testCommentSiteUserAndWidgetRequireDatabase(): void
    {
        $graph = $this->repository();

        foreach (['system/comment', 'system/site', 'system/user', 'system/widget'] as $name) {
            self::assertContains('database', $graph['modules'][$name]['require'], $name);
        }
    }

    public function testAnOrmNamespaceImportIsNotAClassEdge(): void
    {
        $graph = $this->repository();
        $file = 'app/modules/database/src/ORM/DataModelTrait.php';

        self::assertContains('Pagekit\\Database\\ORM\\Attribute', $this->namesIn($graph['references'], $file));
        self::assertContains('Pagekit\\Database\\ORM\\Attribute\\Column', $this->namesIn($graph['references'], $file));
        self::assertNotContains('Pagekit\\Database\\ORM\\Attribute', $this->namesIn($graph['edges'], $file));
        self::assertNotContains('Pagekit\\Database\\ORM\\Attribute\\Column', $this->namesIn($graph['edges'], $file));
        self::assertContains('Pagekit\\Util\\Arr', $this->namesIn($graph['edges'], $file));
    }

    public function testAFunctionImportAddsNoRequire(): void
    {
        $graph = $this->repository();
        $file = 'app/system/modules/settings/src/Controller/SettingsController.php';
        $source = file_get_contents($this->root().'/'.$file);

        self::assertIsString($source);
        self::assertStringContainsString('use function Pagekit\\__;', $source);
        self::assertNotContains('system/intl', $graph['modules']['system/settings']['require']);
        self::assertNotContains('Pagekit\\__', $this->namesIn($graph['references'], $file));
        self::assertNotContains('Pagekit\\Intl\\IntlServiceLocator', $this->namesIn($graph['references'], $file));
    }

    public function testAMissingRequireIsReportedUntilTheManifestNamesIt(): void
    {
        $root = $this->workspace();
        $this->writeModule($root, 'app/modules/alpha', 'alpha', []);
        $this->writeModule($root, 'app/modules/beta', 'beta', []);
        $this->write($root, 'app/modules/beta/src/Tool.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Beta;

class Tool
{
}
PHP);
        $this->write($root, 'app/modules/alpha/src/Reader.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Alpha;

use Pagekit\Beta\Tool;

class Reader
{
}
PHP);

        self::assertSame([
            'alpha imports Pagekit\\Beta\\Tool (app/modules/alpha/src/Reader.php), owned by beta, which is not reachable through require',
        ], $this->graph($root)['violations']);

        $this->writeModule($root, 'app/modules/alpha', 'alpha', ['beta']);

        self::assertSame([], $this->graph($root)['violations']);
    }

    public function testATransitiveRequireSatisfiesAnImport(): void
    {
        $root = $this->workspace();
        $this->writeModule($root, 'app/modules/alpha', 'alpha', ['beta']);
        $this->writeModule($root, 'app/modules/beta', 'beta', ['gamma']);
        $this->writeModule($root, 'app/modules/gamma', 'gamma', []);
        $this->write($root, 'app/modules/gamma/src/Widget.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Gamma;

class Widget
{
}
PHP);
        $this->write($root, 'app/modules/alpha/src/Reader.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Alpha;

use Pagekit\Gamma\Widget;

class Reader
{
}
PHP);

        self::assertSame([], $this->graph($root)['violations']);
    }

    public function testAParentImportStaysAViolationWhenTheRequireIsAdded(): void
    {
        $root = $this->workspace();
        $this->writeModule($root, 'app/system', 'system', ['system/user']);
        $this->writeModule($root, 'app/system/modules/user', 'system/user', []);
        $this->write($root, 'app/system/src/Owned.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\System;

class Owned
{
}
PHP);
        $this->write($root, 'app/system/modules/user/src/Needs.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\User;

use Pagekit\System\Owned;

class Needs
{
}
PHP);

        $violation = 'system/user imports Pagekit\\System\\Owned (app/system/modules/user/src/Needs.php), owned by system, which already requires system/user';

        self::assertSame([$violation], $this->graph($root)['violations']);

        $this->writeModule($root, 'app/system/modules/user', 'system/user', ['system']);

        self::assertSame([$violation], $this->graph($root)['violations']);
    }

    public function testAParentImportInTestCodeIsIgnored(): void
    {
        $root = $this->workspace();
        $this->writeModule($root, 'app/modules/application', 'application', ['database']);
        $this->writeModule($root, 'app/modules/database', 'database', []);
        $this->write($root, 'app/modules/application/src/Application.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Application;

class Application
{
}
PHP);
        $import = <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Database;

use Pagekit\Application\Application;

class ImportsApplication
{
}
PHP;
        $this->write($root, 'app/modules/database/src/Tests/ImportsApplication.php', $import);
        $this->write($root, 'app/modules/database/src/tests/ImportsApplication.php', $import);

        self::assertSame([], $this->graph($root)['violations']);

        $this->write($root, 'app/modules/database/src/ImportsApplication.php', $import);

        self::assertSame([
            'database imports Pagekit\\Application\\Application (app/modules/database/src/ImportsApplication.php), owned by application, which already requires database',
        ], $this->graph($root)['violations']);
    }

    public function testFunctionAndConstImportsAreNotEdges(): void
    {
        $root = $this->workspace();
        $this->writeModule($root, 'app/system/modules/intl', 'system/intl', []);
        $this->writeModule($root, 'app/system/modules/settings', 'system/settings', []);
        $this->write($root, 'app/system/modules/intl/src/Locale.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Intl;

const LOCALE = 'en';

class Locale
{
}
PHP);
        $this->write($root, 'app/system/modules/intl/functions.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit;

function __(string $id): string
{
    return $id;
}
PHP);
        $this->write($root, 'app/system/modules/settings/src/Controller.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Settings;

use const Pagekit\Intl\LOCALE;
use function Pagekit\__;

class Controller
{
    public function index(): string
    {
        return __(LOCALE);
    }
}
PHP);

        $graph = $this->graph($root);

        self::assertSame([], $graph['modules']['system/settings']['require']);
        self::assertSame([], $graph['violations']);
        self::assertNotContains('Pagekit\\__', array_column($graph['references'], 'class'));
        self::assertNotContains('Pagekit\\Intl\\LOCALE', array_column($graph['references'], 'class'));
    }

    public function testANamespaceImportIsAnEdgeOnlyThroughAnAttributeClass(): void
    {
        $root = $this->workspace();
        $this->writeModule($root, 'app/modules/database', 'database', []);
        $this->writeModule($root, 'app/system/modules/comment', 'system/comment', []);
        $this->write($root, 'app/modules/database/src/Entity.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

class Entity
{
}
PHP);
        $this->write($root, 'app/modules/database/src/Row.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM;

use Pagekit\Database\ORM\Attribute as ORM;

#[ORM\Entity]
class Row
{
}
PHP);
        $this->write($root, 'app/system/modules/comment/src/Comment.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Comment\Model;

use Pagekit\Database\ORM\Attribute as ORM;

#[ORM\Entity]
class Comment
{
}
PHP);

        $owner = $this->graph($root);

        self::assertSame([], $this->namesIn($owner['edges'], 'app/modules/database/src/Row.php'));
        self::assertContains('Pagekit\\Database\\ORM\\Attribute', $this->namesIn($owner['references'], 'app/modules/database/src/Row.php'));
        self::assertSame([
            'system/comment imports Pagekit\\Database\\ORM\\Attribute\\Entity (app/system/modules/comment/src/Comment.php), owned by database, which is not reachable through require',
        ], $owner['violations']);

        $this->writeModule($root, 'app/system/modules/comment', 'system/comment', ['database']);

        self::assertSame([], $this->graph($root)['violations']);
    }

    public function testAnAttributeNameIsAnEdgeWithoutASeparateUse(): void
    {
        $root = $this->workspace();
        $this->writeModule($root, 'app/modules/routing', 'routing', []);
        $this->writeModule($root, 'app/modules/screen', 'screen', []);
        $this->write($root, 'app/modules/routing/src/Access.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Routing\Attribute;

class Access
{
}

class Route
{
}
PHP);
        $this->write($root, 'app/modules/screen/src/Screen.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Screen;

use Pagekit\Routing\Attribute\Access as Guard;

#[Guard('a')]
#[\Pagekit\Routing\Attribute\Route('/')]
class Screen
{
}
PHP);

        self::assertSame([
            'screen imports Pagekit\\Routing\\Attribute\\Access (app/modules/screen/src/Screen.php), owned by routing, which is not reachable through require',
            'screen imports Pagekit\\Routing\\Attribute\\Route (app/modules/screen/src/Screen.php), owned by routing, which is not reachable through require',
        ], $this->graph($root)['violations']);
    }

    public function testAStringOrClassConstantProbeIsNotAnEdge(): void
    {
        $root = $this->workspace();
        $this->writeModule($root, 'app/modules/alpha', 'alpha', []);
        $this->writeModule($root, 'app/modules/beta', 'beta', []);
        $this->write($root, 'app/modules/beta/src/Tool.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Beta;

class Tool
{
}
PHP);
        $this->write($root, 'app/modules/alpha/src/Probe.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Alpha;

class Probe
{
    public function ok(string $name): bool
    {
        // use Pagekit\Beta\Tool;
        return class_exists('Pagekit\\Beta\\Tool')
            || $name === 'Pagekit\\Beta\\Tool'
            || class_exists(\Pagekit\Beta\Tool::class);
    }
}
PHP);

        $graph = $this->graph($root);

        self::assertSame([], $graph['violations']);
        self::assertNotContains('Pagekit\\Beta\\Tool', array_column($graph['references'], 'class'));
    }

    public function testAClassInTheSameModuleIsNotAnEdge(): void
    {
        $root = $this->workspace();
        $this->writeModule($root, 'app/modules/alpha', 'alpha', []);
        $this->write($root, 'app/modules/alpha/src/Reader.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Alpha;

use Pagekit\Alpha\Sibling;

class Reader
{
}

class Sibling
{
}
PHP);

        self::assertSame([], $this->graph($root)['violations']);
        self::assertSame([], $this->graph($root)['edges']);
    }

    public function testTheOwnerIsTheLongestModulePath(): void
    {
        $root = $this->workspace();
        $this->writeModule($root, 'app/system', 'system', []);
        $this->writeModule($root, 'app/system/modules/user', 'system/user', []);
        $this->writeModule($root, 'app/modules/reader', 'reader', []);
        $this->write($root, 'app/system/src/Top.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\System;

class Top
{
}
PHP);
        $this->write($root, 'app/system/modules/user/src/Account.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\User;

class Account
{
}
PHP);
        $this->write($root, 'app/modules/reader/src/Reader.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Pagekit\Reader;

use Pagekit\System\Top;
use Pagekit\User\Account;

class Reader
{
}
PHP);

        self::assertSame([
            'reader imports Pagekit\\System\\Top (app/modules/reader/src/Reader.php), owned by system, which is not reachable through require',
            'reader imports Pagekit\\User\\Account (app/modules/reader/src/Reader.php), owned by system/user, which is not reachable through require',
        ], $this->graph($root)['violations']);
    }

    /**
     * @param array{
     *     modules: array<string, array{dir: string, require: list<string>, manifest: string}>,
     *     references: list<array{importer: string, class: string, file: string}>,
     *     edges: list<array{importer: string, owner: string, class: string, file: string}>,
     *     violations: list<string>
     * } $graph
     */
    private function hasEdge(array $graph, string $file, string $class, string $owner): bool
    {
        foreach ($graph['edges'] as $edge) {
            if ($edge['file'] === $file && $edge['class'] === $class && $edge['owner'] === $owner) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{importer: string, class: string, file: string}> $rows
     *
     * @return list<string>
     */
    private function namesIn(array $rows, string $file): array
    {
        $names = [];

        foreach ($rows as $row) {
            if ($row['file'] === $file) {
                $names[] = $row['class'];
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @return array{
     *     modules: array<string, array{dir: string, require: list<string>, manifest: string}>,
     *     references: list<array{importer: string, class: string, file: string}>,
     *     edges: list<array{importer: string, owner: string, class: string, file: string}>,
     *     violations: list<string>
     * }
     */
    private function repository(): array
    {
        return $this->repositoryGraph ??= $this->graph($this->root());
    }

    /**
     * @return array{
     *     modules: array<string, array{dir: string, require: list<string>, manifest: string}>,
     *     references: list<array{importer: string, class: string, file: string}>,
     *     edges: list<array{importer: string, owner: string, class: string, file: string}>,
     *     violations: list<string>
     * }
     */
    private function graph(string $root): array
    {
        $root = rtrim(strtr($root, '\\', '/'), '/');
        $violations = [];
        $modules = [];

        foreach ($this->manifests($root) as $manifest) {
            $fields = $this->manifest($manifest, $violations, $root);

            if ($fields === null) {
                continue;
            }

            $name = $fields['name'];

            if (isset($modules[$name])) {
                $violations[] = 'module '.$name.' is declared in '.$modules[$name]['manifest'].' and '.$this->relative($root, $manifest);
            }

            $modules[$name] = [
                'dir' => dirname($manifest),
                'require' => $fields['require'],
                'manifest' => $this->relative($root, $manifest),
            ];
        }

        /** @var array<string, array{module: string, file: string}> $index */
        $index = [];
        $references = [];

        foreach ($modules as $name => $module) {
            foreach ($this->phpFiles($module['dir']) as $file) {
                if ($this->owner($file, $modules) !== $name) {
                    continue;
                }

                $code = file_get_contents($file);

                if ($code === false) {
                    $violations[] = $this->relative($root, $file).' could not be read';

                    continue;
                }

                try {
                    $described = $this->describe($code);
                } catch (Error $error) {
                    $violations[] = $this->relative($root, $file).' could not be parsed: '.$error->getMessage();

                    continue;
                }

                $relative = $this->relative($root, $file);

                foreach ($described['classes'] as $class) {
                    if (isset($index[$class]) && $index[$class]['file'] !== $relative) {
                        $violations[] = $class.' is defined in '.$index[$class]['file'].' and '.$relative;
                    }

                    $index[$class] = ['module' => $name, 'file' => $relative];
                }

                foreach ($described['names'] as $class) {
                    $references[] = ['importer' => $name, 'class' => $class, 'file' => $relative];
                }
            }
        }

        $edges = [];

        foreach ($references as $reference) {
            if (!isset($index[$reference['class']])) {
                continue;
            }

            $owner = $index[$reference['class']]['module'];

            if ($owner === $reference['importer']) {
                continue;
            }

            $edges[] = [
                'importer' => $reference['importer'],
                'owner' => $owner,
                'class' => $reference['class'],
                'file' => $reference['file'],
            ];
        }

        $violations = array_merge($violations, $this->edgeViolations($edges, $modules));
        $violations = array_values(array_unique($violations));
        sort($violations);

        return [
            'modules' => $modules,
            'references' => $references,
            'edges' => $edges,
            'violations' => $violations,
        ];
    }

    /**
     * @param list<array{importer: string, owner: string, class: string, file: string}> $edges
     * @param array<string, array{dir: string, require: list<string>, manifest: string}> $modules
     *
     * @return list<string>
     */
    private function edgeViolations(array $edges, array $modules): array
    {
        $requires = $this->requireMap($modules);
        $reached = [];

        foreach (array_keys($modules) as $name) {
            $reached[$name] = $this->closure($name, $requires);
        }

        $violations = [];

        foreach ($edges as $edge) {
            $importer = $edge['importer'];
            $owner = $edge['owner'];

            if (isset($reached[$owner][$importer])) {
                $violations[] = sprintf(
                    '%s imports %s (%s), owned by %s, which already requires %s',
                    $importer,
                    $edge['class'],
                    $edge['file'],
                    $owner,
                    $importer,
                );

                continue;
            }

            if (!isset($reached[$importer][$owner])) {
                $violations[] = sprintf(
                    '%s imports %s (%s), owned by %s, which is not reachable through require',
                    $importer,
                    $edge['class'],
                    $edge['file'],
                    $owner,
                );
            }
        }

        return $violations;
    }

    /**
     * @param array<string, array{require: list<string>}> $modules
     *
     * @return array<string, list<string>>
     */
    private function requireMap(array $modules): array
    {
        $requires = [];

        foreach ($modules as $name => $module) {
            $requires[$name] = $module['require'];
        }

        return $requires;
    }

    /**
     * @param array<string, list<string>> $requires
     *
     * @return array<string, true>
     */
    private function closure(string $from, array $requires): array
    {
        $seen = [];
        $pending = $requires[$from] ?? [];

        while ($pending !== []) {
            $next = array_pop($pending);

            if (isset($seen[$next])) {
                continue;
            }

            $seen[$next] = true;

            foreach ($requires[$next] ?? [] as $dependency) {
                $pending[] = $dependency;
            }
        }

        return $seen;
    }

    /**
     * @param array<string, array{dir: string}> $modules
     */
    private function owner(string $file, array $modules): ?string
    {
        $match = null;
        $length = -1;

        foreach ($modules as $name => $module) {
            $directory = $module['dir'];

            if ($file !== $directory && !str_starts_with($file, $directory.'/')) {
                continue;
            }

            if (strlen($directory) > $length) {
                $match = $name;
                $length = strlen($directory);
            }
        }

        return $match;
    }

    /**
     * @return list<string>
     */
    private function manifests(string $root): array
    {
        $paths = [];

        foreach ([
            '/app/modules/*/index.php',
            '/app/system/index.php',
            '/app/system/modules/*/index.php',
            '/app/package/index.php',
            '/app/installer/index.php',
            '/app/console/index.php',
        ] as $pattern) {
            foreach (glob($root.$pattern) ?: [] as $path) {
                $paths[] = strtr($path, '\\', '/');
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $path = strtr($file->getPathname(), '\\', '/');

            if (!str_ends_with($path, '.php') || $this->isTestPath($path)) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    private function isTestPath(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if ($segment === 'Tests' || $segment === 'tests') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $violations
     *
     * @return array{name: string, require: list<string>}|null
     */
    private function manifest(string $path, array &$violations, string $root): ?array
    {
        $code = file_get_contents($path);

        if ($code === false) {
            $violations[] = $this->relative($root, $path).' could not be read';

            return null;
        }

        try {
            $statements = $this->parser()->parse($code) ?? [];
        } catch (Error $error) {
            $violations[] = $this->relative($root, $path).' could not be parsed: '.$error->getMessage();

            return null;
        }

        $array = $this->manifestArray($statements);

        if ($array === null) {
            $violations[] = $this->relative($root, $path).' has no module manifest';

            return null;
        }

        $name = null;
        $require = null;
        $sawRequire = false;

        foreach ($array->items as $item) {
            if ($item === null || !$item->key instanceof String_) {
                continue;
            }

            if ($item->key->value === 'name' && $item->value instanceof String_) {
                $name = $item->value->value;
            }

            if ($item->key->value === 'require') {
                $sawRequire = true;
                $require = $this->literalStrings($item->value);
            }
        }

        if ($name === null || $name === '') {
            $violations[] = $this->relative($root, $path).' has no module name';

            return null;
        }

        if ($sawRequire && $require === null) {
            $violations[] = $name.' manifest require is not a list of module names';
            $require = [];
        }

        return ['name' => $name, 'require' => $require ?? []];
    }

    /**
     * @param list<Node\Stmt> $statements
     */
    private function manifestArray(array $statements): ?Array_
    {
        /** @var array<string, Array_> $assigned */
        $assigned = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_) {
                $found = $this->manifestArray($statement->stmts);

                if ($found !== null) {
                    return $found;
                }
            }

            if ($statement instanceof Expression && $statement->expr instanceof Assign) {
                $assign = $statement->expr;

                if ($assign->var instanceof Variable && is_string($assign->var->name) && $assign->expr instanceof Array_) {
                    $assigned[$assign->var->name] = $assign->expr;
                }
            }

            if (!$statement instanceof Return_) {
                continue;
            }

            if ($statement->expr instanceof Array_) {
                return $statement->expr;
            }

            if ($statement->expr instanceof Variable && is_string($statement->expr->name) && isset($assigned[$statement->expr->name])) {
                return $assigned[$statement->expr->name];
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function literalStrings(Node $node): ?array
    {
        if (!$node instanceof Array_) {
            return null;
        }

        $values = [];

        foreach ($node->items as $item) {
            if ($item === null || $item->unpack || !$item->value instanceof String_) {
                return null;
            }

            $values[] = $item->value->value;
        }

        return $values;
    }

    /**
     * @return array{classes: list<string>, names: list<string>}
     */
    private function describe(string $code): array
    {
        $statements = $this->parser()->parse($code) ?? [];
        $classes = [];
        $names = [];
        $this->collectClasses($statements, '', $classes);
        $this->collectReferences($statements, $names);
        $names = array_values(array_unique($names));
        sort($classes);
        sort($names);

        return ['classes' => $classes, 'names' => $names];
    }

    /**
     * @param list<Node> $nodes
     * @param list<string> $classes
     */
    private function collectClasses(array $nodes, string $namespace, array &$classes): void
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }

            $next = $namespace;

            if ($node instanceof Namespace_) {
                $next = $node->name !== null ? $node->name->toString() : '';
            } elseif ($node instanceof ClassLike && $node->name !== null) {
                $classes[] = $next === '' ? $node->name->toString() : $next.'\\'.$node->name->toString();
            }

            foreach ($node->getSubNodeNames() as $sub) {
                $value = $node->{$sub};

                if ($value instanceof Node) {
                    $this->collectClasses([$value], $next, $classes);
                } elseif (is_array($value)) {
                    $this->collectClasses($value, $next, $classes);
                }
            }
        }
    }

    /**
     * @param list<Node\Stmt> $statements
     * @param list<string> $names
     */
    private function collectReferences(array $statements, array &$names): void
    {
        foreach ($this->segments($statements) as $segment) {
            $aliases = $this->aliases($segment['stmts']);

            foreach ($aliases as $target) {
                $this->recordPagekit($target, $names);
            }

            $this->collectNames($segment['stmts'], $segment['namespace'], $aliases, $names);
        }
    }

    /**
     * @param list<Node\Stmt> $statements
     *
     * @return list<array{namespace: string, stmts: list<Node\Stmt>}>
     */
    private function segments(array $statements): array
    {
        $segments = [];
        $pending = [];
        $sawNamespace = false;

        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_) {
                if ($pending !== []) {
                    $segments[] = ['namespace' => '', 'stmts' => $pending];
                    $pending = [];
                }

                $sawNamespace = true;
                $segments[] = [
                    'namespace' => $statement->name !== null ? $statement->name->toString() : '',
                    'stmts' => $statement->stmts,
                ];

                continue;
            }

            $pending[] = $statement;
        }

        if ($pending !== [] || !$sawNamespace) {
            $segments[] = ['namespace' => '', 'stmts' => $pending];
        }

        return $segments;
    }

    /**
     * @param list<Node\Stmt> $statements
     *
     * @return array<string, string>
     */
    private function aliases(array $statements): array
    {
        $aliases = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Use_) {
                foreach ($statement->uses as $use) {
                    if (!$this->isClassUse($statement->type, $use->type)) {
                        continue;
                    }

                    $aliases[$use->getAlias()->toString()] = $use->name->toString();
                }

                continue;
            }

            if (!$statement instanceof GroupUse) {
                continue;
            }

            foreach ($statement->uses as $use) {
                if (!$this->isClassUse($statement->type, $use->type)) {
                    continue;
                }

                $combined = Name::concat($statement->prefix, $use->name);

                if (!$combined instanceof Name) {
                    continue;
                }

                $aliases[$use->getAlias()->toString()] = $combined->toString();
            }
        }

        return $aliases;
    }

    private function isClassUse(int $statementType, int $itemType): bool
    {
        $type = $itemType !== Use_::TYPE_UNKNOWN ? $itemType : $statementType;

        return $type === Use_::TYPE_UNKNOWN || $type === Use_::TYPE_NORMAL;
    }

    /**
     * @param list<Node> $nodes
     * @param array<string, string> $aliases
     * @param list<string> $names
     */
    private function collectNames(array $nodes, string $namespace, array $aliases, array &$names): void
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Node || $node instanceof Namespace_) {
                continue;
            }

            if ($node instanceof Attribute) {
                $this->recordPagekit($this->resolveName($node->name, $namespace, $aliases), $names);
            } elseif ($node instanceof TraitUse) {
                foreach ($node->traits as $trait) {
                    $this->recordPagekit($this->resolveName($trait, $namespace, $aliases), $names);
                }
            }

            foreach ($node->getSubNodeNames() as $sub) {
                $value = $node->{$sub};

                if ($value instanceof Node) {
                    $this->collectNames([$value], $namespace, $aliases, $names);
                } elseif (is_array($value)) {
                    $this->collectNames($value, $namespace, $aliases, $names);
                }
            }
        }
    }

    /**
     * @param array<string, string> $aliases
     */
    private function resolveName(Name $name, string $namespace, array $aliases): string
    {
        if ($name->isFullyQualified()) {
            return $name->toString();
        }

        if ($name->isRelative()) {
            return $namespace === '' ? $name->toString() : $namespace.'\\'.$name->toString();
        }

        $parts = $name->getParts();
        $first = $parts[0];

        if (isset($aliases[$first])) {
            $rest = array_slice($parts, 1);

            return $rest === [] ? $aliases[$first] : $aliases[$first].'\\'.implode('\\', $rest);
        }

        $relative = implode('\\', $parts);

        return $namespace === '' ? $relative : $namespace.'\\'.$relative;
    }

    /**
     * @param list<string> $names
     */
    private function recordPagekit(string $name, array &$names): void
    {
        if (str_starts_with($name, 'Pagekit\\')) {
            $names[] = $name;
        }
    }

    private function parser(): Parser
    {
        return $this->parser ??= (new ParserFactory())->createForHostVersion();
    }

    private function root(): string
    {
        return strtr(dirname(__DIR__, 3), '\\', '/');
    }

    private function relative(string $root, string $file): string
    {
        $file = strtr($file, '\\', '/');

        if (str_starts_with($file, $root.'/')) {
            return substr($file, strlen($root) + 1);
        }

        return $file;
    }

    private function workspace(): string
    {
        $path = strtr(sys_get_temp_dir(), '\\', '/').'/pk_import_edge_'.getmypid().'_'.uniqid();

        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            self::fail('The fixture workspace could not be created.');
        }

        $this->workspaces[] = $path;

        return $path;
    }

    /**
     * @param list<string> $require
     */
    private function writeModule(string $root, string $directory, string $name, array $require): void
    {
        $this->write(
            $root,
            $directory.'/index.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'name' => ".var_export($name, true).",\n    'require' => ".var_export(array_values($require), true).",\n];\n",
        );
    }

    private function write(string $root, string $relative, string $contents): void
    {
        $path = $root.'/'.$relative;
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            self::fail('The fixture directory could not be created.');
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail('The fixture file could not be written.');
        }
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
            $this->removeTree($path.'/'.$entry);
        }

        rmdir($path);
    }
}
