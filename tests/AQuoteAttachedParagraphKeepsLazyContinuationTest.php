<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AQuoteAttachedParagraphKeepsLazyContinuationTest extends TestCase
{
    public function testAttachedParagraphTakesItsLazyContinuation(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<blockquote><p>q\nq</p></blockquote>\n"),
            CarveConverter::create()->convert("> +\nq\nq\n"),
        );
    }

    public function testAttachedParagraphStillEndsAtAnInterruptingBlock(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<blockquote><p>q</p></blockquote>\n<ul>\n  <li>s</li>\n</ul>\n"),
            CarveConverter::create()->convert("> +\nq\n- s\n"),
        );
    }
}
