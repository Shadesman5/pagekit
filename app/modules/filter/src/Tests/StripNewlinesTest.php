<?php

declare(strict_types=1);

namespace Pagekit\Filter\Tests;

use Pagekit\Filter\StripNewlinesFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StripNewlinesTest extends TestCase
{
    #[DataProvider('provideNewLineStrings')]
    public function testFilter(string $input, string $output): void
    {
        $filter = new StripNewlinesFilter();

        $this->assertEquals($output, $filter->filter($input));
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function provideNewLineStrings(): array
    {
        return [
            ['', ''],
            ["\n", ''],
            ["\r", ''],
            ["\r\n", ''],
            ['\n', '\n'],
            ['\r', '\r'],
            ['\r\n', '\r\n'],
            ["These newlines should\nbe removed by\r\nthe filter", 'These newlines shouldbe removed bythe filter'],
        ];
    }
}
