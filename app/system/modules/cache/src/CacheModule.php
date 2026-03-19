<?php

namespace Pagekit\Cache;

use Pagekit\Application as App;
use Pagekit\Cache\Adapter\ArrayAdapter;
use Pagekit\Cache\Adapter\ApcuAdapter;
use Pagekit\Cache\Adapter\FilesystemAdapter;
use Pagekit\Cache\Adapter\NullAdapter;
use Pagekit\Cache\Adapter\PhpFilesAdapter;
use Pagekit\Cache\CacheInterface;
use Pagekit\Module\Module;
use Symfony\Component\Finder\Finder;

class CacheModule extends Module
{
    protected ?App $app = null;

    /**
     * {@inheritdoc}
     */
    public function main(App $app): void
    {
        $this->app = $app;
        foreach ($this->config['caches'] as $name => $config)  {
            $app->set($name, function() use ($config, $name) {

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
            });
        }
    }



    /**
     * Create PSR-6 based cache adapter
     *
     * @param array $config
     * @return CacheInterface
     */
    protected function createPsr6Cache(array $config): CacheInterface
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
        $supports = ['file', 'phpfile', 'array'];


        // APCu support
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $supports[] = 'apcu';
        }

        return $name ? in_array($name, $supports) : $supports;
    }

    /**
     * Clear cache on terminate event.
     */
    public function clearCache(array $options = []): void
    {
        App::on('terminate', function() use ($options) { // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
            $this->doClearCache($options);
        }, -512);
    }

    /**
     * TODO: clear opcache
     */
    public function doClearCache(array $options = []): void
    {
        $app = $this->app ?? App::getInstance(); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e

        // clear cache
        if (empty($options) || @$options['cache']) {
            $app->get('cache')->flushAll();

            foreach ((array) glob($app->get('path.cache') . '/*.cache') as $file) {
                @unlink($file);
                // opcache
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file);
                }
            }
        }

        // clear temp folder
        if (@$options['temp']) {
            foreach (Finder::create()->in($app->get('path.temp'))->depth(0)->ignoreDotFiles(true) as $file) {
                $app->get('file')->delete($file->getPathname());
                // opcache
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file);
                }
            }
        }
    }
}