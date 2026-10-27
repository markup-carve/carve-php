<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ANestedQuotedFootnoteClosesOuterQuoteTest extends TestCase
{
    public function testContinuedFootnoteCannotHostOuterLazyLine(): void
    {
        $source = "> > - a\n> >   [^f]: note\n> >     more\nsee[^f]\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("</blockquote>\n</blockquote>\n<p>see", $html);
        self::assertStringContainsString("<p>note\nmore", $html);
    }

    public function testMarkedParagraphAfterFootnoteMayHostLazyLine(): void
    {
        $source = "> > - a\n> >   [^f]: note\n> >     more\n> > later\nsee[^f]\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<p>later\nsee", $html);
    }
}
