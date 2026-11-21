<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Resolve;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class NfcReferenceTest extends TestCase
{
    public function testC2I097CrossReferenceMatchesNfcId(): void
    {
        self::assertStringContainsString(
            '<a href="#Café">Café</a>',
            Carve::toHtml("# Café\n\n</#Cafe\u{0301}>"),
        );
    }

    public function testC2I084HeadingLookupNormalizesPlainText(): void
    {
        self::assertStringContainsString(
            "<a href=\"#Café\">Cafe\u{0301}</a>",
            Carve::toHtml("# Café\n\n[Cafe\u{0301}][]"),
        );
    }
}
