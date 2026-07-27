<?php

declare(strict_types=1);

namespace Pagekit\Installer\Helper;

use Composer\Config;
use Composer\Factory as BaseFactory;
use Composer\IO\IOInterface;

class Factory extends BaseFactory
{
    /** @var array<string, string> */
    protected static array $config = [];

    /**
     * @param array<string, string> $config
     */
    public static function bootstrap(array $config): void
    {
        self::$config = $config;
    }

    public static function createConfig(?IOInterface $io = null, ?string $cwd = null): Config
    {
        $config = new Config(true, $cwd);
        $config->merge(['config' => static::$config]);

        return $config;
    }
}
