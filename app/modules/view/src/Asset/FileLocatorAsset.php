<?php

declare(strict_types=1);

namespace Pagekit\View\Asset;

use Pagekit\Filesystem\Filesystem;
use Pagekit\Filesystem\Locator;

class FileLocatorAsset extends FileAsset
{
    /**
     * @param array<int, string>   $dependencies
     * @param array<string, mixed> $options
     */
    public function __construct(
        string $name,
        string $source,
        array $dependencies,
        array $options,
        private readonly Filesystem $file,
        private readonly Locator $locator,
    ) {
        parent::__construct($name, $source, $dependencies, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function getSource(): string
    {
        if (!($path = $this->getPath())) {
            return parent::getSource() ?? '';
        }

        $url = $this->file->getUrl($path);
        if ($url === false) {
            return '';
        }

        if ($version = $this->getOption('version')) {
            $url .= (false === strpos($url, '?') ? '?' : '&') . 'v=' . (string) $version;
        }

        return $url;
    }

    /**
     * {@inheritdoc}
     */
    public function getPath(): string
    {
        return $this->source !== null ? ($this->locator->get($this->source) ?: '') : '';
    }
}
