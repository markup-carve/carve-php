<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use MarkupCarve\Carve\Lint\LintWarning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ruling A on markup-carve/carve#5953: every refused marker-line `+` on a footnote definition is reported, a losing
 * duplicate's included, and a following definition is the note's sibling.
 */
final class ARefusedPlusOnALosingNoteDefinitionIsReportedTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function provider(): array
    {
        return [
            'row 981' => ["[^n]: +\n[^n]: +\n\n  para\n\nsee[^n]\n", ['1:7', '2:7']],
            'row 980' => ["[^a]: x\n[^a]: +\n flush\n\nsee[^a]\n", ['2:7']],
            'quoted row 981' => ["> [^n]: +\n> [^n]: +\n>\n>   para\n\nsee[^n]\n", ['1:9', '2:9']],
            'losing plus whose follower an earlier plus claims' => ["[^a]: +\n[^a]: +\n- b\n\n[^a]\n", ['2:7']],
            'live definition after a refused one' => ["[^n]: +\n[^m]: d1\n[^k]: +\n  para\n\nsee[^n][^m][^k]\n", ['1:7', '3:7']],
            'live definition before a losing one' => ["[^m]: +\n[^n]: +\n[^n]: +\n  para\n\nsee[^m][^n]\n", ['1:7', '2:7', '3:7']],
            'live definition before a blank' => ["see[^a][^b]\n[^b]: +\n[^a]: +\n\n[^a]: +\nflush\n", ['2:7', '3:7']],
            'losing plus before a live definition' => ["[^a]: x\n[^a]: +\n[^b]: y\n\nsee[^a][^b]\n", ['2:7']],
            'losing plus before a flush paragraph' => ["[^m]: d1\n\n[^m]: +\nflush\n\nsee[^m]\n", []],
        ];
    }

    /**
     * @param string $source
     * @param list<string> $expected
     */
    #[DataProvider('provider')]
    public function testTheRefusedPlusIsReported(string $source, array $expected): void
    {
        $found = array_map(
            static fn (LintWarning $warning): string => $warning->line . ':' . $warning->column,
            array_values(array_filter(
                (new DocumentLinter())->lint($source),
                static fn (LintWarning $warning): bool => $warning->rule === 'continuation-marker-refused',
            )),
        );
        sort($found);

        $this->assertSame($expected, $found);
    }
}
