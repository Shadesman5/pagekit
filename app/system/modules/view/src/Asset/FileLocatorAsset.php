<?php

namespace Pagekit\View\Asset;

// TODO: Must be refactored in Step 2.1.6 (PHPStan Level 7→8 — Strict Typing) —
// Static service locator pattern with mixed types. Replace with proper DI
// and type $file as Filesystem, $locator as ResourceLocator.
class FileLocatorAsset extends FileAsset
{
    private static mixed $file = null;
    private static mixed $locator = null;

    public static function setServices(mixed $file, mixed $locator): void
    {
        self::$file = $file;
        self::$locator = $locator;
    }

    /**
     * {@inheritdoc}
     */
    public function getSource(): string
    {
        if (!($path = $this->getPath())) {
            return parent::getSource();
        }

        $path = self::$file->getUrl($path);

        if ($version = $this->getOption('version')) {
            $path .= (false === strpos($path, '?') ? '?' : '&') . 'v=' . $version;
        }

        return $path;
    }

    /**
     * {@inheritdoc}
     */
    public function getPath(): string
    {
        return self::$locator->get($this->source) ?: false;
    }
}
