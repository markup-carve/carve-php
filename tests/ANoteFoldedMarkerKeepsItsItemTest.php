<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ANoteFoldedMarkerKeepsItsItemTest extends TestCase
{
    public function testMarkerShapedLazyLineStaysInItsNoteItem(): void
    {
        $source = "[^3]: - a\n   * z\n    ~~~\nq\n\n[^3]\n";
        $html = trim((new CarveConverter())->convert($source));

        self::assertStringContainsString("<li>a\n* z</li>", $html);
        self::assertStringNotContainsString('<li>z</li>', $html);
        self::assertStringContainsString("</ul>\n      <pre><code></code></pre>", $html);
        self::assertStringStartsWith("<p>q</p>\n", $html);
    }

    public function testMarkerAfterABlankStillOpensAnItem(): void
    {
        $source = "[^3]: - a\n\n   * z\n\n[^3]\n";
        $html = trim((new CarveConverter())->convert($source));

        self::assertStringContainsString("<li>a</li>\n      </ul>\n      <ul>\n        <li>z</li>", $html);
    }
}
