<?php

namespace Pagekit\View\Asset;

use Pagekit\Application as App;

// TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
// FileLocatorAsset is dynamically instantiated by AssetFactory via new $class() — cannot use constructor injection.
class FileLocatorAsset extends FileAsset
{
    /**
     * {@inheritdoc}
     */
    public function getSource(): string
    {
        if (!($path = $this->getPath())) {
            return parent::getSource();
        }

        $path = App::getInstance()->get('file')->getUrl($path); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e

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
        return App::getInstance()->get('locator')->get($this->source) ?: false; // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
    }
}
