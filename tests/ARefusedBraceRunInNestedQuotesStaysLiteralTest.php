<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ARefusedBraceRunInNestedQuotesStaysLiteralTest extends TestCase
{
    public function testTwoQuoteLevelsKeepTheWholeRunLiteral(): void
    {
        self::assertSame(
            "<blockquote>\n  <blockquote><p>{.k\ntail\n<span class=\"tag\"><strong>#x</strong></span>}</p></blockquote>\n</blockquote>",
            CarveConverter::create()->convert("> > {.k\ntail\n> > #x}\n"),
        );
    }

    public function testThreeQuoteLevelsKeepTheWholeRunLiteral(): void
    {
        self::assertSame(
            "<blockquote>\n  <blockquote>\n    <blockquote><p>{.k\ntail\n<span class=\"tag\"><strong>#x</strong></span>}</p></blockquote>\n  </blockquote>\n</blockquote>",
            CarveConverter::create()->convert("> > > {.k\ntail\n> > > #x}\n"),
        );
    }

    public function testAnAttributeValueContainingACommentCloserKeepsTheRunLiteral(): void
    {
        self::assertSame(
            "<blockquote>\n  <blockquote><p>{<span class=\"tag\"><strong>#id</strong></span>\ntail\ntitle=“%}”\n<span class=\"tag\"><strong>#x</strong></span>}</p></blockquote>\n</blockquote>",
            CarveConverter::create()->convert("> > {#id\ntail\n> > title=\"%}\"\n> > #x}\n"),
        );
    }

    public function testWhitespaceBeforeAQuoteMarkerDoesNotAdvanceTheAttributeColumn(): void
    {
        self::assertSame(
            "<blockquote>\n  <blockquote><p>q\n{.k\n<span class=\"tag\"><strong>#x</strong></span>}\ntail</p></blockquote>\n</blockquote>",
            CarveConverter::create()->convert("> > q\n> > {.k\n > > #x}\ntail\n"),
        );
    }
}
