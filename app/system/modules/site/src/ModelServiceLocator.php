<?php

declare(strict_types=1);

namespace Pagekit\Site;

use Pagekit\Application\UrlProvider;
use Pagekit\Module\ModuleInterface;
use Pagekit\Module\ModuleManager;
use Pagekit\User\Model\User;
use Psr\Container\ContainerInterface;

// TODO: Must be refactored in Step 2.1.10 (Entity Presentation Layer) — replace ModelServiceLocator with proper DTO/presenter pattern (GitHub #204)

final class ModelServiceLocator
{
    private static ?ContainerInterface $app = null;

    public static function init(ContainerInterface $app): void
    {
        self::$app = $app;
    }

    public static function getUrl(): UrlProvider
    {
        if (self::$app === null) {
            throw new \RuntimeException('ModelServiceLocator not initialized. Was SiteModule booted?');
        }

        /** @var UrlProvider $url */
        $url = self::$app->get('url');

        return $url;
    }

    public static function getUser(): User
    {
        if (self::$app === null) {
            throw new \RuntimeException('ModelServiceLocator not initialized. Was SiteModule booted?');
        }

        /** @var User $user */
        $user = self::$app->get('user');

        return $user;
    }

    public static function getModule(string $name): ModuleInterface|null
    {
        if (self::$app === null) {
            throw new \RuntimeException('ModelServiceLocator not initialized. Was SiteModule booted?');
        }

        /** @var ModuleManager $moduleManager */
        $moduleManager = self::$app->get('module');
        $module = $moduleManager->get($name);

        return $module instanceof ModuleInterface ? $module : null;
    }
}
