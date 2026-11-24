<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackslashKindTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'backslash before letter after escaped punctuation' => ["\\\\&\\a\n", "\\\\&\\a\n"],
            'backslash before letter before escaped end' => ["\\a\\\\\n", "\\a\\\\\n"],
            'backslash before letter after escaped space' => ["\\\\ \\a\n", "\\\\ \\a\n"],
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
