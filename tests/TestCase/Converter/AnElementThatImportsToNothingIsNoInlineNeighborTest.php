<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve-rs#2029: an element that imports to nothing does not
 * pull a comment beside it into an inline run, so the comment stays a block
 * comment, where a paragraph holding only an inline comment would add an
 * empty paragraph the HTML never had.
 */
class AnElementThatImportsToNothingIsNoInlineNeighborTest extends TestCase
{
    public function testACommentBesideDroppedElementsStaysABlockComment(): void
    {
        $html = "<section>\n<h2>T</h2><!--/lit-part-->\n<x-el></x-el>\n<p>y</p><!--c-->\n<x-el>w</x-el>\n<p>z</p>"
            . "<!--\nA b\n--><noscript><img src=\"a.png\" alt=\"\"></noscript>\n<p>v</p></section>";
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame(
            "## T\n\n%%%\n/lit-part\n%%%\n\ny\n\n{% c %} w\n\nz\n\n%%%\n\nA b\n\n%%%\n\nv\n",
            $result->value,
        );
        $rows = array_map(
            static fn (array $row): array => [$row['code'], $row['message']],
            $result->report()['diagnostics'],
        );
        $this->assertSame([
            ['element-unwrapped', 'Unwrapped unsupported <section> element'],
            ['element-dropped', 'Dropped empty <x-el> element'],
            ['element-unwrapped', 'Unwrapped unsupported <x-el> element'],
            ['element-dropped', 'Dropped active <noscript> element'],
        ], $rows);
    }

    public function testCommentsBesideScriptsStayBlockComments(): void
    {
        $this->assertSame(
            "a\n\n%%%\n x \n%%%\n\n%%%\n y \n%%%\n\nz\n",
            (new HtmlToCarve())->convert("<p>a</p><!-- x --><script>var a=1;</script>\n<!-- y --><script>b()</script>\n<p>z</p>"),
        );
    }

    public function testACommentBetweenContainersBesideANoscript(): void
    {
        $html = "<div class=\"a\"><p>x</p></div><!--\nA b\n--><noscript><img src=\"a.png\" alt=\"\"></noscript>\n<div class=\"b\"><p>y</p></div>";

        $this->assertSame(
            "::: a\nx\n:::\n\n%%%\n\nA b\n\n%%%\n\n::: b\ny\n:::\n",
            (new HtmlToCarve())->convert($html),
        );
    }
}
