<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * An attribute line above a quote does not hide the quote's mark from the lint layout.
 */
final class AttributedQuoteDepthTest extends TestCase
{
    public function testANestedQuoteAtTheContentColumnIsNotOverindented(): void
    {
        $codes = array_map(static fn ($d): string => $d->code, Carve::lint("{#foo}\n>\n> > a\n"));

        self::assertSame([], $codes);
    }

    public function testAListMarkerAfterQuotedTextIsReported(): void
    {
        $codes = array_map(static fn ($d): string => $d->code, Carve::lint("{#foo}\n> x\n> - i\n"));

        self::assertSame(['list-marker-without-blank-line'], $codes);
    }
}
