<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088: a reported kind escapes every potential opener of that kind in the leaf block.
 */
final class KindEscapeTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sources(): array
    {
        return [
            'brackets of a paragraph, not of the next' => ["\\[a] and [b]\n\n[c]\n\n[a]: /u\n", "\\[a] and \\[b]\n\n[c]\n\n[a]: /u\n"],
            'slashes that can open' => ["\\/a/ and/or /b\n", "\\/a/ and/or \\/b\n"],
            'a kind reported in link text' => ["\\:a: [\\:b:](/u) :c:\n", "\\:a\\: [\\:b\\:](/u) :c:\n"],
            'an escaped mark before a closer gives its kind' => ["{^a\\^}^} x^ y\n", "{^a\\^}^} x\\^ y\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testFormat(string $source, string $canonical): void
    {
        self::assertSame($canonical, Carve::format($source));
        self::assertSame(Carve::toHtml($source), Carve::toHtml($canonical));
    }

    public function testABalancedLinkKeepsTheReportedEscapesOnly(): void
    {
        $source = "x [{*/x/[a]](u)\\[:\n\n[a]: /u\n";

        self::assertSame(Carve::toHtml($source), Carve::toHtml(Carve::format($source)));
    }
}
