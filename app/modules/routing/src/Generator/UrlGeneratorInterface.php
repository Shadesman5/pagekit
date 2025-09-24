<?php

namespace Pagekit\Routing\Generator;

interface UrlGeneratorInterface
{
    /**
     * Generates a link url.
     * Using a custom integer value that doesn't conflict with Symfony constants
     */
    const LINK_URL = 100;
}
