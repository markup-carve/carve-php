<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AQuotedTermRetainsItsColumnAfterLazyTextTest extends TestCase
{
    public function testDocumentDescriptionMarkerEndsQuotedTermAfterLazyLines(): void
    {
        self::assertSame(
            "<blockquote>\n  <dl>\n    <dt>t\nline\nmore</dt>\n  </dl>\n</blockquote>\n<p>: d</p>\n",
            CarveConverter::create()->convert("> :: t\nline\nmore\n: d\n"),
        );
    }
}
