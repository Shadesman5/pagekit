<?php

namespace Pagekit\Cache;

use Pagekit\Application as App;
use Pagekit\Cache\Adapter\ArrayAdapter;
use Pagekit\Cache\Adapter\ApcuAdapter;
use Pagekit\Cache\Adapter\FilesystemAdapter;
use Pagekit\Cache\Adapter\NullAdapter;
use Pagekit\Cache\Adapter\PhpFilesAdapter;
use Pagekit\Module\Module;

class CacheModule extends Module
{
    /**
     * {@inheritdoc}
     */
    public function main(App $app): void
    {
        foreach ($this->config['caches'] as $name => $config)  {
            $app[$name] = function() use ($config) {

                $supports = $this->supports();

                if (!isset($config['storage'])) {
                    throw new \RuntimeException('Cache storage missing.');
                }

                if ($this->config['nocache']) {
                    $config['storage'] = 'array';
                } elseif ($config['storage'] == 'auto' || !in_array($config['storage'], $supports)) {
                    $config['storage'] = end($supports);
                }

                // Always use PSR-6 adapters
                return $this->createPsr6Cache($config);
            };
        }
    }

    /**
     * Create PSR-6 based cache adapter
     *
     * @param array $config
     * @return \Doctrine\Common\Cache\CacheProvider
     */
    protected function createPsr6Cache(array $config): \Doctrine\Common\Cache\CacheProvider
    {
        switch ($config['storage']) {
            case 'array':
                $cache = new ArrayAdapter();
                break;

            case 'apcu':
                // Check if APCu is available
                if (!function_exists('apcu_fetch') || !ini_get('apc.enabled')) {
                    // Fallback to phpfile cache if APCu not available
                    $cache = new PhpFilesAdapter($config['path'] ?? '');
                } else {
                    $cache = new ApcuAdapter('', 0);
                }
                break;

            case 'file':
                $cache = new FilesystemAdapter($config['path'] ?? '');
                break;

            case 'phpfile':
                $cache = new PhpFilesAdapter($config['path'] ?? '');
                break;

            case 'null':
                $cache = new NullAdapter();
                break;

            // Handle legacy configurations
            case 'apc':
            case 'xcache':
                // Silently fallback to phpfile for legacy configurations
                $cache = new PhpFilesAdapter($config['path'] ?? '');
                break;

            default:
                throw new \RuntimeException('Unknown cache storage: ' . $config['storage']);
        }

        // Set namespace if provided
        if ($prefix = isset($config['prefix']) ? $config['prefix'] : false) {
            $cache->setNamespace($prefix);
        }

        return $cache;
    }


    /**
     * Returns list of supported caches or boolean for individual cache.
     *
     * @param  string $name
     * @return array|boolean
     */
    public static function supports($name = null)
    {
        // Only modern cache options
        $supports = ['file', 'phpfile', 'array'];

        // Check for APCu support (modern memory cache)
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $supports[] = 'apcu';
        }

        return $name? in_array($name, $supports) : $supports;
    }

    /**
     * Clear cache on terminate event.
     */
    public function clearCache(array $options = []): void
    {
        App::on('terminate', function() use ($options) {
            $this->doClearCache($options);
        }, -512);
    }

    /**
     * TODO: clear opcache
     */
    public function doClearCache(array $options = []): void
    {
        // clear cache
        if (empty($options) || @$options['cache']) {
            App::cache()->flushAll();

            foreach ((array) glob(App::get('path.cache') . '/*.cache') as $file) {
                @unlink($file);
                // opcache
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file);
                }
            }
        }

        // clear temp folder
        if (@$options['temp']) {
            foreach (App::finder()->in(App::get('path.temp'))->depth(0)->ignoreDotFiles(true) as $file) {
                App::file()->delete($file->getPathname());
                // opcache
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file);
                }
            }
        }
    }
}