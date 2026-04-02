<?php

namespace Pagekit\Cache\Adapter;

use Symfony\Component\Cache\Adapter\ApcuAdapter as SymfonyApcuAdapter;

/**
 * APCu Cache Adapter
 */
class ApcuAdapter extends Psr6Adapter
{
    /**
     * Constructor
     *
     * @param string $namespace
     * @param int $defaultLifetime
     */
    public function __construct(string $namespace = '', int $defaultLifetime = 0)
    {
        parent::__construct(new SymfonyApcuAdapter($namespace, $defaultLifetime));
    }
}
