<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A diagnostic names the line it is ON, not the line its block starts on.
 *
 * The inline parser is handed the line its STRING begins on, and that string
 * can be many lines - a folded paragraph, a line block's whole stanza. Every
 * diagnostic raised from it therefore reported that first line, and the offset
 * into the whole string as if it were a column.
 *
 * A line block used to be parsed a line at a time, so its diagnostics were
 * right by construction; they stopped being right the moment the stanza became
 * a single parse (markup-carve/carve-php#1327). The paragraph had reported its
 * own first line since long before that, and is fixed by the same counting -
 * there is one rule here, not one per block type, and this repository keeps
 * finding one rule spelled several ways.
 *
 * EVERY ROW PUTS THE FAULT ON THE THIRD LINE, because that is the position that
 * discriminates: a fault on the first line of a block reports correctly however
 * broken the counting is.
 *
 * The coordinates are resolved against the whole BLOCK, from line endings found
 * once, rather than by counting the newlines before each position. Counting is
 * quadratic in the number of faults, which is exactly the shape a document of
 * faults has, and it is wrong inside a nested parse - which is handed a
 * substring and restarts its cursor at zero.
 */
class DiagnosticsNameTheLineTheyAreOnTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function faultProvider(): array
    {
        return [
            'an undefined reference in a line block' => ["::: |\na\nb [x][undef] c\n:::\n", 3, 3],
            'an undefined reference in a paragraph' => ["a\nb\nc [x][undef] d\n", 3, 3],
            'an undefined footnote in a line block' => ["::: |\na\nb [^undef] c\n:::\n", 3, 3],
            'an undefined footnote in a paragraph' => ["a\nb\nc [^undef] d\n", 3, 3],
            // A NESTED parse is handed a substring and restarts its cursor at
            // zero, so counting inside it alone loses every line before it and
            // reports the fault too high. These rows are what force the origin
            // to accumulate rather than be recounted locally.
            'an undefined reference inside emphasis' => ["a\n/b\nc [x][undef] d/\n", 3, 3],
            'an undefined reference inside a link text' => ["a\nb\nc [t [x][undef] u](/w) d\n", 3, 6],
            'an undefined footnote inside emphasis' => ["a\n/b\nc [^undef] d/\n", 3, 3],
        ];
    }

    #[DataProvider('faultProvider')]
    public function testTheDiagnosticNamesItsOwnLine(string $source, int $line, int $column): void
    {
        $parser = new BlockParser(collectWarnings: true);
        $parser->parse($source);

        $warnings = $parser->getWarnings();
        $this->assertNotSame([], $warnings, 'the fault raised no diagnostic at all');

        $first = $warnings[0];
        $this->assertSame($line, $first->getLine());
        $this->assertSame($column, $first->getColumn());
    }

    /**
     * The control: a fault on the block's FIRST line is unmoved.
     *
     * Counting newlines before the position must add nothing when there are
     * none, so this row fails if the fix reached for the map, or for the line
     * after, instead of counting what is actually there.
     */
    public function testAFaultOnTheFirstLineIsUnmoved(): void
    {
        $parser = new BlockParser(collectWarnings: true);
        $parser->parse("a [x][undef] b\nc\n");

        $warnings = $parser->getWarnings();
        $this->assertNotSame([], $warnings);
        $this->assertSame(1, $warnings[0]->getLine());
        $this->assertSame(3, $warnings[0]->getColumn());
    }

    public function testEveryRowIsStillCovered(): void
    {
        $this->assertCount(7, self::faultProvider());
    }
}
