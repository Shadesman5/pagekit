<?php

declare(strict_types=1);

namespace Pagekit\View\Asset;

class StringAsset extends Asset
{
    /**
     * {@inheritdoc}
     *
     * @param array<int, string>   $dependencies
     * @param array<string, mixed> $options
     */
    public function __construct(string $name, string $source, array $dependencies = [], array $options = [])
    {
        parent::__construct($name, null, $dependencies, $options);

        $this->setContent($source);
    }

    /**
     * {@inheritdoc}
     */
    public function hash(string $salt = ''): string
    {
        return hash('crc32b', $this->getContent().$salt);
    }
}
