<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LineBlockNoBreakSpaceTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'no-break space before a hard break' => ["::: |\n\\ \\\nb\n", "::: |\n\\ \\\nb\n:::\n"],
            'no-break space after text before a hard break' => ["::: |\na\\ \\\nb\n", "::: |\na\\ \\\nb\n:::\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testWriterKeepsTheExpectedSpellingAndReadBack(string $source, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($source);
        self::assertSame($expected, $result->source);
        self::assertSame([], $result->diagnostics);
        self::assertSame(Carve::toHtml($source), Carve::toHtml($expected));
        self::assertSame($expected, Carve::formatWithDiagnostics($expected)->source);
    }
}
