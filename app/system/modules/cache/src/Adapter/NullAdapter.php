<?php

namespace Pagekit\Cache\Adapter;

use Symfony\Component\Cache\Adapter\NullAdapter as SymfonyNullAdapter;

/**
 * Null Cache Adapter - No-op cache for testing or when caching is disabled
 */
class NullAdapter extends Psr6Adapter
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct(new SymfonyNullAdapter());
    }
}
