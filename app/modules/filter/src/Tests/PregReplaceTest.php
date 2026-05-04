<?php

declare(strict_types=1);

namespace Pagekit\Filter\Tests;

use Pagekit\Filter\PregReplaceFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PregReplaceTest extends TestCase
{
    protected ?PregReplaceFilter $filter = null;

    public function setUp(): void
    {
        $this->filter = new PregReplaceFilter();
    }

    public function testRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->filter->filter('foo');
    }

    public function testModifierE(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->filter->setPattern('/foo/e');
    }

    public function testPatternArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @var mixed $invalid */
        $invalid = null;
        $this->filter->setPattern($invalid);
    }

    public function testReplacementArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @var mixed $invalid */
        $invalid = null;
        $this->filter->setReplacement($invalid);
    }

    /**
     * @param string|array<int, string> $pattern
     * @param string|array<int, string> $replacement
     * @param string                    $in
     * @param string                    $out
     */
    #[DataProvider('provider')]
    public function testFilter($pattern, $replacement, $in, $out): void
    {
        $this->filter->setPattern($pattern);
        $this->assertSame($this->filter->getPattern(), $pattern);

        $this->filter->setReplacement($replacement);
        $this->assertSame($this->filter->getReplacement(), $replacement);

        $this->assertSame($this->filter->filter($in), $out);
    }

    /**
     * @return array<int, array{0: string|array<int, string>, 1: string|array<int, string>, 2: string, 3: string}>
     */
    public static function provider(): array
    {
        return [
            ['/foo/i', '', 'Foobar', 'bar'],
            [['/foo/', '/bar/'], ['FOO', 'BAR'], 'foobar', 'FOOBAR'],
        ];
    }

}
