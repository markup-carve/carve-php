<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class NestedNoteReturnsToOuterBodyTest extends TestCase
{
    public function testDivAtOuterBodyColumnLeavesInnerNote(): void
    {
        $source = "r[^1] s[^4]\n\n- [^1]: :\n    [^4]: p\n    ::::\nz\n";
        $html = (new CarveConverter())->convert($source);

        $this->assertStringContainsString("<ul>\n  <li></li>\n</ul>\n<p>z</p>", $html);
        $this->assertStringContainsString("<li id=\"fn1\">\n      <p>:</p>\n      <div>", $html);
        $this->assertStringContainsString("<li id=\"fn2\">\n      <p>p", $html);
    }
}
