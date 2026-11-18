<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Resolve;

use MarkupCarve\Carve2\Carve2;
use PHPUnit\Framework\TestCase;

final class NfcReferenceTest extends TestCase
{
    public function testC2I097CrossReferenceMatchesNfcId(): void
    {
        self::assertStringContainsString(
            '<a href="#Café">Café</a>',
            Carve2::toHtml("# Café\n\n</#Cafe\u{0301}>"),
        );
    }

    public function testC2I084HeadingLookupNormalizesPlainText(): void
    {
        self::assertStringContainsString(
            "<a href=\"#Café\">Cafe\u{0301}</a>",
            Carve2::toHtml("# Café\n\n[Cafe\u{0301}][]"),
        );
    }
}
