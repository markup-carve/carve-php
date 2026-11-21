<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class FuzzInlineSpellingTest extends TestCase
{
    public function testLongDotRunsRemainTextWhenWritten(): void
    {
        foreach (range(3, 15) as $length) {
            $text = 'a' . str_repeat('.', $length) . 'b';
            $source = Carve::importHtml('<p>' . $text . '</p>')->source;
            self::assertSame('<p>' . $text . '</p>', trim(Carve::toHtml($source)), $text);
        }
    }

    public function testArrowBeforeSmartQuoteIsNotAnOpeningQuote(): void
    {
        $source = '<-->"a"';
        $format = Carve::formatWithDiagnostics($source);
        self::assertSame([], $format->diagnostics);
        self::assertSame($source . "\n", $format->source);
    }

    public function testEmptySpanWithoutAttributesUsesAnEmptyAttributeBlock(): void
    {
        $source = 'a []{} b';
        $format = Carve::formatWithDiagnostics($source);
        self::assertSame([], $format->diagnostics);
        self::assertSame($source . "\n", $format->source);
    }
}
