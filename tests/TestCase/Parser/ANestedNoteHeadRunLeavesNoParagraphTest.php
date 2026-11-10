<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An accepted run on a nested note head opens no paragraph, so a flush-left follower leaves every note (CARVE-P2-035, PART 0 S4, carve#5842).
 */
final class ANestedNoteHeadRunLeavesNoParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        yield 'two nested notes' => [
            "[^q]: [^r]: {.x\n            .y}\nz\n\nx[^q][^r]\n",
            "<p>z</p>\n<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a><a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n    <li id=\"fn2\">\n      <p><a href=\"#fnref2\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'three nested notes' => [
            "[^q]: [^r]: [^s]: {.x\n                  .y}\nz\n\nx[^q][^r][^s]\n",
            "<p>z</p>\n<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a><a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a><a id=\"fnref3\" href=\"#fn3\" role=\"doc-noteref\"><sup>3</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n    <li id=\"fn2\">\n      <p><a href=\"#fnref2\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n    <li id=\"fn3\">\n      <p><a href=\"#fnref3\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'list item host' => [
            "- [^q]: [^r]: {.x\n              .y}\nz\n\nx[^q][^r]\n",
            "<ul>\n  <li></li>\n</ul>\n<p>z</p>\n<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a><a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n    <li id=\"fn2\">\n      <p><a href=\"#fnref2\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];
    }

    #[DataProvider('shapes')]
    public function testShape(string $source, string $html): void
    {
        $this->assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
