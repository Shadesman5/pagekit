<?php

declare(strict_types=1);

namespace Pagekit\View\Asset;

class UrlAsset extends Asset
{
    /**
     * {@inheritdoc}
     */
    public function hash($salt = ''): string
    {
        return hash('crc32b', $this->source . $salt);
    }
}
