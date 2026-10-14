<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Inline\CellWrap;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `cell_wrap` stands on the `+` of its continuation line, or on the pipe a
 * second join from the same line crosses (CARVE-P12-069). A cell that took
 * text from a continuation line spans lines its siblings cross too, so it
 * carries no position (carve-js parity).
 */
class ACellWrapStandsOnItsJoinTest extends TestCase
{
    /**
     * @return array<string, array{string, list<list<string>>}>
     */
    public static function wraps(): array
    {
        return [
            'one join per cell' => ["| a | b |\n+ x | y |\n", [['+'], ['+']]],
            'two continuation lines' => ["- | a | b |\n  + x | y |\n  + p | q |\n", [['+', '+'], ['+', '+']]],
            'a second join from one line crosses its pipe' => ["| a | < |\n+ x | y |\n", [['+', '|']]],
            'a blocked caret takes the colspan join on the pipe' => ["| ^ | < |\n+ x | y |\n", [['|']]],
            'a quoted table' => ["> | a | b |\n> + x | y |\n", [['+'], ['+']]],
        ];
    }

    /**
     * @param string $source
     * @param list<list<string>> $expected
     */
    #[DataProvider('wraps')]
    public function testEachWrapStandsOnItsSourceByte(string $source, array $expected): void
    {
        $actual = [];
        foreach ($this->cells($source) as $cell) {
            $marks = [];
            foreach ($this->wrapsIn($cell) as $wrap) {
                $pos = $wrap->getPos();
                $this->assertNotNull($pos);
                $marks[] = substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset);
            }
            if ($marks !== []) {
                $actual[] = $marks;
            }
        }

        $this->assertSame($expected, $actual);
    }

    public function testBlankCellsJoinedFromAContinuationClaimNoOverlappingSpans(): void
    {
        foreach ($this->cells("| | |\n+ x | y |\n") as $cell) {
            $this->assertNull($cell->getPos());
        }
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Block\TableCell>
     */
    private function cells(string $source): array
    {
        $found = [];
        $walk = static function (Node $node) use (&$walk, &$found): void {
            foreach ($node->getChildren() as $child) {
                if ($child instanceof TableCell) {
                    $found[] = $child;
                }
                $walk($child);
            }
        };
        $walk((new CarveConverter(parser: new BlockParser(false, false, false, true)))->parse($source));

        return $found;
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Inline\CellWrap>
     */
    private function wrapsIn(Node $node): array
    {
        $found = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof CellWrap) {
                $found[] = $child;
            }
            array_push($found, ...$this->wrapsIn($child));
        }

        return $found;
    }
}
