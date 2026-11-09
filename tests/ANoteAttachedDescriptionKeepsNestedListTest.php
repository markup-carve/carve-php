<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ANoteAttachedDescriptionKeepsNestedListTest extends TestCase
{
    public function testNestedItemRemainsInsideDescription(): void
    {
        $source = "[^a]: +\n:: t\n: d\n  - i\n\nsee[^a]\n";
        $expected = "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
            . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
            . "      <dl>\n        <dt>t</dt>\n        <dd>\n          <p>d</p>\n          <ul>\n            <li>i</li>\n          </ul>\n        </dd>\n      </dl>\n"
            . "      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n  </ol>\n</section>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
