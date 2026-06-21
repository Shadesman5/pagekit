<?php

declare(strict_types=1);

namespace Pagekit\View\Helper;

use Pagekit\View\View;

/**
 * @implements \IteratorAggregate<string, mixed>
 */
class MetaHelper implements HelperInterface, \IteratorAggregate
{
    /** @var array<string, string|array<string, string>> */
    protected array $metas = [];

    /**
     * {@inheritdoc}
     */
    public function register(View $view): void
    {
        $view->on('head', function ($event) use ($view) {
            $view->trigger('meta', [$this]);
            $event->addResult($this->render());
        }, 20);
    }

    /**
     * Adds meta tags.
     *
     * Null/false entries are silently skipped so callers can pass values
     * straight from config lookups without pre-filtering.
     *
     * @param array<string, string|array<string, string>|null|false> $metas
     */
    public function __invoke(array $metas): self
    {
        foreach ($metas as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            $this->add($name, $value);
        }

        return $this;
    }

    /**
     * Gets a meta tag.
     *
     * @return string|array<string, string>|null
     */
    public function get(string $name): string|array|null
    {
        return isset($this->metas[$name]) ? $this->metas[$name] : null;
    }

    /**
     * Adds a meta tag.
     *
     * A null value is treated as "not set" and skipped, mirroring how
     * Pagekit config lookups return null for unset keys.
     *
     * @param string|array<string, string>|null $value
     */
    public function add(string $name, string|array|null $value = null): self
    {
        if ($value === null || $value === '' || $value === []) {
            return $this;
        }

        $this->metas[$name] = $value;

        return $this;
    }

    /**
     * Removes a meta tag.
     */
    public function remove(string $name): self
    {
        if (isset($this->metas[$name])) {
            unset($this->metas[$name]);
        }

        return $this;
    }

    /**
     * Renders the meta tags.
     */
    public function render(): string
    {
        $output = '';

        foreach ($this->metas as $name => $value) {

            if (preg_match('/^link:?/i', $name)) {

                $attrs = is_array($value) ? $value : ['href' => $value];

                if (!isset($attrs['rel'])) {
                    $attrs['rel'] = substr($name, 5);
                }

                $attributes = '';
                foreach ($attrs as $attr => $val) {
                    $attributes .= sprintf(' %s="%s"', $attr, htmlspecialchars($val));
                }
                $output .= sprintf("        <link%s>\n", $attributes);

            } else {

                $scalar = is_array($value) ? implode(' ', $value) : $value;
                $scalar = htmlspecialchars($scalar);

                if ($name == 'title') {
                    $output .= sprintf("        <title>%s</title>\n", $scalar);
                } elseif ($name == 'base') {
                    $output .= sprintf("        <base href=\"%s\">\n", $scalar);
                } elseif ($name == 'canonical') {
                    $output .= sprintf("        <link rel=\"%s\" href=\"%s\">\n", $name, $scalar);
                } elseif (preg_match('/^(og|fb|twitter|article):/i', $name)) {
                    $output .= sprintf("        <meta property=\"%s\" content=\"%s\">\n", $name, $scalar);
                } else {
                    $output .= sprintf("        <meta name=\"%s\" content=\"%s\">\n", $name, $scalar);
                }
            }
        }

        return $output;
    }

    /**
     * Returns an iterator for meta tags.
     *
     * @return \ArrayIterator<string, string|array<string, string>>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->metas);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'meta';
    }
}
