<?php

declare(strict_types=1);

namespace Pagekit\View\Helper;

use Pagekit\View\Asset\AssetInterface;
use Pagekit\View\Asset\AssetManager;
use Pagekit\View\View;

/**
 * @implements \IteratorAggregate<string, AssetInterface>
 */
class ScriptHelper implements HelperInterface, \IteratorAggregate
{
    protected \Pagekit\View\Asset\AssetManager $scripts;

    public function __construct(?AssetManager $scripts = null)
    {
        $this->scripts = $scripts ?: new AssetManager();
    }

    /**
     * {@inheritdoc}
     */
    public function register(View $view): void
    {
        $view->on('head', function ($event) use ($view) {
            $view->trigger('scripts', [$this->scripts]);
            $event->addResult($this->render());
        }, 5);
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
        return $this->scripts->add($name, $source, $dependencies, $options);
    }

    /**
     * Proxies all method calls to the manager.
     *
     * @param array<int, mixed> $args
     * @return mixed Genuinely unknown type — proxied to the ScriptManager; return type depends on the method called.
     */
    public function __call(string $method, array $args): mixed
    {
        if (!is_callable($callable = [$this->scripts, $method])) {
            throw new \InvalidArgumentException(sprintf('Undefined method call "%s::%s"', get_class($this->scripts), $method));
        }

        return call_user_func_array($callable, $args);
    }

    /**
     * Renders the script tags.
     *
     * NOTE: Inline scripts are NO LONGER supported due to strict CSP policy.
     * Use DataHelper ($view->data('$varname', $value)) for passing data to JavaScript.
     *
     * @see \Pagekit\View\Helper\DataHelper
     */
    public function render(): string
    {
        $output = '';

        foreach ($this->scripts as $script) {
            if ($source = $script->getSource()) {

                $attributes = '';
                foreach (['async', 'defer'] as $attribute) {
                    $attributes .= $script->getOption($attribute) ? ' ' . $attribute : '';
                }

                $output .= sprintf("        <script src=\"%s\"%s></script>\n", $source, $attributes);
            } elseif ($content = $script->getContent()) {
                // Inline scripts are blocked by strict CSP (no 'unsafe-inline')
                // Log warning and skip - use DataHelper instead
                error_log(sprintf(
                    '[Pagekit CSP] Inline script "%s" blocked. Use $view->data() instead.',
                    $script->getName()
                ));
                // Output as HTML comment for debugging (CSP-safe)
                $output .= sprintf(
                    "        <!-- CSP: Inline script '%s' blocked. Use DataHelper. -->\n",
                    htmlspecialchars($script->getName(), ENT_QUOTES, 'UTF-8')
                );
            }
        }

        return $output;
    }

    /**
     * Returns an iterator for script tags.
     *
     * @return \Traversable<string, AssetInterface>
     */
    public function getIterator(): \Traversable
    {
        return $this->scripts->getIterator();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'script';
    }
}
