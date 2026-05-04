<?php

declare(strict_types=1);

namespace Pagekit\Filter\Tests;

use Pagekit\Filter\FilterChain;
use Pagekit\Filter\FilterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FilterChainTest extends TestCase
{
    public function testAttach(): void
    {
        $chain = new FilterChain();

        $chain->attach(fn ($value) => $value);
        $this->assertCount(1, $chain->getFilters());
    }

    public function testAttachFailed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $chain = new FilterChain();

        $chain->attach(new \stdClass());
    }

    public function testMerge(): void
    {
        $chain = new FilterChain();
        $chain->attach($this->getFilterMock());

        $chain2 = new FilterChain();
        $chain2->attach($this->getFilterMock());
        $chain->merge($chain2);

        $this->assertCount(2, $chain->getFilters());
    }

    public function testCount(): void
    {
        $chain = new FilterChain();
        $this->assertCount(0, $chain);

        $chain->attach($this->getFilterMock());
        $this->assertCount(1, $chain);

        $chain->attach($this->getFilterMock());
        $this->assertCount(2, $chain);
    }

    public function testFilter(): void
    {
        $chain = new FilterChain();
        $chain->attach(fn ($value) => 'filtered_'.$value);

        $value = 'TEST';
        $this->assertEquals('filtered_TEST', $chain->filter($value));
    }

    /**
     * @return FilterInterface&MockObject
     */
    protected function getFilterMock(): FilterInterface
    {
        $filter = $this->createMock(FilterInterface::class);
        $filter->expects($this->any())
               ->method('filter');

        return $filter;
    }
}
