<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Module;

use Pagekit\Module\ModuleManifest;
use Pagekit\Module\ModuleManifestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Registration fields read from one module.json document.
 */
final class ModuleManifestTest extends TestCase
{
    public function testTheRegistrationFileAndTheEntryPointAndTheSizeLimit(): void
    {
        self::assertSame('module.json', ModuleManifest::FILE);
        self::assertSame('index.php', ModuleManifest::ENTRY);
        self::assertSame(1024 * 1024, ModuleManifest::MAX_BYTES);
    }

    public function testJsonThatDoesNotParseCarriesTheParserFailure(): void
    {
        try {
            ModuleManifest::decode('{');
            self::fail('Invalid JSON has to be refused.');
        } catch (ModuleManifestException $exception) {
            self::assertSame('Syntax error', $exception->getMessage());
            self::assertInstanceOf(\JsonException::class, $exception->getPrevious());
            self::assertInstanceOf(\RuntimeException::class, $exception);
        }
    }

    #[DataProvider('documentsThatAreNotObjects')]
    public function testADocumentThatIsNotAnObjectIsRefused(string $json): void
    {
        $this->assertRejected($json, 'Module manifest must be a JSON object.');
    }

    public function testAMissingOrBlankNameIsSkipped(): void
    {
        self::assertNull(ModuleManifest::decode('{}'));
        self::assertNull(ModuleManifest::decode('{"name":""}'));
        self::assertNull(ModuleManifest::decode('{"title":"orphan"}'));
    }

    #[DataProvider('namesThatAreNotStrings')]
    public function testANameThatIsNotAStringNamesTheField(string $json): void
    {
        $this->assertField($json, 'name');
    }

    public function testUnknownKeysAreIgnoredAndAnOmittedRequireIsAnEmptyList(): void
    {
        $module = ModuleManifest::decode('{"title":"Hidden","name":"plain","extra":1}');

        self::assertSame([
            'name' => 'plain',
            'require' => [],
        ], $module);
    }

    public function testRequireKeepsEveryStringInOrderIncludingABlankOne(): void
    {
        $json = json_encode([
            'name' => 'counted',
            'require' => ['', 'alpha', 'beta'],
        ], JSON_THROW_ON_ERROR);

        $module = ModuleManifest::decode($json);

        self::assertIsArray($module);
        self::assertSame(['', 'alpha', 'beta'], $module['require']);
    }

    public function testIncludeIsAStringOrAListOnlyWhenPresent(): void
    {
        $absent = ModuleManifest::decode('{"name":"plain"}');

        self::assertIsArray($absent);
        self::assertArrayNotHasKey('include', $absent);

        $string = ModuleManifest::decode('{"name":"host","include":"modules/*/module.json"}');

        self::assertIsArray($string);
        self::assertSame('modules/*/module.json', $string['include']);

        $list = ModuleManifest::decode('{"name":"host","include":["modules/*/module.json","themes/*/module.json"]}');

        self::assertIsArray($list);
        self::assertSame(['modules/*/module.json', 'themes/*/module.json'], $list['include']);
    }

    public function testAutoloadIsAStringMapOnlyWhenPresent(): void
    {
        $absent = ModuleManifest::decode('{"name":"plain"}');

        self::assertIsArray($absent);
        self::assertArrayNotHasKey('autoload', $absent);

        $empty = ModuleManifest::decode('{"name":"theme-one","autoload":{}}');

        self::assertIsArray($empty);
        self::assertSame([], $empty['autoload']);

        $json = json_encode([
            'name' => 'kernel',
            'autoload' => [
                'Pagekit\\' => 'src',
                'Pagekit\\Kernel\\' => 'src',
            ],
        ], JSON_THROW_ON_ERROR);

        $mapped = ModuleManifest::decode($json);

        self::assertIsArray($mapped);
        self::assertSame([
            'Pagekit\\' => 'src',
            'Pagekit\\Kernel\\' => 'src',
        ], $mapped['autoload']);
    }

    public function testNodesKeepNestedValues(): void
    {
        $absent = ModuleManifest::decode('{"name":"plain"}');

        self::assertIsArray($absent);
        self::assertArrayNotHasKey('nodes', $absent);

        $nodes = [
            'post' => [
                'label' => 'Post',
                'on' => true,
                'n' => 2,
                'ratio' => 1.5,
                'extra' => null,
                'tags' => ['a', 'b'],
                'child' => ['deep' => true],
            ],
        ];
        $json = json_encode([
            'name' => 'blog',
            'nodes' => $nodes,
        ], JSON_THROW_ON_ERROR);

        $module = ModuleManifest::decode($json);

        self::assertIsArray($module);
        self::assertSame($nodes, $module['nodes']);
    }

    #[DataProvider('rejectedFields')]
    public function testAFieldOfTheWrongJsonTypeNamesThatField(string $json, string $field): void
    {
        $this->assertField($json, $field);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentsThatAreNotObjects(): iterable
    {
        yield 'array' => ['[]'];
        yield 'string' => ['"module"'];
        yield 'number' => ['1'];
        yield 'bool' => ['true'];
        yield 'null' => ['null'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatAreNotStrings(): iterable
    {
        yield 'number' => ['{"name":42}'];
        yield 'null' => ['{"name":null}'];
        yield 'bool' => ['{"name":true}'];
        yield 'array' => ['{"name":[]}'];
        yield 'object' => ['{"name":{"id":"plain"}}'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedFields(): iterable
    {
        yield 'require object' => ['{"name":"a","require":{}}', 'require'];
        yield 'require string' => ['{"name":"a","require":"kernel"}', 'require'];
        yield 'require number' => ['{"name":"a","require":[42]}', 'require'];
        yield 'require null' => ['{"name":"a","require":[null]}', 'require'];
        yield 'include number' => ['{"name":"a","include":1}', 'include'];
        yield 'include empty' => ['{"name":"a","include":""}', 'include'];
        yield 'include empty element' => ['{"name":"a","include":[""]}', 'include'];
        yield 'include object' => ['{"name":"a","include":{"modules":"*"}}', 'include'];
        yield 'autoload list' => ['{"name":"a","autoload":[]}', 'autoload'];
        yield 'autoload string' => ['{"name":"a","autoload":"src"}', 'autoload'];
        yield 'autoload path' => ['{"name":"a","autoload":{"Fixture\\\\":1}}', 'autoload'];
        yield 'nodes list' => ['{"name":"a","nodes":[]}', 'nodes'];
        yield 'nodes string' => ['{"name":"a","nodes":"post"}', 'nodes'];
    }

    private function assertField(string $json, string $field): void
    {
        $this->assertRejected($json, sprintf('Module manifest field "%s" is invalid.', $field));
    }

    private function assertRejected(string $json, string $message): void
    {
        try {
            ModuleManifest::decode($json);
            self::fail('The manifest has to be refused.');
        } catch (ModuleManifestException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertNull($exception->getPrevious());
            self::assertInstanceOf(\RuntimeException::class, $exception);
        }
    }
}
