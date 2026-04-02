<?php

namespace Pagekit\Cache\Adapter;

use Symfony\Component\Cache\Adapter\ArrayAdapter as SymfonyArrayAdapter;

/**
 * Array Cache Adapter - In-memory cache
 */
class ArrayAdapter extends Psr6Adapter
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct(new SymfonyArrayAdapter());
    }
}
