<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AQuotedAbbreviationLineStaysInItsParagraphTest extends TestCase
{
    public function testShallowerQuotedLineKeepsInnerParagraphOpen(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<blockquote>\n  <blockquote><p>a\n*[C]: d</p></blockquote>\n</blockquote>\n"),
            CarveConverter::create()->convert("> > a\n> *[C]: d\n"),
        );
    }

    public function testFlushLeftAbbreviationStillEndsQuote(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<blockquote><p>a</p></blockquote>\n<p>next</p>\n"),
            CarveConverter::create()->convert("> a\n*[C]: d\nnext\n"),
        );
    }
}
