<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * An inline element holding blocks keeps its link or span and flattens the
 * blocks with a separator, as carve-js and carve-rs do (carve-php#2545).
 */
class AnInlineElementHoldingBlocksStaysInlineTest extends TestCase
{
    public function testTheElementStaysAndItsBlocksFlatten(): void
    {
        $html = "<p>x</p><a href=\"/t\"><div>A</div><div>B</div></a>\n"
            . "<span class=\"w\"><p>one</p><p>two</p></span>\n<b><div>bold</div></b>";
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame("x\n\n[A B](/t) [one two]{.w} *bold*\n", $result->value);
        $this->assertSame(
            [
                ['element-unwrapped', '/a[2]/div[1]'],
                ['element-unwrapped', '/a[2]/div[2]'],
                ['element-unwrapped', '/span[4]/p[1]'],
                ['element-unwrapped', '/span[4]/p[2]'],
                ['element-unwrapped', '/b[6]/div[1]'],
            ],
            array_map(
                static fn ($row): array => [$row->code, $row->path],
                $result->diagnostics,
            ),
        );
    }

    public function testABlockInsideAHeadingFlattensWithASeparator(): void
    {
        $this->assertSame("## a b\n", (new HtmlToCarve())->convert('<h2><div>a</div><div>b</div></h2>'));
    }

    public function testAnEmptyBlockTakesNoSeparator(): void
    {
        $this->assertSame("*b c*\n", (new HtmlToCarve())->convert('<b><div></div><div>b</div>c</b>'));
    }
}
