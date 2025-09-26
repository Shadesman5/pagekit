<?php

namespace Pagekit\Cache\Adapter;

use Symfony\Component\Cache\Adapter\FilesystemAdapter as SymfonyFilesystemAdapter;

/**
 * Filesystem Cache Adapter
 */
class FilesystemAdapter extends Psr6Adapter
{
    /**
     * Constructor
     *
     * @param string $directory
     * @param int $defaultLifetime
     */
    public function __construct(string $directory = '', int $defaultLifetime = 0)
    {
        // Use a namespace to avoid conflicts
        $namespace = '';
        
        // If no directory specified, use system temp
        if (empty($directory)) {
            $directory = sys_get_temp_dir() . '/pagekit-cache';
        }
        
        parent::__construct(new SymfonyFilesystemAdapter($namespace, $defaultLifetime, $directory));
    }
}