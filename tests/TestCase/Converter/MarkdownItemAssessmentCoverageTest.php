<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownItemAssessmentCoverageTest extends TestCase
{
    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function cases(): array
    {
        return [
            'paragraph tab' => ["a\tb", "a\tb", ['paragraph:1']],
            'loose nested list' => ["- a\n\n  - b\n  - c\n\n- d", "- a\n\n  - b\n  - c\n\n- d", ['bullet-list:1', 'list-item:1', 'paragraph:1', 'bullet-list:3', 'list-item:3', 'list-item:4', 'list-item:6', 'paragraph:6']],
            'lazy item line' => ["- a\nlazy", "- a\n  lazy", ['bullet-list:1', 'list-item:1', 'paragraph:1', 'soft-break:1']],
            'changed ordered delimiter' => ["1. a\n2) b", "1. a\n\n2) b", ['ordered-list:1', 'list-item:1', 'ordered-list:2', 'list-item:2']],
            'quote in tight item' => ["- Item.\n  > x", "- Item.\n  > x", ['bullet-list:1', 'list-item:1', 'paragraph:1', 'block-quote:2', 'paragraph:2']],
        ];
    }

    /**
     * @param string $source
     * @param string $expectedCarve
     * @param list<string> $expectedRows
     */
    #[DataProvider('cases')]
    public function testCompleteConstructsHaveExactReports(string $source, string $expectedCarve, array $expectedRows): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
        self::assertSame($expectedCarve, $result->value);
        self::assertSame(
            $expectedRows,
            array_map(static fn ($row): string => substr($row->code, 9) . ':' . substr((string)$row->path, 5), $result->diagnostics),
        );
        self::assertSame(array_fill(0, count($expectedRows), 'preserved'), array_column($result->diagnostics, 'fidelity'));
    }
}
