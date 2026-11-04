<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AQuotedAbbreviationLineStaysInItsParagraphTest extends TestCase
{
    public function testShallowerQuotedLineDefinesAndClosesTheInnerParagraph(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<blockquote>\n  <blockquote><p>a</p></blockquote>\n</blockquote>\n"),
            CarveConverter::create()->convert("> > a\n> *[C]: d\n"),
        );
    }

    public function testFlushLeftAbbreviationStillEndsQuote(): void
    {
        // carve#5012 erases the line, so `next` is lazy quote prose now.
        self::assertSame(
            "<blockquote><p>a\nnext</p></blockquote>",
            CarveConverter::create()->convert("> a\n*[C]: d\nnext\n"),
        );
    }
}
