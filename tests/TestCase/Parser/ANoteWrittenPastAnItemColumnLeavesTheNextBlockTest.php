<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ANoteWrittenPastAnItemColumnLeavesTheNextBlockTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'note past the item column before a quote' => [
            "- x\n    [^m]: n\n    > q\n\n[^m]\n",
            "<ul>\n  <li>x\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'task item note before a quote' => [
            "- [ ] x\n      [^m]: n\n      > q\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled aria-label=\"x\"> x\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>",
        ];

        yield 'task first-block note before a fence' => [
            "- [ ] +\n      [^m]: n\n      ```\n      c\n      ```\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <pre><code>c\n</code></pre>\n  </li>\n</ul>",
        ];

        yield 'ordered task-shaped item' => [
            "1. [ ] x\n       [^m]: n\n       > q\n",
            "<ol>\n  <li>[ ] x\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ol>",
        ];

        yield 'a line past the note body column stays in the note' => [
            "- x\n    [^m]: n\n      > q\n\n[^m]\n",
            "<ul>\n  <li>x</li>\n</ul>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n</p>\n      <blockquote><p>q</p></blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];
    }

    #[DataProvider('cases')]
    public function testTheNextBlockStaysInTheItem(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
