<?php

declare(strict_types=1);

namespace Pagekit\View\Helper;

use Pagekit\View\Asset\AssetInterface;
use Pagekit\View\Asset\AssetManager;
use Pagekit\View\View;

/**
 * @implements \IteratorAggregate<string, AssetInterface>
 */
class StyleHelper implements HelperInterface, \IteratorAggregate
{
    protected \Pagekit\View\Asset\AssetManager $styles;

    public function __construct(?AssetManager $styles = null)
    {
        $this->styles = $styles ?: new AssetManager();
    }

    /**
     * {@inheritdoc}
     */
    public function register(View $view): void
    {
        $view->on('head', function ($event) use ($view) {
            $view->trigger('styles', [$this->styles]);
            $event->addResult($this->render());
        }, 15);
    }

    /**
     * Add shortcut.
     *
     * @see add()
     *
     * @param array<int, string>   $dependencies
     * @param array<string, mixed> $options
     */
    public function __invoke(string $name, mixed $source = null, array $dependencies = [], array $options = []): ?AssetInterface
    {
        return $this->styles->add($name, $source, $dependencies, $options);
    }

    /**
     * Proxies all method calls to the manager.
     *
     * @param array<int, mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        if (!is_callable($callable = [$this->styles, $method])) {
            throw new \InvalidArgumentException(sprintf('Undefined method call "%s::%s"', get_class($this->styles), $method));
        }

        return call_user_func_array($callable, $args);
    }

    /**
     * Renders the style tags.
     */
    public function render(): string
    {
        $output = '';

        foreach ($this->styles as $style) {
            if ($source = $style->getSource()) {
                $output .= sprintf("        <link href=\"%s\" rel=\"stylesheet\">\n", $source);
            } elseif ($content = $style->getContent()) {
                $output .= sprintf("        <style>%s</style>\n", $content);
            }
        }

        return $output;
    }

    /**
     * Returns an iterator for style tags.
     *
     * @return \Traversable<string, AssetInterface>
     */
    public function getIterator(): \Traversable
    {
        return $this->styles->getIterator();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'style';
    }
}
