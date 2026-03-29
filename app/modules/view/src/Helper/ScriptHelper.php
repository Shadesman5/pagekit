<?php

namespace Pagekit\View\Helper;

use Pagekit\View\Asset\AssetManager;
use Pagekit\View\View;

class ScriptHelper implements HelperInterface, \IteratorAggregate
{
    protected \Pagekit\View\Asset\AssetManager $scripts;

    /**
     * Constructor.
     *
     * @param AssetManager $scripts
     */
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
     */
    public function __invoke($name, $source = null, $dependencies = [], $options = [])
    {
        return $this->scripts->add($name, $source, $dependencies, $options);
    }

    /**
     * Proxies all method calls to the manager.
     *
     * @param  string $method
     * @param  array $args
     * @return mixed
     */
    public function __call($method, $args)
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
