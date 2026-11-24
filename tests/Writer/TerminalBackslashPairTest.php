<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TerminalBackslashPairTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function cases(): array
    {
        return [
            'strong backslash' => ["*\\\\*\n"],
            'strong text and backslash' => ["*a\\\\*\n"],
            'emphasis in paragraph' => ["x /a\\\\/ y\n"],
            'emphasis backslash' => ["/\\\\/\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testBarePairWithEscapedTerminalBackslashReadsBack(string $source): void
    {
        self::assertSame($source, Carve::format($source));
        self::assertEquals(Carve::parse($source), Carve::parse(Carve::format($source)));
    }
}
