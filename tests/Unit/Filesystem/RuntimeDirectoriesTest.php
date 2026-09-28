<?php

declare(strict_types=1);

namespace Pagekit\Filesystem {
    // Stand-ins for the built-ins. A test forces one result; every other call is the real function.
    function chmod(string $filename, int $permissions): bool
    {
        $forced = \Pagekit\Tests\Unit\Filesystem\RuntimeDirectoriesTest::forcedChmod();

        if (is_array($forced) && $filename === $forced['path']) {
            if (is_string($forced['warning'])) {
                \trigger_error($forced['warning'], \E_USER_WARNING);
            }

            return $forced['result'];
        }

        return \chmod($filename, $permissions);
    }

    function fileperms(string $filename): int|false
    {
        $forced = \Pagekit\Tests\Unit\Filesystem\RuntimeDirectoriesTest::forcedPerms();

        if (is_array($forced) && $filename === $forced['path']) {
            return $forced['perms'];
        }

        return \fileperms($filename);
    }

    /**
     * @param resource|null $context
     */
    function mkdir(string $directory, int $permissions = 0777, bool $recursive = false, $context = null): bool
    {
        $forced = \Pagekit\Tests\Unit\Filesystem\RuntimeDirectoriesTest::forcedMkdir();

        if (is_array($forced) && $directory === $forced['path']) {
            if (!\is_dir($directory)) {
                if (is_resource($context)) {
                    \mkdir($directory, $permissions, $recursive, $context);
                } else {
                    \mkdir($directory, $permissions, $recursive);
                }
            }

            return false;
        }

        if (is_resource($context)) {
            return \mkdir($directory, $permissions, $recursive, $context);
        }

        return \mkdir($directory, $permissions, $recursive);
    }
}

namespace Pagekit\Tests\Unit\Filesystem {

    use Pagekit\Filesystem\RuntimeDirectories;
    use PHPUnit\Framework\TestCase;

    /**
     * Private runtime directories are created owner-only, or the boot does not continue.
     */
    final class RuntimeDirectoriesTest extends TestCase
    {
        /** @var array{path: string, result: bool, warning: string|null}|null */
        private static ?array $forcedChmod = null;

        /** @var array{path: string, perms: int|false}|null */
        private static ?array $forcedPerms = null;

        /** @var array{path: string}|null */
        private static ?array $forcedMkdir = null;

        private string $workspace;

        /**
         * @return array{path: string, result: bool, warning: string|null}|null
         */
        public static function forcedChmod(): ?array
        {
            return self::$forcedChmod;
        }

        /**
         * @return array{path: string, perms: int|false}|null
         */
        public static function forcedPerms(): ?array
        {
            return self::$forcedPerms;
        }

        /**
         * @return array{path: string}|null
         */
        public static function forcedMkdir(): ?array
        {
            return self::$forcedMkdir;
        }

        protected function setUp(): void
        {
            self::$forcedChmod = null;
            self::$forcedPerms = null;
            self::$forcedMkdir = null;

            $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pagekit-runtime-'.bin2hex(random_bytes(4));
            self::assertTrue(mkdir($this->workspace, 0700, true));
            self::assertTrue(chmod($this->workspace, 0700));
        }

        protected function tearDown(): void
        {
            self::$forcedChmod = null;
            self::$forcedPerms = null;
            self::$forcedMkdir = null;
            $this->removeTree($this->workspace);
        }

        public function testEnsureCreatesAMissingDirectoryOwnerOnly(): void
        {
            $directory = $this->workspace.'/data';

            RuntimeDirectories::ensure($directory);
            RuntimeDirectories::ensure($directory);

            self::assertSame(0700, RuntimeDirectories::MODE);
            self::assertDirectoryExists($directory);
            self::assertSame(0700, $this->mode($directory));
        }

        public function testEnsureNarrowsAnExistingDirectoryToOwnerOnly(): void
        {
            $directory = $this->workspace.'/data';
            self::assertTrue(mkdir($directory));
            self::assertTrue(chmod($directory, 0755));
            self::assertSame(0755, $this->mode($directory));

            RuntimeDirectories::ensure($directory);

            self::assertSame(0700, $this->mode($directory));
        }

        public function testEnsureCreatesAMissingParentAndChmodsOnlyTheDirectoryItWasGiven(): void
        {
            $parent = $this->workspace.'/data';
            self::assertTrue(mkdir($parent));
            self::assertTrue(chmod($parent, 0755));

            $directory = $parent.'/state';
            RuntimeDirectories::ensure($directory);

            self::assertSame(0700, $this->mode($directory));
            // chmod runs only on the directory that was asked for.
            self::assertSame(0755, $this->mode($parent));
            self::assertSame(['data'], $this->entries($this->workspace));
            self::assertSame(['state'], $this->entries($parent));

            $nested = $this->workspace.'/fresh/leaf';
            RuntimeDirectories::ensure($nested);

            self::assertDirectoryExists($this->workspace.'/fresh');
            self::assertSame(0700, $this->mode($nested));
            self::assertSame(['data', 'fresh'], $this->entries($this->workspace));
        }

