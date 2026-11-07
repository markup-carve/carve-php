<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block at a `[^n]: +` note's content column ends the attached paragraph; the note goes on only with
 * lines at its column (PART 9 §17 L3, carve#5331).
 */
final class ABlockAfterAnAttachedNoteParagraphEndsTheAttachmentTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'a column-0 line after a heading leaves the note' => [
            "[^1]\n\n[^1]: +\nh\n  # H\nz\n",
            "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<p>z</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>h</p>\n      <h1 id=\"H\">H</h1>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a column-0 line after a thematic break leaves the note' => [
            "[^1]\n\n[^1]: +\nh\n  ---\nz\n",
            "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<p>z</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>h</p>\n      <hr>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a line at the note column after a heading stays in the note' => [
            "[^1]\n\n[^1]: +\nh\n  # H\n  k\n",
            "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>h</p>\n      <h1 id=\"H\">H</h1>\n      <p>k<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];
    }

    #[DataProvider('documents')]
    public function testDocument(string $source, string $html): void
    {
        self::assertSame($html, (new CarveConverter())->convert($source));
    }
}
