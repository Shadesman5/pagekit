<?php

declare(strict_types=1);

namespace Pagekit\Filter;

class FilterChain implements \Countable, FilterInterface
{
    /**
     * Default priority at which filters are added
     */
    public const DEFAULT_PRIORITY = 1000;

    /**
     * Filter chain
     *
     * @var \SplPriorityQueue<int, callable>
     */
    protected \SplPriorityQueue $filters;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->filters = new \SplPriorityQueue();
    }

    /**
     * Return the count of attached filters
     */
    public function count(): int
    {
        return count($this->filters);
    }

    /**
     * Attach a filter to the chain
     *
     * @param callable|FilterInterface $callback
     *
     * @throws \InvalidArgumentException
     */
    public function attach($callback, int $priority = self::DEFAULT_PRIORITY): self
    {
        if (!is_callable($callback)) {
            if (!$callback instanceof FilterInterface) {
                throw new \InvalidArgumentException(sprintf('Expected a valid PHP callback; received "%s"', (is_object($callback) ? get_class($callback) : gettype($callback))));
            }
            $callback = [$callback, 'filter'];
        }
        $this->filters->insert($callback, $priority);

        return $this;
    }

    /**
     * Merge the filter chain with the one given in parameter
     *
     * @param  FilterChain $filterChain
     */
    public function merge(FilterChain $filterChain): self
    {
        foreach ($filterChain->getFilters() as $filter) {
            $this->attach($filter);
        }

        return $this;
    }

    /**
     * Get all the filters.
     *
     * @return \SplPriorityQueue<int, callable>
     */
    public function getFilters(): \SplPriorityQueue
    {
        return $this->filters;
    }

    /**
     * Returns $value filtered through each filter in the chain.
     *
     * @param  mixed $value Genuinely unknown type — each chained filter may transform the value to a different type.
     * @return mixed Genuinely unknown type — the output type depends on the last filter in the chain.
     */
    public function filter(mixed $value): mixed
    {
        $chain = clone $this->filters;

        $valueFiltered = $value;
        foreach ($chain as $filter) {
            $valueFiltered = call_user_func($filter, $valueFiltered);
        }

        return $valueFiltered;
    }
}