        public function testACreateThatLosesTheRaceStillLeavesTheDirectoryOwnerOnly(): void
        {
            $directory = $this->workspace.'/data/snapshots';
            self::assertDirectoryDoesNotExist($directory);
            self::$forcedMkdir = ['path' => $directory];

            try {
                RuntimeDirectories::ensure($directory);
            } finally {
                self::$forcedMkdir = null;
            }

            self::assertDirectoryExists($directory);
            self::assertSame(0700, $this->mode($directory));
        }

        public function testEnsureThrowsWhenTheDirectoryCannotBeCreated(): void
        {
            $parent = $this->workspace.'/blocked';
            self::assertNotFalse(file_put_contents($parent, 'not a directory'));
            $directory = $parent.'/data';

            $failure = null;
            $error = null;

            $this->keepingWarnings(function () use ($directory, &$failure, &$error): void {
                \error_clear_last();
                $created = @\mkdir($directory, 0700, true);
                $error = \error_get_last();

                self::assertFalse($created);
                self::assertFalse(is_dir($directory));
                self::assertIsArray($error);
                self::assertNotSame('', $error['message']);

                try {
                    RuntimeDirectories::ensure($directory);
                    self::fail('A directory that cannot be created must not pass ensure().');
                } catch (\RuntimeException $caught) {
                    $failure = $caught;
                }
            });

            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertIsArray($error);
            // The warning the failed call left is the message, not a fixed sentence in its place.
            self::assertSame($directory.': '.$error['message'], $failure->getMessage());
            self::assertFileExists($parent);
            self::assertDirectoryDoesNotExist($directory);
        }

        public function testEnsureThrowsWhenTheModeCannotBeSet(): void
        {
            $directory = $this->workspace.'/data';
            self::assertTrue(mkdir($directory, 0700, true));
            self::assertTrue(chmod($directory, 0700));
            self::$forcedChmod = ['path' => $directory, 'result' => false, 'warning' => 'Permission denied'];
            $failure = null;

            try {
                $this->keepingWarnings(function () use ($directory, &$failure): void {
                    // A warning from earlier must not replace the one this call left.
                    @\trigger_error('stale warning', \E_USER_WARNING);

                    try {
                        RuntimeDirectories::ensure($directory);
                        self::fail('A directory whose mode cannot be set must not pass ensure().');
                    } catch (\RuntimeException $caught) {
                        $failure = $caught;
                    }
                });
            } finally {
                self::$forcedChmod = null;
            }

            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertSame($directory.': Permission denied', $failure->getMessage());

            self::assertSame(0700, $this->mode($directory));
        }

        public function testEnsureThrowsWhenTheModeDoesNotStayOwnerOnly(): void
        {
            $directory = $this->workspace.'/data';
            self::assertTrue(mkdir($directory, 0700, true));
            self::assertTrue(chmod($directory, 0700));
            self::$forcedPerms = ['path' => $directory, 'perms' => 0755];
            $failure = null;

            try {
                $this->keepingWarnings(function () use ($directory, &$failure): void {
                    @\trigger_error('stale warning', \E_USER_WARNING);

                    try {
                        RuntimeDirectories::ensure($directory);
                        self::fail('A directory that does not stay owner-only must not pass ensure().');
                    } catch (\RuntimeException $caught) {
                        $failure = $caught;
                    }
                });
            } finally {
                self::$forcedPerms = null;
            }

            self::assertInstanceOf(\RuntimeException::class, $failure);
            // No warning from the call: the message is the directory alone.
            self::assertSame($directory, $failure->getMessage());
        }

        public function testEnsureThrowsWhenTheModeCannotBeRead(): void
        {
            $directory = $this->workspace.'/data';
            self::assertTrue(mkdir($directory, 0700, true));
            self::assertTrue(chmod($directory, 0700));
            self::$forcedPerms = ['path' => $directory, 'perms' => false];
            $failure = null;

            try {
                $this->keepingWarnings(function () use ($directory, &$failure): void {
                    @\trigger_error('stale warning', \E_USER_WARNING);

                    try {
                        RuntimeDirectories::ensure($directory);
                        self::fail('A directory whose mode cannot be read must not pass ensure().');
                    } catch (\RuntimeException $caught) {
                        $failure = $caught;
                    }
                });
            } finally {
                self::$forcedPerms = null;
            }

            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertSame($directory, $failure->getMessage());
        }

        /**
         * PHPUnit's handler drops a silenced warning before error_get_last() can see it.
         * A handler that returns false leaves the warning the production code reads.
         */
        private function keepingWarnings(callable $run): void
        {
            \set_error_handler(static fn (int $number, string $message): bool => false);

            try {
                $run();
            } finally {
                \restore_error_handler();
            }
        }

        private function mode(string $directory): int
        {
            $mode = fileperms($directory);
            self::assertIsInt($mode, $directory);

            return $mode & 0777;
        }

        /**
         * @return list<string>
         */
        private function entries(string $directory): array
        {
            $entries = scandir($directory);
            self::assertIsArray($entries, $directory);

            $names = array_values(array_diff($entries, ['.', '..']));
            sort($names);

            return $names;
        }

        private function removeTree(string $path): void
        {
            if (is_link($path) || is_file($path)) {
                @unlink($path);

                return;
            }

            if (!is_dir($path)) {
                return;
            }

            @chmod($path, 0700);
            $entries = scandir($path);

            if ($entries === false) {
                return;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $this->removeTree($path.'/'.$entry);
            }

            @rmdir($path);
        }
    }
}
