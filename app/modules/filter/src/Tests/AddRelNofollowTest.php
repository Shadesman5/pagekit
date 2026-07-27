<?php

declare(strict_types=1);

namespace Pagekit\Filter\Tests;

use Pagekit\Filter\AddRelNofollowFilter;
use PHPUnit\Framework\TestCase;

class AddRelNofollowTest extends TestCase
{
    public function testFilter(): void
    {
        $filter = new AddRelNofollowFilter();

        $this->assertStringContainsString('rel="nofollow"', (string) $filter->filter('<a href="http://www.example.com/">text</a>'));
        $this->assertStringContainsString('rel="nofollow"', (string) $filter->filter('<A href="http://www.example.com/">text</a>'));
    }

    public function testFilterMatchesSlashObfuscatedAnchor(): void
    {
        $filter = new AddRelNofollowFilter();

        $this->assertStringContainsString('rel="nofollow"', (string) $filter->filter('<a/href="http://www.example.com/">text</a>'));
    }

    public function testFilterIsSafeAgainstNullByteObfuscation(): void
    {
        $filter = new AddRelNofollowFilter();

        $filtered = (string) $filter->filter("<\0a\0 href=\"http://www.example.com/\">text</a>");

        $this->assertStringContainsString('rel="nofollow"', $filtered);
        $this->assertStringNotContainsString("\0", $filtered);
    }

    public function testFilterReplacesExistingRelFollow(): void
    {
        $filter = new AddRelNofollowFilter();

        $filtered = (string) $filter->filter('<a href="http://www.example.com/" rel="follow">text</a>');

        $this->assertStringContainsString('rel="nofollow"', $filtered);
        $this->assertStringNotContainsString('rel="follow"', $filtered);
    }

}
