<?php

declare(strict_types=1);

namespace Pagekit\Twig;

use Pagekit\View\Loader\FilesystemLoader;

class TwigLoader extends \Twig\Loader\FilesystemLoader
{
    protected ?FilesystemLoader $loader;

    /**
     * Constructor.
     *
     * @param FilesystemLoader|null $loader
     */
    public function __construct(?FilesystemLoader $loader = null)
    {
        parent::__construct([]);

        $this->loader = $loader;
    }

    /**
     * {@inheritdoc}
     */
    protected function findTemplate(string $name, bool $throw = true): ?string
    {
        $tpl = (string) preg_replace('/\.twig$/', '', $name);

        if ($this->loader && $file = $this->loader->load($tpl)) {
            return $this->cache[$name] = (string) $file;
        }

        return parent::findTemplate($name, $throw);
    }
}
