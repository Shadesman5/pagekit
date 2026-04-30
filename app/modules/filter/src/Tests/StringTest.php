<?php

declare(strict_types=1);

namespace Pagekit\Filter\Tests;

use Pagekit\Filter\StringFilter;
use PHPUnit\Framework\TestCase;

class StringTest extends TestCase
{
    public function testFilter(): void
    {
        $filter = new StringFilter();

        $values = [
            23 => "23",
            "23" => "23",
            '"23"' => '"23"',
            '{"foo": "23"}' => '{"foo": "23"}',
            "whateverthisis" => "whateverthisis",
            "!'#+*§$%&/()=?" => "!'#+*§$%&/()=?",
            'äöü' => "äöü", // unicode support please
        ];
        foreach ($values as $in => $out) {
            $this->assertSame($filter->filter($in), $out);
        }

    }

}
