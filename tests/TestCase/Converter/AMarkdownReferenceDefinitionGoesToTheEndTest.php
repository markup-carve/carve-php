<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `carve fmt` writes every reference definition at the end of the document, one
 * line each, so the import does too. Joined onto one line, a destination or
 * title on the next line stays part of the definition.
 */
class AMarkdownReferenceDefinitionGoesToTheEndTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function shapes(): array
    {
        return [
            'between paragraphs' => ["a [x]\n\n[x]: /u\n\nb\n", "a [x][]\n\nb\n\n[x]: /u\n", "<p>a <a href=\"/u\">x</a></p>\n<p>b</p>\n"],
            'before a paragraph' => ["[x]: /u\nb [x]\n", "b [x][]\n\n[x]: /u\n", "<p>b <a href=\"/u\">x</a></p>\n"],
            'two in source order' => ["[b]: /2\n[a]: /1\n\n[a] [b]\n", "[a][] [b][]\n\n[b]: /2\n\n[a]: /1\n", "<p><a href=\"/1\">a</a> <a href=\"/2\">b</a></p>\n"],
            'in a quote' => ["> [x]: /u\n> b [x]\n", "> b [x][]\n\n[x]: /u\n", "<blockquote><p>b <a href=\"/u\">x</a></p></blockquote>\n"],
            'as an item' => ["- [x]: /u\n- b [x]\n", "- +\n- b [x][]\n\n[x]: /u\n", "<ul>\n  <li></li>\n  <li>b <a href=\"/u\">x</a></li>\n</ul>\n"],
            'as an item with a lazy line' => ["- [x]: /u\nb [x]\n", "- b [x][]\n\n[x]: /u\n", "<ul>\n  <li>b <a href=\"/u\">x</a></li>\n</ul>\n"],
            'in a quote with a lazy line' => ["> [x]: /u\nb [x]\n", "> b [x][]\n\n[x]: /u\n", "<blockquote><p>b <a href=\"/u\">x</a></p></blockquote>\n"],
            'two in an item' => ["- [x]: /u\n  [y]: /v\n\n[x] [y]\n", "- +\n\n[x][] [y][]\n\n[x]: /u\n\n[y]: /v\n", "<ul>\n  <li></li>\n</ul>\n<p><a href=\"/u\">x</a> <a href=\"/v\">y</a></p>\n"],
            'a repeated label' => ["[x]: /1\n[X]: /2\n\n[x]\n", "[x][]\n\n[x]: /1\n", "<p><a href=\"/1\">x</a></p>\n"],
            'in an item' => ["- a [x]\n\n  [x]: /u\n", "- a [x][]\n\n[x]: /u\n", "<ul>\n  <li>a <a href=\"/u\">x</a></li>\n</ul>\n"],
            'a destination on the next line' => ["[x]:\n/u\nb [x]\n", "b [x][]\n\n[x]: /u\n", "<p>b <a href=\"/u\">x</a></p>\n"],
            'a title on the next line' => ["[x]: /u\n\"t\"\nb [x]\n", "b [x][]\n\n[x]: /u \"t\"\n", "<p>b <a href=\"/u\" title=\"t\">x</a></p>\n"],
            'before a fence' => ["[x]: /u\n\n```\nc\n```\n", "```\nc\n```\n\n[x]: /u\n", "<pre><code>c\n</code></pre>\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheDefinitionIsWrittenLast(string $markdown, string $carve, string $html): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $imported);
        $this->assertSame($html, (new CarveConverter())->convert($imported));
        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    public function testAQuoteHoldingOnlyADefinitionStaysAQuote(): void
    {
        $imported = (new MarkdownToCarve())->convert("a\n\n> [x]: /u\n\nb\n");

        $this->assertSame("a\n\n> \n\nb\n\n[x]: /u\n", $imported);
        $this->assertSame("<p>a</p>\n<blockquote>\n\n</blockquote>\n<p>b</p>\n", (new CarveConverter())->convert($imported));
    }

    public function testADefinitionPartingTwoListsStaysBetweenThem(): void
    {
        $imported = (new MarkdownToCarve())->convert("- a\n\n[x]: /u\n- b [x]\n");

        $this->assertSame("<ul>\n  <li>a</li>\n</ul>\n<ul>\n  <li>b <a href=\"/u\">x</a></li>\n</ul>\n", (new CarveConverter())->convert($imported));
    }

    public function testALineWithNoDestinationClaimsNoLabel(): void
    {
        $imported = (new MarkdownToCarve())->convert("[x]:\n\n[x]: /v\n\n[x]\n");

        $this->assertSame("<p>[x]:</p>\n<p><a href=\"/v\">x</a></p>\n", (new CarveConverter())->convert($imported));
    }

    public function testAFootnoteStaysWhereItIs(): void
    {
        $this->assertSame("a[^1]\n\n[^1]: note\n\nb\n", (new MarkdownToCarve())->convert("a[^1]\n\n[^1]: note\n\nb\n"));
    }
}
