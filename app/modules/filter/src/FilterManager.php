<?php

declare(strict_types=1);

namespace Pagekit\Filter;

class FilterManager
{
    /** @var array<string, class-string<FilterInterface>>|null */
    protected ?array $defaults = [
        'addrelnofollow' => 'Pagekit\Filter\AddRelNofollowFilter',
        'alnum' => 'Pagekit\Filter\AlnumFilter',
        'alpha' => 'Pagekit\Filter\AlphaFilter',
        'bool' => 'Pagekit\Filter\BooleanFilter',
        'boolean' => 'Pagekit\Filter\BooleanFilter',
        'digits' => 'Pagekit\Filter\DigitsFilter',
        'int' => 'Pagekit\Filter\IntFilter',
        'integer' => 'Pagekit\Filter\IntFilter',
        'float' => 'Pagekit\Filter\FloatFilter',
        'json' => 'Pagekit\Filter\JsonFilter',
        'pregreplace' => 'Pagekit\Filter\PregReplaceFilter',
        'slugify' => 'Pagekit\Filter\SlugifyFilter',
        'string' => 'Pagekit\Filter\StringFilter',
        'stripnewlines' => 'Pagekit\Filter\StripNewlinesFilter',
    ];

    /**
     * @var array<string, FilterInterface|class-string<FilterInterface>>
     */
    protected array $filters = [];

    /**
     * Constructor.
     *
     * @param array<string, class-string<FilterInterface>>|null $defaults
     */
    public function __construct(?array $defaults = null)
    {
        if (null !== $defaults) {
            $this->defaults = $defaults;
        }
    }

    /**
     * Apply shortcut.
     *
     * @see apply()
     *
     * @param array<string, mixed> $options
     */
    public function __invoke(mixed $value, string $name, array $options = []): mixed
    {
        return $this->apply($value, $name, $options);
    }

    /**
     * Apply a filter.
     *
     * @param array<string, mixed> $options
     *
     * @throws \InvalidArgumentException
     */
    public function apply(mixed $value, string $name, array $options = []): mixed
    {
        return $this->get($name, $options)->filter($value);
    }

    /**
     * Gets a filter.
     *
     * @param array<string, mixed> $options
     *
     * @throws \InvalidArgumentException
     */
    public function get(string $name, array $options = []): FilterInterface
    {
        if ($this->defaults !== null && array_key_exists($name, $this->defaults)) {
            $this->filters[$name] = $this->defaults[$name];
        }

        if (!array_key_exists($name, $this->filters)) {
            throw new \InvalidArgumentException(sprintf('Filter "%s" is not defined.', $name));
        }

        if (is_string($class = $this->filters[$name])) {
            $this->filters[$name] = new $class();
        }

        $filter = clone $this->filters[$name];
        $filter->setOptions($options);

        return $filter;
    }

    /**
     * Registers a filter.
     *
     * @param FilterInterface|class-string<FilterInterface> $filter
     *
     * @throws \InvalidArgumentException
     */
    public function register(string $name, $filter): void
    {
        if (array_key_exists($name, $this->filters)) {
            throw new \InvalidArgumentException(sprintf('Filter with the name "%s" is already defined.', $name));
        }

        if (is_string($filter) && !class_exists($filter)) {
            throw new \InvalidArgumentException(sprintf('Unknown filter with the class name "%s".', $filter));
        }

        $this->filters[$name] = $filter;
    }
}
