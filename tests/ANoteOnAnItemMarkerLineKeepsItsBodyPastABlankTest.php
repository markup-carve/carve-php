<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected HTML is the spec model's: a blank run continues a note on an item's marker line
 * when the next line reaches the note's body column, whatever block the note last held (PART 9 section 16).
 */
final class ANoteOnAnItemMarkerLineKeepsItsBodyPastABlankTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'a quote in the note then a blank' => ["- [^a]: text\n    > q\n\n    more\n\nx[^a]\n", "<ul>\n  <li></li>\n</ul>\n<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>text</p>\n      <blockquote><p>q</p></blockquote>\n      <p>more<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"];
        yield 'a fence in the note then a blank' => ["- [^a]: text\n      ~~~\n      one\n      ~~~\n\n      more\n\nx[^a]\n", "<ul>\n  <li></li>\n</ul>\n<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>text</p>\n      <pre><code>one\n</code></pre>\n      <p>more<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"];
        yield 'two fences across a blank' => ["- [^a]: text\n      ~~~\n      one\n      ~~~\n\n      ~~~\n      two\n      ~~~\n\nx[^a]\n", "<ul>\n  <li></li>\n</ul>\n<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>text</p>\n      <pre><code>one\n</code></pre>\n      <pre><code>two\n</code></pre>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"];
        yield 'a line below the note column still leaves it' => ["- [^a]: text\n    > q\n\n  more\n\nx[^a]\n", "<ul>\n  <li><p>more</p></li>\n</ul>\n<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>text</p>\n      <blockquote><p>q</p></blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
