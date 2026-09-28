<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Composer;

use PHPUnit\Framework\TestCase;

/**
 * Dependencies install at the repository root, and the boot and tooling read that directory.
 */
final class RootVendorLayoutTest extends TestCase
{
    /**
     * This file names the old directory in order to detect it. The walk has to leave it unread.
     */
    private const OWN = 'tests/Unit/Composer/RootVendorLayoutTest.php';

    private const OLD_VENDOR = 'app/vendor';

    public function testComposerLeavesTheVendorDirectoryAtItsDefault(): void
    {
        $raw = $this->read('composer.json');

        self::assertStringNotContainsString('vendor-dir', $raw);
        self::assertStringNotContainsString('bin-dir', $raw);

        $composer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($composer);

        $config = $composer['config'] ?? null;

        self::assertIsArray($config);
        self::assertArrayNotHasKey('vendor-dir', $config);
        self::assertArrayNotHasKey('bin-dir', $config);
        self::assertArrayHasKey('platform', $config);
        self::assertArrayHasKey('allow-plugins', $config);
    }

    public function testBootRequiresTheRootVendorAutoload(): void
    {
        $autoload = $this->read('autoload.php');

        self::assertStringContainsString("return require __DIR__ . '/vendor/autoload.php';", $autoload);
        self::assertStringNotContainsString(self::OLD_VENDOR, $autoload);

        $suite = $this->read('tests/bootstrap.php');

        self::assertStringContainsString("dirname(__DIR__) . '/vendor/autoload.php'", $suite);
        self::assertStringNotContainsString(self::OLD_VENDOR, $suite);
    }

    public function testTheFrontControllerPointsVendorAtTheRootDirectory(): void
    {
        $index = $this->read('public/index.php');

        self::assertStringContainsString('\'path.vendor\' => $path.\'/vendor\'', $index);
        self::assertSame(1, substr_count($index, 'path.vendor'));
        self::assertStringNotContainsString(self::OLD_VENDOR, $index);
    }

    public function testTheCacheSuiteBootstrapClimbsSixSegmentsToTheRoot(): void
    {
        $bootstrap = $this->read('app/system/modules/cache/src/Tests/bootstrap.php');

        self::assertSame(1, preg_match(
            "~require_once __DIR__ \\. '/((?:\\.\\./)+)vendor/autoload\\.php';~",
            $bootstrap,
            $match,
        ));
        self::assertSame(6, substr_count($match[1], '../'));
        self::assertStringNotContainsString(self::OLD_VENDOR, $bootstrap);
    }

    public function testTheLegacyVendorDirectoryIsNotOnDisk(): void
    {
        $legacy = $this->root().'/'.self::OLD_VENDOR;

        self::assertDirectoryDoesNotExist($legacy);
        self::assertFalse(is_link($legacy));
    }

    public function testSuiteAndImageConfigDoNotNameTheOldVendorDirectory(): void
    {
        $files = $this->layoutFiles();

        self::assertContains(self::OWN, $files);

        $own = $this->read(self::OWN);

        self::assertStringContainsString(self::OLD_VENDOR, $own);

        foreach ($files as $relative) {
            if ($relative === self::OWN) {
                continue;
            }

            self::assertStringNotContainsString(self::OLD_VENDOR, $this->read($relative), $relative);
        }
    }

    public function testSuiteAndImageReadTheRootVendorDirectory(): void
    {
        $phpunit = $this->read('phpunit.xml.dist');
        $mysql = $this->read('phpunit-mysql.xml.dist');

        self::assertStringContainsString('vendor/phpunit/phpunit/phpunit.xsd', $phpunit);
        self::assertStringContainsString('vendor/phpunit/phpunit/phpunit.xsd', $mysql);
        self::assertStringNotContainsString('<directory>vendor</directory>', $phpunit);
        self::assertStringNotContainsString('<directory>vendor</directory>', $mysql);

        $phpstan = $this->read('phpstan.neon');

        self::assertStringContainsString('- vendor/autoload.php', $phpstan);
        self::assertMatchesRegularExpression('/^\s*- vendor$/m', $phpstan);
        self::assertStringContainsString('app/modules/*/vendor', $phpstan);

        $infection = $this->read('infection.json.dist');

        self::assertStringContainsString('vendor/infection/infection/resources/schema.json', $infection);
        self::assertStringContainsString('"customPath": "vendor/bin/phpunit"', $infection);

        self::assertMatchesRegularExpression(
            '/->exclude\(\[\s*\'vendor\',/',
            $this->read('.php-cs-fixer.php'),
        );

        $docker = $this->read('Dockerfile');

        self::assertStringContainsString(
            'COPY --from=composer-deps /var/www/html/vendor ./vendor',
            $docker,
        );
        self::assertSame(1, substr_count($docker, 'COPY --from=composer-deps'));
        self::assertDoesNotMatchRegularExpression('/chown[^\n]*vendor/', $docker);

        foreach ($this->workflows() as $workflow) {
            $contents = $this->read($workflow);

            self::assertStringContainsString('path: vendor', $contents, $workflow);
            self::assertStringContainsString('composer-root-vendor', $contents, $workflow);
            self::assertDoesNotMatchRegularExpression('/composer-(?!root-vendor)/', $contents, $workflow);
        }

        foreach ([
            '.github/workflows/php-tests.yml',
            '.github/workflows/infection.yml',
            '.github/workflows/nightly.yml',
        ] as $workflow) {
            self::assertStringContainsString('./vendor/bin/', $this->read($workflow), $workflow);
        }
    }

    /**
     * @return list<string>
     */
    private function layoutFiles(): array
    {
        return [
            self::OWN,
            'phpunit.xml.dist',
            'phpunit-mysql.xml.dist',
            'phpstan.neon',
            'infection.json.dist',
            '.php-cs-fixer.php',
            'Dockerfile',
            ...$this->workflows(),
        ];
    }

    /**
     * @return list<string>
     */
    private function workflows(): array
    {
        return [
            '.github/workflows/php-tests.yml',
            '.github/workflows/infection.yml',
            '.github/workflows/nightly.yml',
            '.github/workflows/e2e.yml',
            '.github/workflows/e2e-weekly.yml',
        ];
    }

    private function read(string $relative): string
    {
        $contents = file_get_contents($this->root().'/'.$relative);

        self::assertIsString($contents, $relative);

        return $contents;
    }

    private function root(): string
    {
        return strtr(dirname(__DIR__, 3), '\\', '/');
    }
}
