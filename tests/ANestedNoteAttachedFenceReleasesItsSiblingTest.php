<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ANestedNoteAttachedFenceReleasesItsSiblingTest extends TestCase
{
    public function testParagraphAtNoteMarkerColumnReturnsToItem(): void
    {
        $source = "- x[^a]\n\n  [^a]: +\n  ```\n    a\n  ```\n  p\n";
        $expected = "<ul>\n  <li><p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n    <p>p</p>\n  </li>\n</ul>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <pre><code>  a\n</code></pre>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
