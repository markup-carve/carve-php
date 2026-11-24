<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LineBlockOpenerEscapesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'quote mark in a line block' => ["::: |\n> a\n", "::: |\n> a\n:::\n"],
            'comment line in a line block' => ["::: |\n%%\n", "::: |\n%%\n:::\n"],
            'list mark in a line block' => ["::: |\n- a\n", "::: |\n- a\n:::\n"],
            'heading mark in a line block' => ["::: |\n# a\n", "::: |\n# a\n:::\n"],
            'fence run in a line block' => ["::: |\n```\n", "::: |\n```\n:::\n"],
            'comment fence still escaped' => ["::: |\n\\%%%\n", "::: |\n\\%%%\n:::\n"],
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
