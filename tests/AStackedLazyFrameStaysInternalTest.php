<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockGrammar;
use PHPUnit\Framework\TestCase;

final class AStackedLazyFrameStaysInternalTest extends TestCase
{
    public function testNestedQuoteDoesNotRenderAStackedFrame(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<blockquote>\n  <blockquote><p>{.a\n.b}</p></blockquote>\n</blockquote>\n"),
            CarveConverter::create()->convert("> > {.a\n.b}\n"),
        );
    }

    public function testFrameStripperPreservesIndentAndAuthoredText(): void
    {
        self::assertSame('  .b}', BlockGrammar::stripLazyFrame('  ' . BlockGrammar::LAZY_FRAME . BlockGrammar::LAZY_FRAME . '.b}'));
    }
}
