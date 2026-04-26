<?php

namespace Pagekit\Filter\Tests;

use Pagekit\Filter\StripNewlinesFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StripNewlinesTest extends TestCase
{
    #[DataProvider('provideNewLineStrings')]
    public function testFilter($input, $output): void
    {
        $filter = new StripNewlinesFilter();

        $this->assertEquals($output, $filter->filter($input));
    }

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
