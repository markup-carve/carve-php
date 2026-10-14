<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\SourceLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TableSpanMarkerBlockedTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, int, string}>
     */
    public static function blockedCases(): iterable
    {
        yield 'first row' => ["| ^ | b |\n", 1, 3, 'There is no cell above it to extend.'];
        yield 'first column' => ["| a | b |\n| < | c |\n", 2, 3, 'There is no cell to its left to widen.'];
        yield 'covered cell' => ["| a | < |\n| ^ | b |\n", 2, 3, 'Extending the cell above would cover another cell.'];
        yield 'earlier row' => ["| a | b | c |\n| ^ | < | d |\n", 2, 7, 'The cell to its left starts in an earlier row, so widening it would cover cells already placed.'];
        yield 'continuation' => ["| a | b |\n| < | c |\n+ x | y |\n", 2, 3, 'There is no cell to its left to widen.'];
        yield 'list' => ["- item\n\n  | ^ | b |\n", 3, 5, 'There is no cell above it to extend.'];
        yield 'astral and quote' => ["😀\n> | ^ | b |\n", 2, 5, 'There is no cell above it to extend.'];
        yield 'footnote definition' => ["x[^n]\n\n[^n]: note\n\n    | ^ | b |\n", 5, 7, 'There is no cell above it to extend.'];
        yield 'captioned table' => ["| ^ | b |\n^ Table: cap\n", 1, 3, 'There is no cell above it to extend.'];
        yield 'shorter row above' => ["| a |\n| b | ^ |\n", 2, 7, 'There is no cell above it to extend.'];
        yield 'pipe in a code span' => ["| `a|b` | ^ |\n+ x | y |\n", 1, 11, 'There is no cell above it to extend.'];
    }

    public function testLocatesTheMarkerAcrossLoneCrLines(): void
    {
        foreach (["| a | b |\r| < | c |\r+ x | y |\r", "| a | b |\r| < | c |\r"] as $source) {
            $warnings = $this->findings($source);
            self::assertCount(1, $warnings);
            $start = (int)strpos($source, '<');
            self::assertSame([2, 3, $start, $start + 1], [$warnings[0]->line, $warnings[0]->column, $warnings[0]->start, $warnings[0]->end]);
        }
    }

    #[DataProvider('blockedCases')]
    public function testReportsBlockedMarker(string $source, int $line, int $column, string $reason): void
    {
        $warnings = $this->findings($source);
        self::assertCount(1, $warnings);
        $warning = $warnings[0];
        $lines = explode("\n", $source);
        $start = 0;
        for ($index = 0; $index < $line - 1; $index++) {
            $start += strlen($lines[$index]) + 1;
        }
        $start += $column - 1;
        $marker = $source[$start];
        self::assertSame([$line, $column, $start, $start + 1], [$warning->line, $warning->column, $warning->start, $warning->end]);
        self::assertSame(sprintf('The "%s" span marker cannot extend a cell. %s It renders as a cell of its own; escape it as "\\%s" to keep the character as text, or remove it.', $marker, $reason, $marker), $warning->message);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreportedCases(): iterable
    {
        yield 'resolved mixed' => ["| a | < |\n| ^ | ^ |\n"];
        yield 'resolved rowspan' => ["| a | b |\n| ^ | c |\n"];
        yield 'escaped' => ["| \\^ | b |\n"];
        yield 'gfm separator' => ["| a | b |\n|---|---|\n| ^ | c |\n"];
        yield 'fence' => ["```\n| ^ | b |\n```\n"];
    }

    #[DataProvider('unreportedCases')]
    public function testDoesNotReportResolvedOrLiteralMarkers(string $source): void
    {
        self::assertSame([], $this->findings($source));
    }

    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    private function findings(string $source): array
    {
        return array_values(array_filter(
            (new SourceLinter())->lint($source),
            static fn ($warning): bool => $warning->rule === 'table-span-marker-blocked',
        ));
    }
}
