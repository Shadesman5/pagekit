<?php

declare(strict_types=1);

namespace Pagekit\Cache;

use Pagekit\Application as App;
use Pagekit\Module\Module;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Finder\Finder;

class CacheModule extends Module
{
    protected ?App $app = null;

    public function main(App $app): mixed
    {
        $this->app = $app;
        foreach ($this->config['caches'] as $name => $config) {
            $app->set($name, function () use ($config, $name) {

                $supports = self::supports();

                if (!isset($config['storage'])) {
                    throw new \RuntimeException('Cache storage missing.');
                }

                if ($this->config['nocache']) {
                    $config['storage'] = 'array';
                } elseif ($config['storage'] == 'auto' || !in_array($config['storage'], $supports)) {
                    $config['storage'] = end($supports);
                }

                return $this->createCachePool($config);
            });
        }

        return null;
    }

    /**
     * Create PSR-6 cache pool from Symfony adapters.
     *
     * @param array<string, mixed> $config Cache configuration with 'storage', 'prefix', 'path' keys
     */
    protected function createCachePool(array $config): CacheItemPoolInterface
    {
        $prefix = $config['prefix'] ?? '';
        $path = $config['path'] ?? '';

        if (empty($path)) {
            $path = sys_get_temp_dir() . '/pagekit-cache';
        }

        switch ($config['storage']) {
            case 'array':
                return new ArrayAdapter();

            case 'apcu':
                if (!function_exists('apcu_fetch') || !ini_get('apc.enabled')) {
                    return new PhpFilesAdapter($prefix, 0, $path);
                }

                return new ApcuAdapter($prefix, 0);

            case 'file':
                return new FilesystemAdapter($prefix, 0, $path);

            case 'phpfile':
                return new PhpFilesAdapter($prefix, 0, $path);

            case 'null':
                return new NullAdapter();

            default:
                throw new \RuntimeException('Unknown cache storage: ' . $config['storage']);
        }
    }

    /**
     * Returns list of supported caches.
     *
     * @return array<int, string>
     */
    public static function supports(): array
    {
        $supports = ['file', 'phpfile', 'array'];

        // APCu support
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $supports[] = 'apcu';
        }

        return $supports;
    }

    /**
     * Checks whether a specific cache storage backend is supported.
     */
    public static function isSupported(string $name): bool
    {
        return in_array($name, self::supports(), true);
    }

    /**
     * Asserts that main() has been called.
     */
    private function assertBooted(): App
    {
        if ($this->app === null) {
            throw new \LogicException('CacheModule::main() has not been called yet.');
        }
        return $this->app;
    }

    /**
     * Schedule cache clear on terminate event.
     *
     * @param array<string, mixed> $options
     */
    public function clearCache(array $options = []): void
    {
        $app = $this->assertBooted();

        $app->get('events')->on('terminate', function () use ($options) {
            $this->doClearCache($options);
        }, -512);
    }

    /**
     * Clear the cache pool and optionally temp files.
     *
     * @param array<string, mixed> $options
     */
    public function doClearCache(array $options = []): void
    {
        $app = $this->assertBooted();

        // Clear PSR-6 cache pool + compiled cache files
        if (empty($options) || @$options['cache']) {
            $app->get('cache')->clear();

            $files = glob($app->get('path.cache') . '/*.cache') ?: [];
            foreach ($files as $file) {
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file, true);
                }
                @unlink($file);
            }
        }

        // Clear temp folder
        if (@$options['temp']) {
            foreach (Finder::create()->in($app->get('path.temp'))->depth(0)->ignoreDotFiles(true) as $file) {
                $app->get('file')->delete($file->getPathname());
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file->getPathname(), true);
                }
            }
        }
    }
}
