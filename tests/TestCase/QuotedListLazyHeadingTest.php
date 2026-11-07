<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

final class QuotedListLazyHeadingTest extends TestCase
{
    use RuleATodo;

    public function testIndentedHeadingAfterTheQuoteOpensInTheItem(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>\n    <blockquote>\n\n    </blockquote>\n    <h1 id=\"a\">a</h1>\n  </li>\n</ul>\n<p>b</p>\n"),
            CarveConverter::create()->convert("- +\n> +\n  # a\nb\n"),
        );
    }

    public function testOrdinaryIndentedContinuationStaysInQuote(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>\n    <blockquote>\n\n    </blockquote>\n    b\n  </li>\n</ul>\n"),
            CarveConverter::create()->convert("- +\n> +\n  b\n"),
        );
    }
}
