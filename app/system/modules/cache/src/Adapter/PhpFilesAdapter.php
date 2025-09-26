<?php

namespace Pagekit\Cache\Adapter;

use Symfony\Component\Cache\Adapter\PhpFilesAdapter as SymfonyPhpFilesAdapter;

/**
 * PHP Files Cache Adapter - Stores cache as PHP files for better performance
 */
class PhpFilesAdapter extends Psr6Adapter
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
        
        parent::__construct(new SymfonyPhpFilesAdapter($namespace, $defaultLifetime, $directory));
    }
}