<?php

declare(strict_types=1);

namespace Pagekit\Site;

use Psr\Container\ContainerInterface;

// TODO: Must be refactored in Step 2.1 (Static Analysis) — replace ModelServiceLocator with proper DTO/presenter pattern

final class ModelServiceLocator
{
    private static ?ContainerInterface $app = null;

    public static function init(ContainerInterface $app): void
    {
        self::$app = $app;
    }

    public static function getUrl(): mixed
    {
        if (self::$app === null) {
            throw new \RuntimeException('ModelServiceLocator not initialized. Was SiteModule booted?');
        }
        return self::$app->get('url');
    }

    public static function getUser(): mixed
    {
        if (self::$app === null) {
            throw new \RuntimeException('ModelServiceLocator not initialized. Was SiteModule booted?');
        }
        return self::$app->get('user');
    }

    public static function getModule(string $name): mixed
    {
        if (self::$app === null) {
            throw new \RuntimeException('ModelServiceLocator not initialized. Was SiteModule booted?');
        }
        return self::$app->get('module')->get($name);
    }
}
