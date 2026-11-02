<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AWrappedAttributeAndAHostlessCaretLineInTheWalkTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function wrappedAttributeProvider(): array
    {
        return [
            'after paragraph text' => ["a\n {.c\n }\npara\n", "<p>a</p>\n<p class=\"c\">para</p>"],
            'at document level' => ["  {.a\n  .b}\ntail\n", '<p class="a b">tail</p>'],
            'closer at column 0' => ["a\n {.c\n}\npara\n", "<p>a</p>\n<p class=\"c\">para</p>"],
            'in a quote' => ["> a\n>  {.c\n>  }\n> b\n", "<blockquote>\n  <p>a</p>\n  <p class=\"c\">b</p>\n</blockquote>"],
            'a quote needs its marker on every line' => ["> a\n>  {.c\n}\npara\n", "<blockquote><p>a\n{.c\n}\npara</p></blockquote>"],
            'a footnote body at its column' => [
                "[^f]: d\n  {.c\n}\n  x\n\n[^f]\n",
                "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>d</p>\n      <p class=\"c\">x<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
            ],
        ];
    }

    #[DataProvider('wrappedAttributeProvider')]
    public function testAnIndentedWrappedAttributeIsOneAttributeLine(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function caretProvider(): array
    {
        return [
            'an indented quote interrupts' => ["^ p\n- x\n  > q\n", "<p>^ p\n- x</p>\n<blockquote><p>q</p></blockquote>"],
            'an indented heading interrupts' => ["^ p\n- x\n  # h\n", "<p>^ p\n- x</p>\n<section id=\"h\">\n  <h1>h</h1>\n</section>"],
            'in an item a marker of another kind below the column opens a list' => ["- ^ c\n 1. x\n", "<ul>\n  <li>^ c</li>\n</ul>\n<ol>\n  <li>x</li>\n</ol>"],
        ];
    }

    #[DataProvider('caretProvider')]
    public function testACaretLineWithNoHostIsParagraphText(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
