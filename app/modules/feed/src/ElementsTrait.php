<?php

declare(strict_types=1);

namespace Pagekit\Feed;

trait ElementsTrait
{
    /**
     * @var array<string, array<int, array{0: string, 1: mixed, 2: array<string, mixed>|null}>>
     */
    protected array $elements;

    /**
     * {@inheritdoc}
     *
     * @param  string                    $name
     * @param  mixed                     $value
     * @param  array<string, mixed>|null $attributes
     * @return $this
     */
    public function setElement($name, $value, $attributes = null): object
    {
        unset($this->elements[$name]);

        return $this->addElement($name, $value, $attributes);
    }

    /**
     * {@inheritdoc}
     *
     * @param  string                    $name
     * @param  mixed                     $value
     * @param  array<string, mixed>|null $attributes
     * @return $this
     */
    public function addElement($name, $value, $attributes = null): object
    {
        $this->elements[$name][] = [$name, $value, $attributes];

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed> $elements
     * @return $this
     */
    public function addElements(array $elements): static
    {
        foreach ($elements as $name => $value) {
            if (method_exists($this, $method = 'set'.$name)) {
                if (is_array($value)) {
                    call_user_func_array([$this, $method], $value);
                } else {
                    $this->$method($value);
                }
            } else {
                $this->addElement($name, $value);
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return array<int, array{0: string, 1: mixed, 2: array<string, mixed>|null}>
     */
    public function getElements(): array
    {
        return call_user_func_array('array_merge', $this->elements);
    }
}
