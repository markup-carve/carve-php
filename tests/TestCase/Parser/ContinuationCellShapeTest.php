<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Inline\CellWrap;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * A cell rebuilt from `+` continuation rows keeps one `cell_wrap` marker per
 * join (CARVE-P12-069), with a text node per source line between them.
 *
 * The node SHAPE is the contract, not just whether positions are present:
 * PART 12 says a consumer written against one implementation must be able to
 * read another's output, and carve-js emits this shape.
 */
class ContinuationCellShapeTest extends TestCase
{
    /**
     * @return list<\MarkupCarve\Carve\Node\Inline\Text>
     */
    protected function cellTexts(Node $node): array
    {
        $found = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Text) {
                $found[] = $child;
            }
            $found = array_merge($found, $this->cellTexts($child));
        }

        return $found;
    }

    protected function parseWithPositions(string $carve): Node
    {
        // Positions are opt-in - the same construction `bin/carve --json` uses.
        return (new CarveConverter(parser: new BlockParser(false, false, false, true)))->parse($carve);
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Block\TableCell>
     */
    protected function cells(Node $node): array
    {
        $found = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof TableCell) {
                $found[] = $child;
            }
            $found = array_merge($found, $this->cells($child));
        }

        return $found;
    }

    public function testAContinuationCellKeepsAMarkerPerJoin(): void
    {
        $carve = "|= Feature |= Description        |\n"
            . "| Complex  | A long description |\n"
            . "+          | that continues     |\n"
            . "+          | across lines.      |\n";

        $cells = $this->cells($this->parseWithPositions($carve));
        $shape = array_map(
            static fn (Node $child): string => $child instanceof CellWrap ? 'wrap:' . $child->getLine() : $child->getType() . ':' . ($child instanceof Text ? $child->getContent() : ''),
            $cells[3]->getChildren(),
        );

        $this->assertSame(['text:A long description', 'wrap:1', 'text:that continues', 'wrap:2', 'text:across lines.'], $shape);
    }

    /**
     * Each text between the markers is a contiguous slice of its own line, so
     * it is placed and slices back to its value.
     */
    public function testEachPieceSlicesBackToItsLine(): void
    {
        $carve = "|= Feature |= Description        |\n"
            . "| Complex  | A long description |\n"
            . "+          | that continues     |\n"
            . "+          | across lines.      |\n";

        $cells = $this->cells($this->parseWithPositions($carve));
        foreach ($this->cellTexts($cells[3]) as $text) {
            $pos = $text->getPos();
            $this->assertNotNull($pos);
            $this->assertSame($text->getContent(), mb_substr($carve, $pos->startOffset, $pos->endOffset - $pos->startOffset, 'UTF-8'));
        }
    }

    public function testASingleLineCellIsUnchanged(): void
    {
        $carve = "|= A |= B |\n| one | two |\n";

        $cells = $this->cells($this->parseWithPositions($carve));

        foreach ($cells as $cell) {
            $this->assertCount(1, $this->cellTexts($cell));
        }
    }
}
