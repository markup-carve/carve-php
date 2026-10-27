<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class QuotedListLazyHeadingTest extends TestCase
{
    public function testIndentedHeadingShapeStaysInQuotedParagraph(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>\n    <blockquote><p>+\n# a\nb</p></blockquote>\n  </li>\n</ul>\n"),
            CarveConverter::create()->convert("- +\n> +\n  # a\nb\n"),
        );
    }

    public function testOrdinaryIndentedContinuationStaysInQuote(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>\n    <blockquote><p>+\nb</p></blockquote>\n  </li>\n</ul>\n"),
            CarveConverter::create()->convert("- +\n> +\n  b\n"),
        );
    }
}
