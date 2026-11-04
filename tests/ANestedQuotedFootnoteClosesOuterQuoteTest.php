<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ANestedQuotedFootnoteClosesOuterQuoteTest extends TestCase
{
    use RuleATodo;

    public function testContinuedFootnoteCannotHostOuterLazyLine(): void
    {
        $source = "> > - a\n> >   [^f]: note\n> >     more\nsee[^f]\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("</ul>\n  </blockquote>\n</blockquote>", $html);
        self::assertStringContainsString('<li>a</li>', $html);
    }

    public function testMarkedParagraphAfterFootnoteMayHostLazyLine(): void
    {
        $source = "> > - a\n> >   [^f]: note\n> >     more\n> > later\nsee[^f]\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<li>a</li>\n    </ul>\n  </blockquote>\n</blockquote>", $html);
    }

    public function testIndentedDefinitionShapeWithoutListRemainsParagraphText(): void
    {
        $source = "> > para\n> > more\n> >  [^p]: n\ntail\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<p>para\nmore</p></blockquote>\n</blockquote>", $html);
    }
}
