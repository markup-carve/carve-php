<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class NestedQuoteAfterEmptySiblingItemTest extends TestCase
{
    public function testFormatterKeepsNestedQuoteAfterEmptySibling(): void
    {
        $source = "- {.x}\n- > - {k=v}\n";
        $formatted = CarveConverter::toCarve($source);
        $converter = new CarveConverter();

        self::assertSame("- {empty}\n- > - {empty}\n", $formatted);
        self::assertSame($converter->convert($source), $converter->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    public function testLaterSiblingMarkerIsNotItsNestedSuffix(): void
    {
        $html = (new CarveConverter())->convert("- +\n- > - +\n");

        self::assertSame(
            "<ul>\n  <li>+</li>\n  <li>\n    <blockquote>\n      <ul>\n        <li>+</li>\n      </ul>\n    </blockquote>\n  </li>\n</ul>",
            $html,
        );
    }
}
