<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownLiteralEdgesFidelityTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function cases(): array
    {
        return [
            'trailing space' => ['hello ', 'hello ', 'markdown-paragraph'],
            'two final line endings' => ["hello\n\n", "hello\n\n", 'literal-text-verified'],
            'two CRLF line endings' => ["hello\r\n\r\n", "hello\n\n", 'literal-text-verified'],
            'plain Unicode words' => ["café 世界 123\n\n", "café 世界 123\n\n", 'literal-text-verified'],
            'Markdown hard-break spaces' => ['hello  ', 'hello', 'markdown-paragraph'],
            'tab inside a paragraph' => ["hello\tworld", "hello\tworld", 'fidelity-unverified'],
            'interior line ending' => ["hello\nworld", "hello\nworld", 'markdown-paragraph'],
            'markup' => ['*markup*', '/markup/', 'markdown-paragraph'],
            'leading indentation' => [' hello', 'hello', 'markdown-paragraph'],
        ];
    }

    #[DataProvider('cases')]
    public function testOutputMatchesItsFidelityReport(string $source, string $expected, string $code): void
    {
        $importer = new MarkdownToCarve();
        self::assertSame($expected, $importer->convert($source));
        $result = $importer->convertWithFidelityReport($source);
        self::assertSame($expected, $result->value);
        self::assertSame($code, $result->diagnostics[0]->code);
        self::assertSame($code === 'fidelity-unverified' ? 'dropped' : 'preserved', $result->diagnostics[0]->fidelity);
    }

    public function testLongPlainParagraphKeepsItsFinalSpaceAndBlankLines(): void
    {
        $source = str_repeat('hello ', 10000) . "end \n\n";
        self::assertSame($source, (new MarkdownToCarve())->convert($source));
    }
}
