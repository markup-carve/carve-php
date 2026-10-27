<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class CaretListContinuationTest extends TestCase
{
    public function testUnattachedCaretContinuesOpenItemParagraph(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>a\n^ caption</li>\n</ul>\n"),
            CarveConverter::create()->convert("- a\n^ caption\n"),
        );
    }

    public function testHeadingStillEndsItem(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>a</li>\n</ul>\n<section id=\"H\">\n  <h1>H</h1>\n</section>\n"),
            CarveConverter::create()->convert("- a\n# H\n"),
        );
    }
}
