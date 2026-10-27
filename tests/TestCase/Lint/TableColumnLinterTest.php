<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\TableColumnLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TableColumnLinterTest extends TestCase
{
    public function testWholeCellSpanMarkersNeedNoAlignmentPadding(): void
    {
        $source = "|= 2-col header|<|= header|\n"
            . "|some long content|foo|bar|\n"
            . "+and even more content|||\n"
            . "|^|spans 4 cells|<|\n"
            . "|^|^|<|\n";
        self::assertSame([], (new TableColumnLinter())->lint($source));
        self::assertCount(1, (new TableColumnLinter())->lint('|<text|'));
        // `=^` stays literal with a space added, so the space is not what is missing.
        self::assertSame([], (new TableColumnLinter())->lint('|=^|'));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function provideUnpaddedRuns(): array
    {
        return [
            'tab after the kind marker' => ["|=\th |", ['1:2']],
            'tab after the alignment run' => ["|>\ta |", ['1:2']],
            'tab after kind and alignment' => ["|=<\ta |", ['1:3']],
            'glued attribute block' => ['|{.x}a |', ['1:2']],
            'tab after the attribute block' => ["|{.x}\ta |", ['1:2']],
            'block glued before a non-alignment glyph' => ['|{.x}^ a |', ['1:2']],
            'second cell, indented row' => ['  | a |{.x}b |', ['1:8']],
            'padded attribute block' => ['|{.x} a |', []],
            'space before the brace' => ['| {.x}a |', []],
            'invalid brace payload' => ['|{not attrs!}a |', []],
            'block before an alignment sigil is the retired order' => ['|{.x}< a |', []],
            'vertical sigil first' => ['|^a |', []],
            'kind then vertical sigil' => ['|=^ a |', []],
            'doubled horizontal sigil' => ['|=<< a |', []],
            'vertical only' => ['|v a |', []],
        ];
    }

    /**
     * @param string $source
     * @param list<string> $expected
     */
    #[DataProvider('provideUnpaddedRuns')]
    public function testReportsARunExactlyWhereTheMissingSpaceMakesTheCellLiteral(string $source, array $expected): void
    {
        $found = array_map(
            static fn ($warning): string => $warning->line . ':' . $warning->column,
            array_filter((new TableColumnLinter())->lint($source), static fn ($warning): bool => $warning->rule === 'table-marker-run-padding'),
        );
        self::assertSame($expected, array_values($found));
    }

    public function testReportsTheFourColumnContracts(): void
    {
        $linter = new TableColumnLinter();
        $rules = static fn (array $warnings): array => array_map(static fn ($warning): string => $warning->rule, $warnings);
        self::assertSame(['table-marker-run-padding'], $rules($linter->lint('|>text |')));
        self::assertSame(['table-marker-run-padding'], $rules($linter->lint('|=text |')));
        self::assertSame(['table-marker-run-padding'], $rules($linter->lint('|=|')));
        self::assertSame(['table-marker-run-padding'], $rules($linter->lint('|>{#x}value |')));
        self::assertSame([], $rules($linter->lint('|>{#x} value |')));
        self::assertContains('table-column-arity', $rules($linter->lint("{aligns=left}\n| a | b |")));
        self::assertContains('table-width-total', $rules($linter->lint("{widths=60,50}\n| a | b |")));
        self::assertContains('table-column-overlap', $rules($linter->lint("{aligns=left}\n|=> H |")));
    }
}
